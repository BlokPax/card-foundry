<?php

namespace App\InDesign;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Owns the lifecycle of the indesign-uxp-server bridge (node bridge/server.js):
 * cloning the repo, installing dependencies, and starting/stopping the
 * process so the user never has to touch a terminal.
 */
final class BridgeSupervisor
{
    public function __construct(
        private readonly BridgeClient $client = new BridgeClient,
    ) {}

    public function bridgePath(): string
    {
        return config('wotf.bridge_path') ?: resource_path('bridge');
    }

    public function serverScript(): string
    {
        // The esbuild bundle carries express/uuid/ws inside it, so packaged
        // apps (whose nested node_modules get stripped) need nothing else.
        $bundle = $this->bridgePath().'/bridge/server.bundle.js';

        return is_file($bundle) ? $bundle : $this->bridgePath().'/bridge/server.js';
    }

    /**
     * The UXP plugin folder the user loads into InDesign (one-time,
     * via Adobe UXP Developer Tool).
     */
    public function pluginPath(): string
    {
        return $this->bridgePath().'/plugin';
    }

    /**
     * The packaged .ccx of the same plugin — installable without the
     * UXP Developer Tool.
     */
    public function pluginPackage(): string
    {
        return $this->pluginPath().'/com.ads.indesign-bridge_ID.ccx';
    }

    /**
     * Whether the plugin is installed in InDesign, per UPIA's listing.
     * Null when UPIA isn't available to ask.
     */
    public function pluginInstalled(): ?bool
    {
        $upia = (string) config('wotf.upia_path');

        if (! is_file($upia)) {
            return null;
        }

        $result = Process::timeout(30)->run([$upia, '--list', 'all']);

        return str_contains($result->output(), 'com.ads.indesign-bridge');
    }

    /**
     * The InDesign application bundle, if installed.
     */
    public function indesignAppPath(): ?string
    {
        foreach (['/Applications/Adobe InDesign*/Adobe InDesign*.app', '/Applications/Adobe InDesign*.app'] as $pattern) {
            $hits = glob($pattern) ?: [];
            if ($hits !== []) {
                return end($hits); // newest version sorts last
            }
        }

        return null;
    }

    public function indesignRunning(): bool
    {
        return Process::timeout(10)->run(['pgrep', '-f', 'Adobe InDesign'])->successful();
    }

    public function openInDesign(): bool
    {
        $app = $this->indesignAppPath();
        $open = (string) config('wotf.open_binary');

        if ($app === null || ! is_file($open)) {
            return false;
        }

        return Process::timeout(30)->run([$open, $app])->successful();
    }

    /**
     * Install the bundled .ccx: UPIA when Creative Cloud ships it,
     * otherwise hand the file to Creative Cloud Desktop via the opener.
     *
     * @return array{0: bool, 1: string} [ok, message]
     */
    public function installPlugin(): array
    {
        $ccx = $this->pluginPackage();

        if (! is_file($ccx)) {
            return [false, "Plugin package not found: {$ccx}"];
        }

        $upia = (string) config('wotf.upia_path');

        if (is_file($upia)) {
            $result = Process::timeout(120)->run([$upia, '--install', $ccx]);

            return $result->successful()
                ? [true, 'Plugin installed. Open InDesign, then enable it under Window → Plugins → InDesign Bridge.']
                : [false, 'Plugin install failed: '.trim($result->errorOutput() ?: $result->output())];
        }

        $open = (string) config('wotf.open_binary');

        if (is_file($open)) {
            Process::timeout(30)->run([$open, $ccx]);

            return [true, 'Handed the plugin to Creative Cloud — confirm the install prompt, then open InDesign.'];
        }

        return [false, "Couldn't find Creative Cloud's installer. Double-click the plugin package yourself: {$ccx}"];
    }

    public function pidFile(): string
    {
        return storage_path('app/bridge.pid');
    }

    public function logFile(): string
    {
        return storage_path('logs/bridge.log');
    }

    public function isInstalled(): bool
    {
        $script = $this->serverScript();

        // The bundle is self-contained; the raw server.js needs its deps.
        return str_ends_with($script, '.bundle.js')
            ? is_file($script)
            : (is_file($script) && is_dir(dirname($script).'/node_modules'));
    }

    /**
     * The bridge ships vendored with the app (see resources/bridge/VERSION.md);
     * this only fetches its npm dependencies when node_modules is absent.
     */
    public function install(?callable $output = null): void
    {
        $emit = $output ?? fn (string $line) => null;
        $bridgeDir = dirname($this->serverScript());

        if (! is_file($this->serverScript())) {
            throw new RuntimeException(
                "Bridge not found at {$this->serverScript()} — the vendored copy is missing (or WOTF_BRIDGE_PATH points somewhere stale).",
            );
        }

        if (is_dir($bridgeDir.'/node_modules')) {
            $emit('Bridge dependencies already present.');

            return;
        }

        $emit('Installing bridge npm dependencies…');
        $result = Process::timeout(600)->path($bridgeDir)->run(['npm', 'install', '--no-audit', '--no-fund']);
        if (! $result->successful()) {
            throw new RuntimeException('npm install failed: '.$result->errorOutput());
        }

        $emit('Bridge ready.');
    }

    public function isRunning(): bool
    {
        return $this->client->isUp();
    }

