<?php

namespace App\Export;

use App\InDesign\BridgeClient;
use App\InDesign\BuildScriptGenerator;
use App\InDesign\JsxPayloadBuilder;
use App\Merge\ArtResolver;
use App\Merge\MergeFileParser;
use App\Models\Run;
use App\Models\RunItem;
use RuntimeException;

/**
 * Executes a planned Run card by card. Per card: prepare a one-row CSV
 * (absolute art paths, typographer's quotes), copy the template, and send
 * one generated build script through the bridge — merge, pipeline stages,
 * PDF export, close. Every stage report is persisted on the run.
 */
final class ExportRunner
{
    /** The first generated build script of the run, snapshotted into the run record. */
    private ?string $sampleJsx = null;

    public function __construct(
        private readonly BridgeClient $bridge = new BridgeClient,
        private readonly JsxPayloadBuilder $builder = new JsxPayloadBuilder,
        private readonly CsvPreparer $preparer = new CsvPreparer,
        private readonly ?PdfCombiner $combiner = null,
    ) {}

    /**
     * @param  callable(RunItem, ?string):void|null  $onItem  called after each item (error message or null)
     */
    public function run(Run $run, ?callable $onItem = null): Run
    {
        $kit = rtrim((string) $run->kit_path, '/');

        if (! is_file($kit.'/'.config('wotf.kit_library'))) {
            throw new RuntimeException("Build kit is missing ".config('wotf.kit_library')." at: {$kit}");
        }

        // A set may carry its own library (frozen or customized) in
        // .foundry/scripts/ — it replaces the kit's for this set only.
        $libPath = $kit.'/'.config('wotf.kit_library');
        $scriptsOverride = false;
        if (($foundryDir = $this->foundryDir($run)) !== null
            && is_file($candidate = $foundryDir.'/scripts/'.config('wotf.kit_library'))) {
            $libPath = $candidate;
            $scriptsOverride = true;
            $run->appendLog('Using the set\'s own library: .foundry/scripts/'.config('wotf.kit_library'));
        }

        $merge = (new MergeFileParser)->parse($run->merge_path);
        $resolver = new ArtResolver($run->art_dirs, config('wotf.art_extensions'));
        $whiteResolver = ($run->white_dirs ?? []) !== []
            ? new ArtResolver($run->white_dirs, config('wotf.art_extensions'))
            : null;
        $generator = new BuildScriptGenerator(
            $run->pipeline ?: config('wotf.pipeline'),
            $run->treatment_layers ?: config('wotf.treatments'),
        );

        $workRoot = storage_path("app/runs/{$run->id}");
        if (! is_dir($workRoot)) {
            mkdir($workRoot, 0755, true);
        }

        $this->sampleJsx = null;

        $run->update(['status' => 'running', 'started_at' => now()]);
        $run->appendLog("Run started — {$run->total} card(s), treatment \"{$run->treatment}\""
            .($run->proof ? ' (proof, white plate suppressed)' : ' (production)').'.');

        foreach ($run->items()->where('status', 'pending')->orderBy('id')->get() as $item) {
            $item->update(['status' => 'running', 'started_at' => now()]);

            [$error, $summary, $warnings, $output] = $this->buildCard($run, $item, $merge, $resolver, $whiteResolver, $generator, $kit, $libPath, $workRoot);

            $tag = count($run->treatments ?? []) > 1 && isset($item->payload['treatment'])
                ? " [{$item->payload['treatment']}]"
                : '';

            if ($error === null) {
                // Warnings from a completed build live in `error` so the UI
                // can show them per item; `status` is what tells them apart.
                $item->update([
                    'status' => 'done',
                    'result' => $summary,
                    'error' => $warnings !== [] ? implode(' | ', $warnings) : null,
                    'payload' => $item->payload + ['output' => $output],
                    'finished_at' => now(),
                ]);
                $run->increment('completed');
                $run->appendLog("✓ {$item->name} ({$item->image_ref}){$tag}".($summary ? " — {$summary}" : ''));
                $onItem && $onItem($item, null);
            } else {
                $item->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
                $run->increment('failed');
                $run->appendLog("✗ {$item->name} ({$item->image_ref}){$tag}: {$error}");
                $onItem && $onItem($item, $error);
            }
        }

        if (($run->format ?: 'pdf') === 'pdf' && ! empty(($run->export_options ?? [])['combine'])) {
            $this->combinePdfs($run);
        }

        $run->refresh();
        $run->update([
            'status' => $run->failed > 0 ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);
        $run->appendLog("Run finished: {$run->completed} succeeded, {$run->failed} failed.");

        $this->writeRunRecord($run, $libPath, $scriptsOverride);

        return $run;
    }

    /**
     * The set's .foundry directory, when this run came from a manifest.
     */
    private function foundryDir(Run $run): ?string
    {
        if (! $run->manifest_path) {
            return null;
        }

        return \App\Manifest\FoundryLayout::foundryDirFor((string) $run->manifest_path);
    }

    /**
     * Drops an immutable record of the finished run into
     * <set>/.foundry/runs/<id>/: run.json (the resolved parameters plus
     * content hashes of the JSX library and template), merge.csv (the
     * exact data that built the cards), and build-script.jsx (one
     * representative generated script — per-card scripts differ only in
     * paths and serials). This is the durable "what built this set"
     * history — the local database is per-machine and disposable.
     * Recording must never fail a run.
     */
    private function writeRunRecord(Run $run, string $libPath, bool $scriptsOverride): void
    {
        try {
            $foundryDir = $this->foundryDir($run);
            if ($foundryDir === null) {
                return;
            }

            $stem = sprintf(
                '%s-%s-%s',
                \App\Manifest\FoundryLayout::stamp(),
                \App\Manifest\FoundryLayout::user(),
                bin2hex(random_bytes(2)),
            );

            $dir = "{$foundryDir}/runs/{$stem}";
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $snapshot = null;
            if (is_file($run->merge_path)) {
                $snapshot = 'merge.csv';
                @copy($run->merge_path, "{$dir}/{$snapshot}");
            }

            if ($this->sampleJsx !== null) {
                @file_put_contents("{$dir}/build-script.jsx", $this->sampleJsx);
            }

            $failures = $run->items()->where('status', 'failed')->get()
                ->map(fn ($item) => ['ref' => $item->image_ref ?: $item->card_code, 'error' => $item->error])
                ->values()->all();

            $record = [
                'formatVersion' => \App\Manifest\FoundryLayout::RUN_FORMAT_VERSION,
                'appVersion' => \App\Manifest\FoundryLayout::appVersion(),
                'recordedAt' => now()->toIso8601String(),
                'user' => \App\Manifest\FoundryLayout::user(),
                'set' => $run->set_name,
                'profile' => $run->profile,
                'mode' => $run->mode,
                'treatments' => $run->treatments,
                'treatmentLayers' => $run->treatment_layers,
                'proof' => $run->proof,
                'format' => $run->format,
                'exportOptions' => $run->export_options,
                'serialization' => $run->serialization,
                'keywords' => $run->keywords,
                'pipeline' => $run->pipeline, // null = the app default of this app version
                'artDirs' => $run->art_dirs,
                'whiteDirs' => $run->white_dirs,
                'outputDir' => $run->output_dir,
                'kit' => [
                    'path' => $run->kit_path,
                    'lib' => $libPath,
                    'libHash' => is_file($libPath) ? md5_file($libPath) : null,
                    'scriptsOverride' => $scriptsOverride,
                    'buildScript' => $this->sampleJsx !== null ? 'build-script.jsx' : null,
                ],
                'template' => [
                    'path' => $run->template_path,
                    'hash' => is_file($run->template_path) ? md5_file($run->template_path) : null,
                ],
                'merge' => [
                    'path' => $run->merge_path,
                    'snapshot' => $snapshot,
                    'hash' => is_file($run->merge_path) ? md5_file($run->merge_path) : null,
                ],
                'totals' => [
                    'planned' => $run->total,
                    'completed' => $run->completed,
                    'failed' => $run->failed,
                ],
                'failures' => $failures,
                'startedAt' => $run->started_at?->toIso8601String(),
                'finishedAt' => $run->finished_at?->toIso8601String(),
            ];

            file_put_contents(
                "{$dir}/run.json",
                json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );

            $run->appendLog("Run record saved: .foundry/runs/{$stem}/");
        } catch (\Throwable $e) {
            $run->appendLog("  ⚠ Could not write the run record: {$e->getMessage()}");
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<string>, 3: ?string} [error, summary, warnings, output]
     */
    private function buildCard(
        Run $run,
        RunItem $item,
        \App\Merge\MergeFile $merge,
        ArtResolver $resolver,
        ?ArtResolver $whiteResolver,
        BuildScriptGenerator $generator,
        string $kit,
        string $libPath,
        string $workRoot,
    ): array {
        $ref = $item->payload['ref'] ?? ($item->image_ref ?: $item->card_code);
        $treatment = $item->payload['treatment'] ?? ($run->treatments[0] ?? $run->treatment);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ref) ?: "row-{$item->id}";
        $treatmentSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $treatment);

        // Serialized copies carry their padded copy number ("03" of "25").
        $serial = $item->payload['serial'] ?? null;
        $serialMax = $item->payload['serialMax'] ?? null;
        $pad = (int) (($run->serialization ?? [])['pad'] ?? 2);
        $numerator = $serial !== null ? str_pad((string) $serial, max(1, $pad), '0', STR_PAD_LEFT) : null;

        $workDoc = "{$workRoot}/{$slug}-{$treatmentSlug}".($numerator !== null ? "-{$numerator}" : '').'.indd';

        try {
            $csv = $this->preparer->prepare($merge, $resolver, "{$workRoot}/{$slug}.csv", [$ref]);

            if (! @copy($run->template_path, $workDoc)) {
                return ["Could not copy template to {$workDoc}", null, [], null];
            }

            // With multiple treatments each gets its own subfolder.
            $outputDir = rtrim($run->output_dir, '/');
            if (count($run->treatments ?? []) > 1) {
                $outputDir .= '/'.$treatment;
                if (! is_dir($outputDir)) {
                    @mkdir($outputDir, 0755, true);
                }
            }

            $whiteArt = null;
            if ($whiteResolver !== null) {
                $whiteArt = $whiteResolver->resolve($ref);
                if ($whiteArt === null) {
                    $run->appendLog("  ⚠ {$ref}: No white-layer art found in any white directory.");
                }
            }

            $safeName = trim(preg_replace('/[^A-Za-z0-9 _-]+/', '', $item->name) ?: $slug);
            $format = $run->format ?: 'pdf';
            $outputFile = "{$outputDir}/{$slug} - {$safeName} [{$treatment}]"
                .($numerator !== null ? " {$numerator}of{$serialMax}" : '')
                .($run->proof && $format === 'pdf' ? ' NO-WHITE' : '').'.'.$format;

            $jsx = $generator->generate([
                'kitPath' => $kit,
                'libPath' => $libPath,
                'workDoc' => $workDoc,
                'csvPath' => $csv,
                'outputFile' => $outputFile,
                'treatment' => $treatment,
                'proof' => $run->proof,
                'format' => $format,
                'exportOptions' => $run->export_options ?? [],
                'serial' => $numerator ?? '01',
                'serialMax' => (string) ($serialMax ?? ''),
                'whiteArt' => $whiteArt ?? '',
                'keywords' => $run->keywords ?? [],
            ]);

            $this->sampleJsx ??= $jsx;

            $result = $this->bridge->execute($this->builder->build($jsx, []));

            if (! $result->ok) {
                return [$result->error, null, [], null];
            }

            return $this->interpretReport($run, $item, $result->data, $outputFile);
        } catch (\Throwable $e) {
            return [$e->getMessage(), null, [], null];
        } finally {
            @unlink($workDoc); // the one-row CSV is kept for debugging
        }
    }

