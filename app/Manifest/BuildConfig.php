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
     * @param  list<string>  $whiteDirs  searched for white-ink layer art, like artDirs
     * @param  list<string>  $treatments  one build per card per treatment
     * @param  array<string,array{layer:string,serialColumn:?string}>  $treatmentConfig
     * @param  array{pad:int,column:?string}  $serialization
     * @param  array<string,mixed>  $keywords  ability-keyword styling config
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
        public readonly array $whiteDirs = [],
        public readonly array $treatmentConfig = [],
        public readonly bool $serialize = false,
        public readonly array $serialization = ['pad' => 2, 'column' => null],
        public readonly array $keywords = [],
    ) {}

    /**
     * Treatment key => template layer name, for the build script.
     *
     * @return array<string,string>
     */
    public function treatmentLayers(): array
    {
        return array_map(fn (array $entry) => $entry['layer'], $this->treatmentConfig);
    }

    /**
     * The merge column holding the edition size for a treatment: the
     * treatment's own serialColumn, else the global serialization column.
     */
    public function serialColumnFor(string $treatment): ?string
    {
        return $this->treatmentConfig[$treatment]['serialColumn']
            ?? $this->serialization['column']
            ?? null;
    }
}
