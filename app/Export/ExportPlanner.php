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

        foreach ($treatments as $treatment) {
            if (! array_key_exists($treatment, config('wotf.treatments'))) {
                throw new InvalidArgumentException(
                    "Unknown treatment \"{$treatment}\" — expected one of: ".implode(', ', array_keys(config('wotf.treatments'))).'.',
                );
            }
        }

        $records = $this->select($merge, $cardRefs);

        $run = Run::create([
            'status' => 'pending',
            'mode' => $cardRefs === [] ? 'all' : (count($cardRefs) === 1 ? 'single' : 'selected'),
            'treatment' => implode(', ', $treatments),
            'treatments' => $treatments,
            'proof' => $config->proof,
            'set_name' => $config->set,
            'profile' => $config->profile,
            'format' => $config->format,
            'export_options' => $config->exportOptions,
            'pipeline' => $config->pipeline,
            'manifest_path' => $manifestPath,
            'merge_path' => $merge->path,
            'art_dirs' => array_values($config->artDirs),
            'template_path' => $config->templatePath,
            'output_dir' => $outputDir,
            'kit_path' => $config->kitPath,
            'total' => count($records) * count($treatments),
        ]);

        foreach ($records as $record) {
            foreach ($treatments as $treatment) {
                $run->items()->create([
                    'card_code' => $record->cardCode(),
                    'name' => $record->name(),
                    'image_ref' => $record->imageRef(),
                    'status' => 'pending',
                    'payload' => [
                        'ref' => $record->imageRef() !== '' ? $record->imageRef() : $record->cardCode(),
                        'row' => $record->row,
                        'treatment' => $treatment,
                    ],
                ]);
            }
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
