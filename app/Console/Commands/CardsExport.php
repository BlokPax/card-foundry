<?php

namespace App\Console\Commands;

use App\Export\ExportPlanner;
use App\Export\ExportRunner;
use App\InDesign\BridgeClient;
use App\InDesign\BridgeSupervisor;
use App\Merge\LintSeverity;
use App\Merge\MergeFileParser;
use App\Merge\MergeLinter;
use App\Models\Run;
use App\Models\RunItem;
use Illuminate\Console\Command;
use Throwable;

class CardsExport extends Command
{
    protected $signature = 'cards:export
        {--manifest=    : Path to a set\'s foundry.json — provides defaults for everything below}
        {--profile=     : Named profile from the manifest (e.g. print, preview)}
        {--merge=       : Merge file (.csv/.xlsx) or a Google Sheet URL shared "Anyone with the link"}
        {--art=*        : Artwork directory (repeatable; searched in order, first match wins)}
        {--white=*      : White-layer artwork directory (repeatable; hunted like --art)}
        {--template=    : InDesign template (default: the kit\'s master template)}
        {--dest=        : Output directory for exported cards}
        {--kit=         : The build-kit folder (wotfLib.jsxinc, Links/, builds/)}
        {--cards=       : Comma-separated card refs (@Image, Card Code, or Name)}
        {--all          : Export every card in the merge file}
        {--treatment=*  : Treatment (repeatable — each exports into its own subfolder)}
        {--format=      : Export format: pdf or png}
        {--serialize    : Build serialized copies — one export per numbered copy (edition sizes from the merge file)}
        {--no-serialize : Ignore the manifest\'s serialize setting for this run}
        {--combine      : Also combine the run\'s PDFs into one document per treatment}
        {--bleed=       : PDF bleed: a size in mm, or "doc" for the document\'s own bleed}
        {--crop-marks   : Add crop (cut) marks to PDF exports}
        {--registration-marks : Add registration marks to PDF exports}
        {--production   : Keep the white-ink plate (default builds are NO-WHITE proofs)}
        {--show-plan    : Print the build pipeline (stages, order, options) and exit}
        {--dry-run      : Parse and lint only; do not touch InDesign}
        {--force        : Proceed even if the linter reports errors}
        {--run-id=      : Resume/execute an already-planned run (used by the GUI)}';

    protected $description = 'Build Wonders of the First cards through InDesign — one card at a time, headlessly.';

    public function handle(
        MergeFileParser $parser,
        MergeLinter $linter,
        ExportPlanner $planner,
        ExportRunner $runner,
        BridgeClient $bridge,
        BridgeSupervisor $supervisor,
    ): int {
        if ($this->option('show-plan')) {
            $this->printPlan();

            return self::SUCCESS;
        }

        // GUI-planned runs already carry validated inputs.
        if ($runId = $this->option('run-id')) {
            $run = Run::find($runId);

            if ($run === null) {
                $this->error("Run {$runId} not found.");

                return self::FAILURE;
            }

            return $this->executeRun($run, $runner, $bridge, $supervisor);
        }

        try {
            $config = $this->resolveConfig();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dest = rtrim((string) $this->option('dest'), '/');

        foreach ([
            '--dest is required.' => $dest !== '',
            "Destination directory does not exist: {$dest}" => $dest === '' || is_dir($dest),
            'A merge file is required (--merge or a manifest).' => $config->mergePath !== '',
            'At least one art directory is required (--art or a manifest).' => $config->artDirs !== [],
            'Not a build kit (no '.config('wotf.kit_library')."): {$config->kitPath}" => is_file($config->kitPath.'/'.config('wotf.kit_library')),
            "Template not found: {$config->templatePath}" => is_file($config->templatePath),
        ] as $message => $ok) {
            if (! $ok) {
                $this->error($message);

                return self::FAILURE;
            }
        }

        foreach ($config->artDirs as $dir) {
            if (! is_dir($dir)) {
                $this->error("Art directory does not exist: {$dir}");

                return self::FAILURE;
            }
        }

        try {
            // A linked Google Sheet merge downloads to a local CSV first —
            // into the set's .foundry/cache when a manifest names the set.
            if (\App\Merge\GoogleSheetFetcher::isUrl($config->mergePath)) {
                $this->components->info('Downloading the linked Google Sheet…');
            }

            $cacheDir = ($manifestOption = $this->option('manifest'))
                ? \App\Manifest\FoundryLayout::foundryDirFor(\App\Manifest\FoundryLayout::discover($manifestOption) ?? $manifestOption).'/cache'
                : null;

            $merge = $parser->parse((new \App\Merge\GoogleSheetFetcher)->localize($config->mergePath, cacheDir: $cacheDir));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($config->profile !== null) {
            $this->components->info("Set \"{$config->set}\" — profile \"{$config->profile}\": ".implode(' + ', $config->treatments).', '.strtoupper($config->format).($config->proof ? ', NO-WHITE proof' : ', production'));
        }

        $this->components->info("Parsed \"{$merge->setName()}\" — {$merge->count()} cards, ".count($merge->headers).' columns.');

        $issues = $linter->lint($merge, $config->artDirs);
        $this->printIssues($issues);

        if ($this->option('dry-run')) {
            return $linter->hasErrors($issues) ? self::FAILURE : self::SUCCESS;
        }

        if (! $this->option('all') && ! $this->option('cards')) {
            $this->error('Choose cards with --cards=REF[,REF…] or export everything with --all.');

            return self::FAILURE;
        }

        $refs = $this->option('all')
            ? []
            : array_values(array_filter(array_map(trim(...), explode(',', (string) $this->option('cards')))));

        // Only errors touching the cards being built (or the file itself) block.
        $selectedRows = null;
        if ($refs !== []) {
            $selectedRows = [];
            foreach ($refs as $ref) {
                $record = $merge->findByImageRef($ref);
                if ($record === null) {
                    $this->error("Card \"{$ref}\" not found in merge file (match by @Image, Card Code, or Name).");

                    return self::FAILURE;
                }
                $selectedRows[] = $record->row;
            }
        }

        $blocking = array_filter($issues, fn ($issue) => $issue->severity === LintSeverity::Error
            && ($selectedRows === null || $issue->row === null || in_array($issue->row, $selectedRows, true)));

        if ($blocking !== [] && ! $this->option('force')) {
            $this->error(count($blocking).' lint error(s) affect this export — fix them or re-run with --force.');

            return self::FAILURE;
        }

        try {
            $run = $planner->plan($merge, $config, $dest, $refs, $this->option('manifest') ?: null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->executeRun($run, $runner, $bridge, $supervisor);
    }

    private function resolveConfig(): \App\Manifest\BuildConfig
    {
        $artDirs = array_values(array_map(fn ($dir) => rtrim($dir, '/'), (array) $this->option('art')));

        $treatments = array_values(array_filter((array) $this->option('treatment')));

        $overrides = array_filter([
            'merge' => $this->option('merge'),
            'template' => $this->option('template'),
            'kit' => $this->option('kit') ? rtrim($this->option('kit'), '/') : null,
            'treatment' => $treatments ?: null,
            'format' => $this->option('format'),
        ]);

        if ($artDirs !== []) {
            $overrides['artDirs'] = $artDirs;
        }

        $whiteDirs = array_values(array_map(fn ($dir) => rtrim($dir, '/'), (array) $this->option('white')));
        if ($whiteDirs !== []) {
            $overrides['whiteDirs'] = $whiteDirs;
        }

        if ($this->option('production')) {
            $overrides['proof'] = false;
        }

        if ($this->option('no-serialize')) {
            $overrides['serialize'] = false;
        } elseif ($this->option('serialize')) {
            $overrides['serialize'] = true;
        }

        $exportOverrides = $this->exportOverrides();
        if ($exportOverrides !== []) {
            $overrides['export'] = $exportOverrides;
        }

        if ($manifestPath = $this->option('manifest')) {
            return (new \App\Manifest\ManifestLoader)
                ->load($manifestPath)
                ->effective($this->option('profile') ?: null, $overrides);
        }

        if ($this->option('profile')) {
            throw new \InvalidArgumentException('--profile requires --manifest.');
        }

        $kit = rtrim((string) $this->option('kit'), '/');

        return new \App\Manifest\BuildConfig(
            set: '',
            profile: null,
            mergePath: (string) $this->option('merge'),
            kitPath: $kit,
            templatePath: $this->option('template') ?: ($kit !== '' ? $kit.'/'.config('wotf.kit_template') : ''),
            artDirs: $artDirs,
            treatments: $treatments ?: ['Base Paper'],
            proof: ! $this->option('production'),
            format: strtolower($this->option('format') ?: 'pdf'),
            exportOptions: $exportOverrides,
            pipeline: null,
            whiteDirs: $whiteDirs,
            serialize: (bool) $this->option('serialize') && ! $this->option('no-serialize'),
        );
    }

    /**
     * The export-option flags shared by both config paths.
     *
     * @return array<string,mixed>
     */
    private function exportOverrides(): array
    {
        $export = [];

        if ($this->option('combine')) {
            $export['combine'] = true;
        }
        if ($this->option('crop-marks')) {
            $export['cropMarks'] = true;
        }
        if ($this->option('registration-marks')) {
            $export['registrationMarks'] = true;
        }
        if (($bleed = $this->option('bleed')) !== null && $bleed !== '') {
            $export['bleed'] = is_numeric($bleed) ? +$bleed : true;
        }

        return $export;
    }

    private function executeRun(Run $run, ExportRunner $runner, BridgeClient $bridge, BridgeSupervisor $supervisor): int
    {
        if (! $this->connectToInDesign($bridge, $supervisor, $run)) {
            return self::FAILURE;
        }

        $mode = $run->proof ? 'NO-WHITE proof' : 'production';
        $this->components->info("Building {$run->total} card(s) — {$run->treatment}, {$mode} → {$run->output_dir}");

        $runner->run($run, function (RunItem $item, ?string $error) {
            if ($error === null) {
                $this->components->twoColumnDetail("  {$item->name} <fg=gray>({$item->image_ref})</>", '<fg=green>✓ done</>');
            } else {
                $this->components->twoColumnDetail("  {$item->name} <fg=gray>({$item->image_ref})</>", '<fg=red>✗ failed</>');
                $this->line("    <fg=red>{$error}</>");
            }
        });

        $run->refresh();
        $this->newLine();
        $this->components->info("Finished: {$run->completed} succeeded, {$run->failed} failed.");

        return $run->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function connectToInDesign(BridgeClient $bridge, BridgeSupervisor $supervisor, Run $run): bool
    {
        $ping = $bridge->ping();

        if (! $ping->ok) {
            $this->line('Bridge not responding — starting it…');

            try {
                $supervisor->ensureRunning();
            } catch (Throwable $e) {
                $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $this->error($e->getMessage());

                return false;
            }

            $ping = $bridge->ping();
        }

        if (! $ping->ok) {
            $message = 'Bridge is up but InDesign is not connected. Open InDesign and enable the plugin under '
                .'Plugins → InDesign Bridge, then try again. ('.$ping->error.')';
            $run->update(['status' => 'failed', 'error' => $message]);
            $this->error($message);

            return false;
        }

        $this->components->info("InDesign connected: {$ping->data}");

        return true;
    }

    private function printPlan(): void
    {
        $this->components->info('Per-card build pipeline (config/wotf.php):');
        $this->components->twoColumnDetail('<fg=gray>—</>', '<fg=gray>copy template → select CSV → show treatment layer → merge</>');

        foreach (config('wotf.pipeline') as $i => $stage) {
            $opts = array_filter($stage['opts'] ?? []);
            $this->components->twoColumnDetail(
                sprintf('  %2d. %s <fg=gray>(%s)</>', $i + 1, $stage['label'] ?? $stage['step'], $stage['step']),
                $opts ? '<fg=gray>'.json_encode($opts).'</>' : '',
            );
        }

        $this->components->twoColumnDetail('  ++. White plate (whitePlate)', '<fg=gray>hidden for proofs, kept for --production</>');
        $this->components->twoColumnDetail('<fg=gray>—</>', '<fg=gray>export PDF → close without saving</>');
        $this->components->info('Treatments: '.implode(', ', array_keys(config('wotf.treatments'))));
    }

    private function printIssues(array $issues): void
    {
        if ($issues === []) {
            $this->components->info('Lint: clean — no issues.');

            return;
        }

        $errors = array_filter($issues, fn ($i) => $i->severity === LintSeverity::Error);
        $warnings = array_filter($issues, fn ($i) => $i->severity === LintSeverity::Warning);

        $this->components->warn('Lint: '.count($errors).' error(s), '.count($warnings).' warning(s).');

        foreach ($issues as $issue) {
            $color = $issue->severity === LintSeverity::Error ? 'red' : 'yellow';
            $where = $issue->row !== null ? "row {$issue->row}" : 'file';
            $this->line("  <fg={$color}>[{$issue->severity->value}]</> <fg=gray>{$where}</> {$issue->message}");
        }
    }
}
