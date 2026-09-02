<?php

namespace App\Merge;

use Illuminate\Support\Facades\Http;

/**
 * Lets a set link a Google Sheet as its merge file: a docs.google.com
 * spreadsheet URL is downloaded through the sheet's CSV export endpoint
 * into a local cache file, which then flows through the normal parser.
 *
 * The sheet must be shared as "Anyone with the link can view" — the export
 * endpoint needs no credentials for link-shared sheets, and a private one
 * answers with a login page we turn into a clear error.
 */
final class GoogleSheetFetcher
{
    private const URL_PATTERN = '#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)#';

    public static function isSheetUrl(string $ref): bool
    {
        return (bool) preg_match(self::URL_PATTERN, $ref);
    }

    public static function isUrl(string $ref): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref);
    }

    /**
     * A local filesystem path for any merge reference: plain paths pass
     * through; a Google Sheet URL is downloaded (or served from a cache
     * copy no older than $maxAgeSeconds — PHP_INT_MAX means "any cached
     * copy is fine", the app's cache-first mode).
     *
     * With a $cacheDir (the set's .foundry/cache), the copy syncs with the
     * set and each fetch records a meta file ({source, sha256, fetchedAt})
     * so change detection is a hash compare, not a re-parse.
     */
    public function localize(string $mergeRef, int $maxAgeSeconds = 0, ?string $cacheDir = null): string
    {
        if (! self::isUrl($mergeRef)) {
            return $mergeRef;
        }

        if (! preg_match(self::URL_PATTERN, $mergeRef, $m)) {
            throw new MergeParseException(
                "Unsupported merge URL: {$mergeRef} — link a Google Sheet (docs.google.com/spreadsheets/…) or use a local file.",
            );
        }

        [$id, $gid] = self::sheetKey($mergeRef);

        $cached = ($cacheDir !== null ? rtrim($cacheDir, '/') : storage_path('app/merge-cache'))
            ."/sheet-{$id}-{$gid}.csv";

        if ($maxAgeSeconds > 0 && is_file($cached) && time() - (int) filemtime($cached) <= $maxAgeSeconds) {
            return $cached;
        }

        $response = Http::timeout(30)->get(
            "https://docs.google.com/spreadsheets/d/{$id}/export",
            ['format' => 'csv', 'gid' => $gid],
        );

        if ($response->failed()) {
            throw new MergeParseException(
                "Could not download the linked Google Sheet (HTTP {$response->status()}). "
                .'Check the link, and that the sheet is shared as "Anyone with the link can view".',
            );
        }

        $body = $response->body();

        if (str_starts_with(ltrim($body), '<') || str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            throw new MergeParseException(
                'The linked Google Sheet is not publicly readable — in Google Sheets choose '
                .'Share → General access → "Anyone with the link" (Viewer), then try again.',
            );
        }

        $dir = dirname($cached);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Atomic replace so a failed download never truncates a good copy.
        $tmp = $cached.'.tmp';
        if (file_put_contents($tmp, $body) === false || ! rename($tmp, $cached)) {
            throw new MergeParseException("Could not write the downloaded sheet to {$cached}.");
        }

        @file_put_contents(substr($cached, 0, -4).'.meta.json', json_encode([
            'source' => $mergeRef,
            'sha256' => hash('sha256', $body),
            'fetchedAt' => time(),
        ], JSON_UNESCAPED_SLASHES)."\n");

        return $cached;
    }

    /**
     * The cached copy's meta (source, sha256, fetchedAt), or null when the
     * sheet hasn't been downloaded to this cache yet.
     *
     * @return array{source:string,sha256:string,fetchedAt:int}|null
     */
    public function meta(string $mergeRef, ?string $cacheDir = null): ?array
    {
        if (! preg_match(self::URL_PATTERN, $mergeRef)) {
            return null;
        }

        [$id, $gid] = self::sheetKey($mergeRef);

        $path = ($cacheDir !== null ? rtrim($cacheDir, '/') : storage_path('app/merge-cache'))
            ."/sheet-{$id}-{$gid}.meta.json";

        $meta = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($meta) ? $meta : null;
    }

    /**
     * @return array{0:string,1:string} [spreadsheet id, tab gid]
     */
    private static function sheetKey(string $mergeRef): array
    {
        preg_match(self::URL_PATTERN, $mergeRef, $m);

        return [$m[1], preg_match('/[#?&]gid=(\d+)/', $mergeRef, $g) ? $g[1] : '0'];
    }
}
