<?php

namespace App\Manifest;

/**
 * The fully-resolved configuration for one build: app defaults, overlaid by
 * the set's base config, overlaid by the chosen profile, overlaid by
 * runtime (UI/CLI) overrides. All paths are absolute.
 */
final class BuildConfig
{
    /**
     * @param  list<string>  $artDirs
     * @param  list<string>  $treatments  one build per card per treatment
     * @param  array<string,mixed>  $exportOptions
     * @param  list<array{step:string,label?:string,opts?:array}>|null  $pipeline  null = app default
     */
    public function __construct(
        public readonly string $set,
        public readonly ?string $profile,
        public readonly string $mergePath,
        public readonly string $kitPath,
        public readonly string $templatePath,
        public readonly array $artDirs,
        public readonly array $treatments,
        public readonly bool $proof,
        public readonly string $format,
        public readonly array $exportOptions,
        public readonly ?array $pipeline,
    ) {}
}