    /**
     * One PDF per treatment, holding every successfully built card of the
     * run in item order — the "whole set as a single document" export.
     */
    private function combinePdfs(Run $run): void
    {
        $groups = [];
        foreach ($run->items()->where('status', 'done')->orderBy('id')->get() as $item) {
            $output = $item->payload['output'] ?? null;
            if ($output === null) {
                continue;
            }
            $treatment = $item->payload['treatment'] ?? ($run->treatments[0] ?? $run->treatment);
            $groups[$treatment][] = $output;
        }

        $setPart = trim(preg_replace('/[^A-Za-z0-9 _-]+/', '', (string) $run->set_name) ?: '')
            ?: pathinfo($run->merge_path, PATHINFO_FILENAME);

        foreach ($groups as $treatment => $files) {
            $dir = rtrim($run->output_dir, '/').(count($run->treatments ?? []) > 1 ? '/'.$treatment : '');
            $combined = "{$dir}/{$setPart} [{$treatment}]".($run->proof ? ' NO-WHITE' : '').'.pdf';

            try {
                ($this->combiner ?? app(PdfCombiner::class))->combine($files, $combined);
                $run->appendLog('✓ Combined '.count($files)." PDF(s) → ".basename($combined));
            } catch (\Throwable $e) {
                $run->appendLog("  ⚠ Combine failed for {$treatment}: {$e->getMessage()} — the per-card PDFs are untouched.");
            }
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<string>, 3: ?string} [error, summary, warnings, output]
     */
    private function interpretReport(Run $run, RunItem $item, mixed $data, string $outputFile): array
    {
        $report = is_string($data) ? json_decode($data, true) : (is_array($data) ? $data : null);

        if (! is_array($report)) {
            return ['Bridge returned an unreadable build report: '.substr((string) json_encode($data), 0, 200), null, [], null];
        }

        if ($timing = $this->timingSummary($report)) {
            $run->appendLog("  ⏱ {$item->image_ref} {$timing}");
        }

        $warnings = [];
        foreach ($report['stages'] ?? [] as $stage) {
            foreach ($stage['warnings'] ?? [] as $warning) {
                $warnings[] = "[{$stage['name']}] {$warning}";
                $run->appendLog("  ⚠ {$item->image_ref} {$stage['name']}: {$warning}");
            }
        }

        if (! empty($report['error'])) {
            return [$report['error'].($warnings ? ' | '.implode(' | ', $warnings) : ''), null, [], null];
        }

        if (($report['ok'] ?? false) !== true) {
            return [$warnings ? implode(' | ', $warnings) : 'Build reported failure without detail.', null, [], null];
        }

        $stageCount = count($report['stages'] ?? []);

        return [null, "{$stageCount} stages → ".basename($outputFile), $warnings, $outputFile];
    }

    private function timingSummary(array $report): ?string
    {
        $timings = $report['timings'] ?? [];
        if ($timings === []) {
            return null;
        }

        $s = fn ($ms) => number_format(((int) $ms) / 1000, 1).'s';

        $phases = [];
        foreach (['open', 'merge', 'stages', 'export', 'close'] as $phase) {
            if (isset($timings[$phase])) {
                $phases[] = "{$phase} {$s($timings[$phase])}";
            }
        }

        $stages = collect($report['stages'] ?? [])
            ->filter(fn ($stage) => isset($stage['ms']))
            ->sortByDesc('ms')
            ->take(3)
            ->map(fn ($stage) => "{$stage['name']} {$s($stage['ms'])}")
            ->implode(', ');

        return implode(' · ', $phases).($stages !== '' ? " — slowest: {$stages}" : '');
    }
}
