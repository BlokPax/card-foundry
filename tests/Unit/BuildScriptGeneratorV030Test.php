<?php

namespace Tests\Unit;

use App\InDesign\BuildScriptGenerator;
use PHPUnit\Framework\TestCase;

/**
 * v0.3.0 generator surface: printer's marks + bleed, serialized copies,
 * white-layer art, and manifest-managed ability keywords.
 */
class BuildScriptGeneratorV030Test extends TestCase
{
    private function generator(array $pipeline = []): BuildScriptGenerator
    {
        return new BuildScriptGenerator(
            pipeline: $pipeline ?: [
                ['step' => 'abilityKeywords', 'opts' => []],
                ['step' => 'applyCardData', 'opts' => ['csvPath' => '@csv', 'treatment' => '@treatment', 'numerator' => '@serial']],
            ],
            treatments: ['Base Paper' => 'Paper Frame'],
        );
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'kitPath' => '/kit',
            'workDoc' => '/work/TBC197.indd',
            'csvPath' => '/work/TBC197.csv',
            'outputFile' => '/out/TBC197.pdf',
            'treatment' => 'Base Paper',
            'proof' => true,
            'format' => 'pdf',
            'exportOptions' => [],
        ], $overrides);
    }

    // ── Marks + bleed ───────────────────────────────────────────────────

    public function test_pdf_marks_and_bleed_options_set_export_preferences(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'exportOptions' => ['cropMarks' => true, 'registrationMarks' => true, 'bleed' => true],
        ]));

        $this->assertStringContainsString('pdfExportPreferences.cropMarks = true', $jsx);
        $this->assertStringContainsString('pdfExportPreferences.registrationMarks = true', $jsx);
        $this->assertStringContainsString('useDocumentBleedWithPdf = true', $jsx);
    }

    public function test_numeric_bleed_sets_an_explicit_bleed_in_millimetres(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'exportOptions' => ['bleed' => 3],
        ]));

        $this->assertStringContainsString('"3mm"', $jsx);
        $this->assertStringContainsString('bleedTop', $jsx);
    }

    public function test_marks_can_be_explicitly_disabled(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'exportOptions' => ['cropMarks' => false],
        ]));

        $this->assertStringContainsString('pdfExportPreferences.cropMarks = false', $jsx);
    }

    public function test_no_marks_options_leave_export_preferences_untouched(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringNotContainsString('cropMarks', $jsx);
        $this->assertStringNotContainsString('useDocumentBleedWithPdf', $jsx);
    }

    public function test_a_preset_with_marks_options_warns_that_the_preset_wins(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'exportOptions' => ['preset' => 'Printer_Cards', 'cropMarks' => true],
        ]));

        $this->assertStringContainsString('preset overrides', $jsx);
    }

    // ── Serials ─────────────────────────────────────────────────────────

    public function test_serial_placeholder_resolves_to_the_padded_numerator(): void
    {
        $jsx = $this->generator()->generate($this->params(['serial' => '07', 'serialMax' => '25']));

        $this->assertStringContainsString('"numerator":"07"', $jsx);
    }

    public function test_serial_placeholder_defaults_to_01(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringContainsString('"numerator":"01"', $jsx);
    }

    // ── White-layer art ─────────────────────────────────────────────────

    public function test_white_art_placeholder_resolves(): void
    {
        $generator = $this->generator([
            ['step' => 'whiteLayer', 'opts' => ['art' => '@white']],
        ]);

        $jsx = $generator->generate($this->params(['whiteArt' => '/white/TBC197-KO.psd']));

        $this->assertStringContainsString('"art":"/white/TBC197-KO.psd"', $jsx);
    }

    public function test_white_art_is_passed_to_the_white_plate_stage(): void
    {
        $jsx = $this->generator()->generate($this->params(['whiteArt' => '/white/TBC197-KO.psd', 'proof' => false]));

        $this->assertStringContainsString('"name":"whitePlate"', $jsx);
        $this->assertStringContainsString('/white/TBC197-KO.psd', $jsx);
    }

    // ── Keywords ────────────────────────────────────────────────────────

    public function test_keyword_config_rewrites_the_known_keyword_table(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'keywords' => ['known' => ['ON PLAY', 'SURGE']],
        ]));

        $this->assertStringContainsString('WOTF.KNOWN_KEYWORDS', $jsx);
        $this->assertStringContainsString('"ON PLAY"', $jsx);
    }

    public function test_keyword_styles_flow_into_the_ability_keywords_stage(): void
    {
        $jsx = $this->generator()->generate($this->params([
            'keywords' => [
                'known' => ['ON PLAY'],
                'bare' => ['FLYING'],
                'styleDefault' => 'Ability Text v2',
                'styleBare' => false,
            ],
        ]));

        $this->assertStringContainsString('"styleDefault":"Ability Text v2"', $jsx);
        $this->assertStringContainsString('"styleBareKeywords":false', $jsx);
        $this->assertStringContainsString('"bareKeywords":["FLYING"]', $jsx);
    }

    public function test_no_keyword_config_leaves_wotflib_defaults_alone(): void
    {
        $jsx = $this->generator()->generate($this->params());

        $this->assertStringNotContainsString('KNOWN_KEYWORDS', $jsx);
    }
}
