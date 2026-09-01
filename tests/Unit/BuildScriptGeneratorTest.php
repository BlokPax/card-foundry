<?php

namespace Tests\Unit;

use App\InDesign\BuildScriptGenerator;
use PHPUnit\Framework\TestCase;

class BuildScriptGeneratorTest extends TestCase
{
    private function generator(): BuildScriptGenerator
    {
        return new BuildScriptGenerator(
            pipeline: [
                ['step' => 'lbAndItalics', 'label' => 'Italics + line breaks', 'opts' => []],
                ['step' => 'orbitalParents', 'label' => 'Orbital parent pages', 'opts' => []],
                ['step' => 'applyCardData', 'label' => 'Serials + stamp', 'opts' => ['csvPath' => '@csv', 'treatment' => '@treatment']],
            ],
            treatments: [
                'Base Paper' => 'Paper Frame',
                'Stonefoil' => 'Stone Foil',
                'Dragon Scale' => 'Dragon Foil',
            ],
        );
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'kitPath' => '/kit',
            'workDoc' => '/work/TBC197.indd',
            'csvPath' => '/work/TBC197.csv',
            'outputFile' => '/out/TBC197.pdf',
            'treatment' => 'Stonefoil',
            'proof' => true,
            'format' => 'pdf',
            'exportOptions' => [],
        ], $overrides);
    }

    public function test_png_format_exports_png_at_requested_resolution(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'format' => 'png',
            'outputFile' => '/out/TBC197.png',
            'exportOptions' => ['ppi' => 300],
        ]));

        $this->assertStringContainsString('PNG_FORMAT', $jsx);
        $this->assertStringContainsString('pngExportPreferences', $jsx);
        $this->assertStringContainsString('300', $jsx);
        $this->assertStringContainsString('/out/TBC197.png', $jsx);
        $this->assertStringNotContainsString('PDF_TYPE', $jsx);
    }

    public function test_pdf_format_can_name_an_export_preset(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'exportOptions' => ['preset' => 'Printer_Cards'],
        ]));

        $this->assertStringContainsString('pdfExportPresets', $jsx);
        $this->assertStringContainsString('Printer_Cards', $jsx);
    }

    public function test_unknown_format_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->generate($this->params(['format' => 'tiff']));
    }

    public function test_stage_warnings_stay_advisory_in_the_generated_script(): void
    {
        // A step that skips itself ("no feather layer") must not flip the
        // report to failure — only a thrown step or unknown step does.
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringNotContainsString('r.warnings.length) report.ok = false', $jsx);
        $this->assertStringContainsString('No such step in wotfLib.', $jsx); // unknown step still fails
    }

    public function test_runs_headless_with_the_kit_library(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringContainsString('WOTF_SILENT', $jsx);
        $this->assertStringContainsString('NEVER_INTERACT', $jsx);
        $this->assertStringContainsString('/kit/wotfLib.jsxinc', $jsx);
        $this->assertStringContainsString('evalFile', $jsx);
    }

    public function test_merges_then_runs_stages_then_exports(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $merge = strpos($jsx, 'mergeRecords');
        $firstStage = strpos($jsx, 'lbAndItalics');
        $export = strpos($jsx, 'PDF_TYPE');

        $this->assertNotFalse($merge);
        $this->assertNotFalse($firstStage);
        $this->assertNotFalse($export);
        $this->assertLessThan($firstStage, $merge);
        $this->assertLessThan($export, $firstStage);
        $this->assertStringContainsString('selectDataSource', $jsx);
    }

    public function test_stages_run_in_configured_order(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertLessThan(strpos($jsx, '"orbitalParents"'), strpos($jsx, '"lbAndItalics"'));
        $this->assertLessThan(strpos($jsx, '"applyCardData"'), strpos($jsx, '"orbitalParents"'));
    }

    public function test_placeholder_opts_are_resolved(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringContainsString('/work/TBC197.csv', $jsx);
        $this->assertStringContainsString('"Stonefoil"', $jsx);
        $this->assertStringNotContainsString('@csv', $jsx);
        $this->assertStringNotContainsString('@treatment', $jsx);
    }

    public function test_shows_only_the_chosen_treatment_layer(): void
    {
        $jsx = $this->generator()->generate($this->params(['treatment' => 'Stonefoil']));

        $this->assertStringContainsString('"Stone Foil"', $jsx);   // shown
        $this->assertStringContainsString('"Paper Frame"', $jsx);  // hidden with the rest
        $this->assertStringContainsString('"Dragon Foil"', $jsx);
    }

    public function test_unknown_treatment_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->generate($this->params(['treatment' => 'Holo Rainbow']));
    }

    public function test_proof_mode_suppresses_the_white_plate(): void
    {
        $proof = $this->generator()->generate($this->params(['proof' => true]));
        $production = $this->generator()->generate($this->params(['proof' => false]));

        $this->assertMatchesRegularExpression('/whitePlate.*visible.*false/s', $proof);
        $this->assertMatchesRegularExpression('/whitePlate.*visible.*true/s', $production);
    }

    public function test_report_carries_phase_and_stage_timings(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringContainsString('Date.now()', $jsx);
        $this->assertStringContainsString('timings', $jsx);
        $this->assertStringContainsString('ms', $jsx);
    }

    public function test_returns_a_json_report(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringContainsString('JSON', $jsx);
        $this->assertStringContainsString('stages', $jsx);
    }
}
