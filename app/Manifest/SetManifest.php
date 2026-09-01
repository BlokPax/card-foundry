<?php

namespace App\Manifest;

final class SetManifest
{
    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,array<string,mixed>>  $profiles
     */
    public function __construct(
        public readonly string $path,
        public readonly string $set,
        public readonly array $base,
        public readonly array $profiles,
        public readonly string $defaultProfileName = 'Default',
    ) {}

    /**
     * @return list<string>
     */
    public function profileNames(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * Absolute path of the set's cover image, if one is configured.
     */
    public function cover(): ?string
    {
        $cover = $this->base['cover'] ?? null;

        if (! is_string($cover) || $cover === '') {
            return null;
        }

        // Embedded covers (data URIs) are location-independent by design.
        return str_starts_with($cover, 'data:') ? $cover : $this->resolve($cover);
    }

    /**
     * Resolve the effective build config: app defaults ← base ← profile
     * ← runtime overrides. Every key is overridable at every layer.
     *
     * @param  array<string,mixed>  $overrides
     */
    public function effective(?string $profile, array $overrides = []): BuildConfig
    {
        if ($profile !== null && ! array_key_exists($profile, $this->profiles)) {
            throw new ManifestException(
                "Profile \"{$profile}\" not defined in {$this->path} (has: ".implode(', ', $this->profileNames() ?: ['none']).').',
            );
        }

        $defaults = [
            'kit' => config('wotf.kit_path') ?? '',
            'merge' => '',
            'template' => '',
            'artDirs' => [],
            'treatment' => ['Base Paper'],
            'proof' => true,
            'export' => ['format' => 'pdf'],
            'pipeline' => null,
        ];

        // UI/CLI overrides may pass a flat "format" for convenience.
        if (array_key_exists('format', $overrides)) {
            $overrides['export'] = array_replace((array) ($overrides['export'] ?? []), ['format' => $overrides['format']]);
            unset($overrides['format']);
        }

        $merged = $this->overlay($defaults, $this->base);
        if ($profile !== null) {
            $merged = $this->overlay($merged, $this->profiles[$profile]);
        }
        $merged = $this->overlay($merged, $overrides);

        // A kit-relative template default when none is given anywhere.
        if (($merged['template'] ?? '') === '' && ($merged['kit'] ?? '') !== '') {
            $merged['template'] = rtrim((string) $merged['kit'], '/').'/'.config('wotf.kit_template');
        }

        $export = (array) ($merged['export'] ?? []);
        $format = strtolower((string) ($export['format'] ?? 'pdf'));

        return new BuildConfig(
            set: $this->set,
            profile: $profile,
            mergePath: $this->resolve((string) $merged['merge']),
            kitPath: $this->resolve((string) $merged['kit']),
            templatePath: $this->resolve((string) $merged['template']),
            artDirs: array_values(array_map($this->resolve(...), (array) $merged['artDirs'])),
            treatments: array_values(array_filter((array) $merged['treatment'], fn ($t) => is_string($t) && $t !== '')),
            proof: (bool) $merged['proof'],
            format: $format,
            exportOptions: array_diff_key($export, ['format' => true]),
            pipeline: $merged['pipeline'] ?? null,
        );
    }

    /**
     * @param  array<string,mixed>  $under
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function overlay(array $under, array $over): array
    {
        foreach ($over as $key => $value) {
            // "export" merges key-by-key so a profile can change just the
            // format while keeping the base ppi/preset; everything else
            // (scalars, artDirs, pipeline) replaces wholesale.
            if ($key === 'export' && is_array($value) && is_array($under[$key] ?? null)) {
                $under[$key] = array_replace($under[$key], $value);
            } else {
                $under[$key] = $value;
            }
        }

        return $under;
    }

    private function resolve(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return $this->normalize(dirname($this->path).'/'.$path);
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
