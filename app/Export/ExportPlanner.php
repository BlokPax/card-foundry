<?php

namespace App\Export;

use App\Manifest\BuildConfig;
use App\Merge\MergeFile;
use App\Models\Run;
use InvalidArgumentException;

/**
 * Turns a parsed merge file plus a resolved BuildConfig and a selection into
 * a persisted Run with one RunItem per card. Each card builds independently:
 * its own one-row CSV, its own template copy, its own merged document and
 * exported file — so one bad card fails alone and the run is resumable.
 */
final class ExportPlanner
{
    /**
     * @param  list<string>  $cardRefs  @Image refs, card codes, or names; empty = all
     */
    public function plan(
        MergeFile $merge,
        BuildConfig $config,
        string $outputDir,
        array $cardRefs = [],
        ?string $manifestPath = null,
    ): Run {
        $treatments = array_values($config->treatments);

        if ($treatments === []) {
            throw new InvalidArgumentException('Choose at least one treatment.');
        }

        // The treatment→layer table comes from the resolved config (manifests
        // can override or extend it); the app config is the fallback for
        // configs built outside a manifest.
        $treatmentConfig = $config->treatmentConfig ?: array_map(
            fn ($layer) => ['layer' => $layer, 'serialColumn' => null],
            config('wotf.treatments'),
        );

        foreach ($treatments as $treatment) {
            if (! array_key_exists($treatment, $treatmentConfig)) {
                throw new InvalidArgumentException(
                    "Unknown treatment \"{$treatment}\" — expected one of: ".implode(', ', array_keys($treatmentConfig)).'.',
                );
            }
        }

        $records = $this->select($merge, $cardRefs);

        $serialColumns = [];
        foreach ($treatments as $treatment) {
            $serialColumns[$treatment] = $treatmentConfig[$treatment]['serialColumn']
                ?? $config->serialization['column']
                ?? null;
        }

        $items = [];
        foreach ($records as $record) {
            foreach ($treatments as $treatment) {
                $payload = [
                    'ref' => $record->imageRef() !== '' ? $record->imageRef() : $record->cardCode(),
                    'row' => $record->row,
                    'treatment' => $treatment,
                ];

                // Serialized copies: one item per numbered copy, sized by the
                // treatment's edition column. Cards without a count build once.
                $column = $config->serialize ? $serialColumns[$treatment] : null;
                $max = $column !== null ? (int) $record->get($column) : 0;

                foreach ($max > 0 ? range(1, $max) : [null] as $serial) {
                    $items[] = [
                        'card_code' => $record->cardCode(),
                        'name' => $record->name(),
                        'image_ref' => $record->imageRef(),
                        'status' => 'pending',
                        'payload' => $serial === null
                            ? $payload
                            : $payload + ['serial' => $serial, 'serialMax' => $max],
                    ];
                }
            }
        }

        $run = Run::create([
            'status' => 'pending',
            'mode' => $cardRefs === [] ? 'all' : (count($cardRefs) === 1 ? 'single' : 'selected'),
            'treatment' => implode(', ', $treatments),
            'treatments' => $treatments,
            'treatment_layers' => array_map(fn ($entry) => $entry['layer'], $treatmentConfig),
            'proof' => $config->proof,
            'set_name' => $config->set,
            'profile' => $config->profile,
            'format' => $config->format,
            'export_options' => $config->exportOptions,
            'pipeline' => $config->pipeline,
            'manifest_path' => $manifestPath,
            'merge_path' => $merge->path,
            'art_dirs' => array_values($config->artDirs),
            'white_dirs' => array_values($config->whiteDirs),
            'serialization' => [
                'enabled' => $config->serialize,
                'pad' => $config->serialization['pad'] ?? 2,
                'columns' => $serialColumns,
            ],
            'keywords' => $config->keywords,
            'template_path' => $config->templatePath,
            'output_dir' => $outputDir,
            'kit_path' => $config->kitPath,
            'total' => count($items),
        ]);

        foreach ($items as $item) {
            $run->items()->create($item);
        }

        return $run;
    }

    /**
     * @param  list<string>  $cardRefs
     * @return list<\App\Merge\CardRecord>
     */
    private function select(MergeFile $merge, array $cardRefs): array
    {
        if ($cardRefs === []) {
            return $merge->records;
        }

        $records = [];
        foreach ($cardRefs as $ref) {
            $record = $merge->findByImageRef($ref);

            if ($record === null) {
                throw new InvalidArgumentException(
                    "Card \"{$ref}\" not found in merge file (match by @Image, Card Code, or Name).",
                );
            }

            $records[] = $record;
        }

        return $records;
    }
}
