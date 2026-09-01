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
    public function __construct(
        private readonly BridgeClient $bridge = new BridgeClient,
        private readonly JsxPayloadBuilder $builder = new JsxPayloadBuilder,
        private readonly CsvPreparer $preparer = new CsvPreparer,
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

        $merge = (new MergeFileParser)->parse($run->merge_path);
        $resolver = new ArtResolver($run->art_dirs, config('wotf.art_extensions'));
        $generator = new BuildScriptGenerator($run->pipeline ?: config('wotf.pipeline'), config('wotf.treatments'));

        $workRoot = storage_path("app/runs/{$run->id}");
        if (! is_dir($workRoot)) {
            mkdir($workRoot, 0755, true);
        }

        $run->update(['status' => 'running', 'started_at' => now()]);
        $run->appendLog("Run started — {$run->total} card(s), treatment \"{$run->treatment}\""
            .($run->proof ? ' (proof, white plate suppressed)' : ' (production)').'.');

        foreach ($run->items()->where('status', 'pending')->orderBy('id')->get() as $item) {
            $item->update(['status' => 'running', 'started_at' => now()]);

            [$error, $summary, $warnings] = $this->buildCard($run, $item, $merge, $resolver, $generator, $kit, $workRoot);

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

        $run->refresh();
        $run->update([
            'status' => $run->failed > 0 ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);
        $run->appendLog("Run finished: {$run->completed} succeeded, {$run->failed} failed.");

        return $run;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<string>} [error, summary, warnings]
     */
    private function buildCard(
        Run $run,
        RunItem $item,
        \App\Merge\MergeFile $merge,
        ArtResolver $resolver,
        BuildScriptGenerator $generator,
        string $kit,
        string $workRoot,
    ): array {
        $ref = $item->payload['ref'] ?? ($item->image_ref ?: $item->card_code);
        $treatment = $item->payload['treatment'] ?? ($run->treatments[0] ?? $run->treatment);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ref) ?: "row-{$item->id}";
        $treatmentSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $treatment);

        $workDoc = "{$workRoot}/{$slug}-{$treatmentSlug}.indd";

        try {
            $csv = $this->preparer->prepare($merge, $resolver, "{$workRoot}/{$slug}.csv", [$ref]);

            if (! @copy($run->template_path, $workDoc)) {
                return ["Could not copy template to {$workDoc}", null, []];
            }

            // With multiple treatments each gets its own subfolder.
            $outputDir = rtrim($run->output_dir, '/');
            if (count($run->treatments ?? []) > 1) {
                $outputDir .= '/'.$treatment;
                if (! is_dir($outputDir)) {
                    @mkdir($outputDir, 0755, true);
                }
            }

            $safeName = trim(preg_replace('/[^A-Za-z0-9 _-]+/', '', $item->name) ?: $slug);
            $format = $run->format ?: 'pdf';
            $outputFile = "{$outputDir}/{$slug} - {$safeName} [{$treatment}]"
                .($run->proof && $format === 'pdf' ? ' NO-WHITE' : '').'.'.$format;

            $jsx = $generator->generate([
                'kitPath' => $kit,
                'workDoc' => $workDoc,
                'csvPath' => $csv,
                'outputFile' => $outputFile,
                'treatment' => $treatment,
                'proof' => $run->proof,
                'format' => $format,
                'exportOptions' => $run->export_options ?? [],
            ]);

            $result = $this->bridge->execute($this->builder->build($jsx, []));

            if (! $result->ok) {
                return [$result->error, null, []];
            }

            return $this->interpretReport($run, $item, $result->data, $outputFile);
        } catch (\Throwable $e) {
            return [$e->getMessage(), null, []];
        } finally {
            @unlink($workDoc); // the one-row CSV is kept for debugging
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<string>} [error, summary, warnings]
     */
    private function interpretReport(Run $run, RunItem $item, mixed $data, string $outputFile): array
    {
        $report = is_string($data) ? json_decode($data, true) : (is_array($data) ? $data : null);

        if (! is_array($report)) {
            return ['Bridge returned an unreadable build report: '.substr((string) json_encode($data), 0, 200), null, []];
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
            return [$report['error'].($warnings ? ' | '.implode(' | ', $warnings) : ''), null, []];
        }

        if (($report['ok'] ?? false) !== true) {
            return [$warnings ? implode(' | ', $warnings) : 'Build reported failure without detail.', null, []];
        }

        $stageCount = count($report['stages'] ?? []);

        return [null, "{$stageCount} stages → ".basename($outputFile), $warnings];
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
