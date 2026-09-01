<?php

namespace App\Merge;

/**
 * Resolves an @Image reference to an artwork file by searching an ordered
 * list of directories — first directory wins. Set art is named by code
 * ("TBC331.jpg"); celebrity art keeps its own naming ("Name - SET - 01.jpg")
 * and lives in a shared folder, which is why multiple directories exist.
 */
final class ArtResolver
{
    /**
     * @param  list<string>  $directories  searched in order
     * @param  list<string>  $extensions  probed when the ref has no direct match
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $extensions,
    ) {}

    public function resolve(string $imageRef): ?string
    {
        foreach ($this->directories as $directory) {
            if (($path = $this->findIn($directory, $imageRef)) !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Every match across all directories, for ambiguity reporting.
     *
     * @return list<string>
     */
    public function resolveAll(string $imageRef): array
    {
        $matches = [];
        foreach ($this->directories as $directory) {
            if (($path = $this->findIn($directory, $imageRef)) !== null) {
                $matches[] = $path;
            }
        }

        return $matches;
    }

    /**
     * True when a match came from the suffix fallback: the ref names an
     * explicit image extension but the matched file carries a different one.
     */
    public function usedSuffixFallback(string $imageRef, string $path): bool
    {
        $refExtension = strtolower(pathinfo($imageRef, PATHINFO_EXTENSION));

        return in_array($refExtension, $this->extensions, true)
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== $refExtension;
    }

    private function findIn(string $directory, string $imageRef): ?string
    {
        $base = rtrim($directory, '/').'/'.$imageRef;

        if (is_file($base)) {
            return $base;
        }

        foreach ($this->extensions as $extension) {
            if (is_file($candidate = $base.'.'.$extension)) {
                return $candidate;
            }
        }

        // A ref with an explicit image extension ("TBC404.jpg") may point at
        // a folder whose exports use another format ("TBC404.png" in RGB).
        // The exact suffix won above; the same stem with any other known
        // extension is the fallback.
        $refExtension = strtolower(pathinfo($imageRef, PATHINFO_EXTENSION));

        if (in_array($refExtension, $this->extensions, true)) {
            $stem = substr($base, 0, -(strlen($refExtension) + 1));

            foreach ($this->extensions as $extension) {
                if ($extension !== $refExtension && is_file($candidate = $stem.'.'.$extension)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