    /**
     * The runtime to execute bridge/server.js with, plus the env it needs.
     * Prefers the app's own Electron binary run as Node so distributed
     * builds require no local Node install.
     *
     * @return array{0: string, 1: array<string,string>}
     */
    public function nodeCommand(): array
    {
        $configured = (string) config('wotf.node_binary', 'node');

        if ($configured !== 'node') {
            return [$configured, []];
        }

        foreach ($this->electronCandidates() as $electron) {
            if ($electron !== null && is_file($electron)) {
                return [$electron, ['ELECTRON_RUN_AS_NODE' => '1']];
            }
        }

        // No Electron found (plain CLI on a dev box) — probe the usual
        // installs, since a packaged GUI app's PATH won't include them.
        foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node'] as $probe) {
            if (is_file($probe)) {
                return [$probe, []];
            }
        }

        return ['node', []];
    }

    /**
     * @return list<?string>
     */
    private function electronCandidates(): array
    {
        $candidates = [config('wotf.electron_binary')];

        // Packaged app: the PHP binary lives inside the .app bundle — the
        // Electron executable is its sibling under Contents/MacOS.
        $phpBin = (string) env('NATIVEPHP_PHP_BINARY_PATH');
        if ($phpBin !== '' && ($pos = strpos($phpBin, '.app/Contents/')) !== false) {
            foreach (glob(substr($phpBin, 0, $pos).'.app/Contents/MacOS/*') ?: [] as $binary) {
                $candidates[] = $binary;
            }
        }

        // Dev: the Electron that native:serve runs.
        $candidates[] = base_path('vendor/nativephp/electron/resources/js/node_modules/electron/dist/Electron.app/Contents/MacOS/Electron');

        return $candidates;
    }

    /**
     * Start the bridge as a detached background process. Idempotent.
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        if (! $this->isInstalled()) {
            $this->install();
        }

        [$node, $nodeEnv] = $this->nodeCommand();

        // Give the bridge at least our per-card budget before it times out a script.
        $timeoutMs = ((int) config('wotf.card_timeout', 180)) * 1000;
        $env = array_merge($nodeEnv, ['BRIDGE_TIMEOUT_MS' => (string) $timeoutMs]);

        if (config('nativephp-internal.running')) {
            // A wedged persistent child under this alias would shadow the new
            // spawn — clear it first.
            try {
                \Native\Laravel\Facades\ChildProcess::stop('wotf-bridge');
            } catch (Throwable) {
                // nothing registered — fine
            }

            // Let Electron own the bridge (dies with the app), but run it
            // through a shell so its output lands in bridge.log — otherwise
            // startup failures vanish into the Electron runtime.
            $envPrefix = implode(' ', array_map(
                fn ($key, $value) => $key.'='.escapeshellarg($value),
                array_keys($env),
                $env,
            ));

            \Native\Laravel\Facades\ChildProcess::start(
                ['/bin/sh', '-c', "{$envPrefix} exec ".escapeshellarg($node).' '.escapeshellarg($this->serverScript())
                    .' >> '.escapeshellarg($this->logFile()).' 2>&1'],
                'wotf-bridge',
                cwd: dirname($this->serverScript()),
                persistent: true,
            );
        } else {
            $script = escapeshellarg($this->serverScript());
            $cwd = escapeshellarg(dirname($this->serverScript()));
            $log = escapeshellarg($this->logFile());
            $envPrefix = implode(' ', array_map(
                fn ($key, $value) => $key.'='.escapeshellarg($value),
                array_keys($env),
                $env,
            ));

            // Detach fully — including stdin, or the child keeps the calling
            // request/terminal alive — so the bridge outlives this process.
            $command = "cd {$cwd} && {$envPrefix} nohup ".escapeshellarg($node)
                ." {$script} >> {$log} 2>&1 < /dev/null & echo $!";
            $pid = trim((string) shell_exec($command));

            if ($pid !== '' && ctype_digit($pid)) {
                file_put_contents($this->pidFile(), $pid);
            }
        }

        $this->waitUntilUp();
    }

    public function stop(): void
    {
        if (config('nativephp-internal.running')) {
            try {
                \Native\Laravel\Facades\ChildProcess::stop('wotf-bridge');
            } catch (\Throwable) {
                // fall through to pid/port cleanup
            }
        }

        $pidFile = $this->pidFile();

        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0) {
                @posix_kill($pid, SIGTERM);
            }
            @unlink($pidFile);
        }

        // The recorded pid can be stale or a shell wrapper — fall back to
        // whatever is actually listening on the bridge port.
        if ($this->isRunning()) {
            $port = (int) (parse_url((string) config('wotf.bridge_url'), PHP_URL_PORT) ?: 3000);
            shell_exec('lsof -ti tcp:'.$port.' | xargs kill 2>/dev/null');
        }
    }

    /**
     * Make sure the bridge HTTP server is up, starting it if needed.
     */
    public function ensureRunning(): void
    {
        if (! $this->isRunning()) {
            $this->start();
        }
    }

    private function waitUntilUp(int $seconds = 10): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($this->client->isUp()) {
                return;
            }
            usleep(250_000);
        }

        throw new RuntimeException(
            "Bridge did not come up on {$this->bridgeUrlForDisplay()} within {$seconds}s."
                .$this->logTail(),
        );
    }

    private function bridgeUrlForDisplay(): string
    {
        return (string) config('wotf.bridge_url');
    }

    /**
     * The last few bridge.log lines, for actionable failure messages.
     */
    private function logTail(int $lines = 4): string
    {
        if (! is_file($this->logFile())) {
            return " No bridge.log was written — the process likely never spawned ({$this->logFile()}).";
        }

        $all = file($this->logFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail = implode(' | ', array_slice($all, -$lines));

        return $tail === '' ? " bridge.log is empty ({$this->logFile()})." : " Last log lines: {$tail}";
    }
}
