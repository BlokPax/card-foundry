<?php

namespace App\Manifest;

/**
 * Decides whether a path is "portable" — expressible relative to the
 * manifest's folder — and produces that relative form.
 *
 * Rules:
 *  - Portable when writable relative to the manifest's folder climbing at
 *    most 3 parents (works for any shared tree — Dropbox, a zip, a USB stick).
 *  - Cloud storage isn't required, but it IS a boundary: if either side
 *    lives inside a recognized cloud root (Dropbox / CloudStorage provider)
 *    and the traversal would cross above that root, the path is definitely
 *    machine-specific.
 */
final class Portability
{
    public static function relativize(string $path, string $baseDir, int $maxClimb = 3): ?string
    {
        if (str_starts_with($path, 'data:')) {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            return $path; // already relative — portable by definition
        }

        $from = array_values(array_filter(explode('/', $baseDir)));
        $to = array_values(array_filter(explode('/', $path)));

        $common = 0;
        while ($common < count($from) && $common < count($to) && $from[$common] === $to[$common]) {
            $common++;
        }

        $ups = count($from) - $common;

        if ($common === 0 || $ups > $maxClimb) {
            return null;
        }

        // Crossing a cloud-storage boundary is never portable: the common
        // ancestor must not sit above either side's cloud root.
        foreach ([self::cloudRootDepth($from), self::cloudRootDepth($to)] as $rootDepth) {
            if ($rootDepth !== null && $common < $rootDepth) {
                return null;
            }
        }

        $relative = str_repeat('../', $ups).implode('/', array_slice($to, $common));

        return $relative === '' ? '.' : $relative;
    }

    /**
     * The 1-based depth of the cloud-root component in the path, or null.
     * Recognizes ~/Library/CloudStorage/<Provider>-<Label> and Dropbox
     * folder conventions ("Dropbox", "Acme Dropbox", "Dropbox-Acme").
     *
     * @param  list<string>  $components
     */
    private static function cloudRootDepth(array $components): ?int
    {
        foreach ($components as $index => $component) {
            if ($index >= 1 && $components[$index - 1] === 'CloudStorage') {
                return $index + 1;
            }

            if ($component === 'Dropbox'
                || str_starts_with($component, 'Dropbox-')
                || str_ends_with($component, ' Dropbox')) {
                return $index + 1;
            }
        }

        return null;
    }
}
