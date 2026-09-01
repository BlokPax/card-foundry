<?php

namespace App\InDesign;

use Illuminate\Support\Facades\Http;
use Throwable;

final class BridgeClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
    ) {}

    private function url(string $path): string
    {
        return rtrim($this->baseUrl ?? config('wotf.bridge_url'), '/').$path;
    }

    /**
     * Execute raw UXP JavaScript inside InDesign via the bridge.
     */
    public function execute(string $code, ?int $timeoutSeconds = null): BridgeResult
    {
        try {
            $response = Http::timeout($timeoutSeconds ?? (int) config('wotf.card_timeout', 180))
                ->connectTimeout(5)
                ->post($this->url('/execute'), ['code' => $code]);
        } catch (Throwable $e) {
            return BridgeResult::failure('Bridge unreachable: '.$e->getMessage());
        }

        $body = $response->json();

        if ($response->failed()) {
            $error = is_array($body) ? ($body['error'] ?? $response->body()) : $response->body();

            return BridgeResult::failure((string) ($error ?: "Bridge returned HTTP {$response->status()}"));
        }

        if (is_array($body) && array_key_exists('error', $body) && $body['error']) {
            return BridgeResult::failure((string) $body['error']);
        }

        return BridgeResult::success(is_array($body) ? ($body['result'] ?? $body) : $body);
    }

    /**
     * Cheap round-trip through the bridge and the UXP plugin into InDesign.
     */
    public function ping(int $timeoutSeconds = 10): BridgeResult
    {
        return $this->execute('return "pong:" + app.name + " " + app.version;', $timeoutSeconds);
    }

    /**
     * True when the bridge HTTP server itself is accepting connections
     * (InDesign may still be disconnected — use ping() for that).
     */
    public function isUp(): bool
    {
        $parts = parse_url($this->url(''));
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3000;

        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 1);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
