<?php

namespace App\Merge;

use Illuminate\Support\Facades\Http;

/**
 * Lets a set link a Google Sheet as its merge file, downloaded into a
 * local cache file that flows through the normal parser.
 *
 * Two paths, by how the sheet is shared:
 *  - Signed in (Preferences → Google account): the Sheets API, as that
 *    person. The real tab list is fetched, so the URL's gid picks a tab
 *    by identity and a URL without a gid reads the FIRST tab whatever
 *    its id (never assume gid 0 exists — it dies with the sheet's
 *    original first tab).
 *  - Anonymous: the CSV export endpoint, which works only for sheets
 *    shared "Anyone with the link can view"; a private one answers with
 *    a login page we turn into a clear error.
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

        [$id, $gid, $explicitGid] = self::sheetKey($mergeRef);

        $cached = ($cacheDir !== null ? rtrim($cacheDir, '/') : storage_path('app/merge-cache'))
            ."/sheet-{$id}-{$gid}.csv";

        if ($maxAgeSeconds > 0 && is_file($cached) && time() - (int) filemtime($cached) <= $maxAgeSeconds) {
            return $cached;
        }

        // Signed in (Preferences → Google account): the download acts as
        // that person, so the sheet's own share list controls access.
        try {
            $token = (new \App\Google\GoogleAuth)->accessToken();
        } catch (\Throwable $e) {
            throw new MergeParseException($e->getMessage(), previous: $e);
        }

        $body = $token !== null
            ? $this->fetchViaApi($id, $gid, $explicitGid, $token)
            : $this->fetchPublicCsv($id, $gid);

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
     * @return array{0:string,1:string,2:bool} [spreadsheet id, tab gid, gid was explicit in the URL]
     */
    private static function sheetKey(string $mergeRef): array
    {
        preg_match(self::URL_PATTERN, $mergeRef, $m);

        $explicit = (bool) preg_match('/[#?&]gid=(\d+)/', $mergeRef, $g);

        return [$m[1], $explicit ? $g[1] : '0', $explicit];
    }

    /**
     * The signed-in path: Sheets API. Resolve the real tab list, pick the
     * tab (explicit gid by identity; otherwise the first tab), pull its
     * values, and render them as CSV for the normal parser.
     */
    private function fetchViaApi(string $id, string $gid, bool $explicitGid, string $token): string
    {
        $meta = Http::timeout(30)->withToken($token)->get(
            "https://sheets.googleapis.com/v4/spreadsheets/{$id}",
            ['fields' => 'sheets.properties'],
        );
        $this->guardApi($meta);

        $tabs = [];
        foreach ((array) $meta->json('sheets', []) as $sheet) {
            $properties = $sheet['properties'] ?? [];
            if (isset($properties['title'])) {
                $tabs[(string) ($properties['sheetId'] ?? '')] = (string) $properties['title'];
            }
        }

        if ($tabs === []) {
            throw new MergeParseException('The linked Google Sheet has no tabs.');
        }

        $title = $tabs[$gid] ?? null;

        // gid=0 is stamped on every fresh sheet's URL and outlives the
        // original first tab it named — treat it like "no gid" when absent.
        if ($title === null && $explicitGid && $gid !== '0') {
            throw new MergeParseException(
                "The link names tab gid={$gid}, but this sheet doesn't have it. Its tabs: "
                .implode(', ', array_map(fn ($tabGid, $name) => "\"{$name}\" (gid={$tabGid})", array_keys($tabs), $tabs))
                .'. Link the sheet without a gid to use the first tab.',
            );
        }

        $title ??= reset($tabs);

        // A1 range naming the whole tab; single quotes in titles double.
        $range = "'".str_replace("'", "''", $title)."'";

        $values = Http::timeout(30)->withToken($token)->get(
            "https://sheets.googleapis.com/v4/spreadsheets/{$id}/values/".rawurlencode($range),
            ['valueRenderOption' => 'FORMATTED_VALUE'],
        );
        $this->guardApi($values);

        $rows = (array) $values->json('values', []);

        if ($rows === []) {
            throw new MergeParseException("The tab \"{$title}\" of the linked Google Sheet is empty.");
        }

        return $this->toCsv($rows);
    }

    /**
     * The API omits trailing empty cells — pad every row to the header's
     * width so the CSV stays rectangular.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $width = count($rows[0]);

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            $row = array_map(fn ($cell) => is_scalar($cell) ? (string) $cell : '', array_values((array) $row));
            fputcsv($handle, array_pad($row, $width, ''), escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function guardApi(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? '');
        $email = \App\Google\GoogleAuth::email() ?? 'your Google account';

        if ($response->status() === 403
            && (str_contains($message, 'has not been used') || str_contains($message, 'is disabled') || str_contains($message, 'SERVICE_DISABLED'))) {
            throw new MergeParseException(
                'The Google Sheets API is not enabled for the app\'s Cloud project — enable it at '
                .'https://console.cloud.google.com/apis/library/sheets.googleapis.com and try again.',
            );
        }

        if ($response->status() === 403) {
            throw new MergeParseException(
                "You're signed in as {$email}, but that account can't read this sheet — "
                .'ask the sheet\'s owner to share it with you.',
            );
        }

        if ($response->status() === 404) {
            throw new MergeParseException(
                "The linked Google Sheet wasn't found — check the link (signed in as {$email}).",
            );
        }

        throw new MergeParseException(
            'Could not read the linked Google Sheet ('.($message !== '' ? $message : "HTTP {$response->status()}").').',
        );
    }

    /**
     * The anonymous path: the CSV export endpoint, for sheets shared
     * "Anyone with the link can view".
     */
    private function fetchPublicCsv(string $id, string $gid): string
    {
        $response = Http::timeout(30)->get(
            "https://docs.google.com/spreadsheets/d/{$id}/export",
            ['format' => 'csv', 'gid' => $gid],
        );

        if ($response->failed()) {
            throw new MergeParseException(
                "Could not download the linked Google Sheet (HTTP {$response->status()}). "
                .'Check the link, and that the sheet is shared as "Anyone with the link can view" '
                .'(or sign in to Google in Preferences (⌘,) to use a protected sheet).',
            );
        }

        $body = $response->body();

        if (str_starts_with(ltrim($body), '<') || str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            throw new MergeParseException(
                'The linked Google Sheet is not publicly readable — share it as "Anyone with the link" '
                .'(Viewer), or sign in to Google in Preferences (⌘,) and have the sheet shared with you.',
            );
        }

        return $body;
    }
}
