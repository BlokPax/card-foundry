<?php

namespace Tests\Feature;

use App\Manifest\ManifestException;
use App\Manifest\ManifestLoader;
use App\Manifest\ManifestWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v0.3.0 manifest surface: treatment→layer config, white-layer directories,
 * serialization, keyword styling, and Google Sheet merge references.
 */
class ManifestV030Test extends TestCase
{
    private string $setDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setDir = sys_get_temp_dir().'/wotf-set-'.uniqid();
        mkdir($this->setDir.'/_exports/WHITE', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->setDir);

        parent::tearDown();
    }

    private function writeManifest(array $base, array $profiles = []): string
    {
        $path = $this->setDir.'/foundry.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => $base + ['merge' => 'merge.csv', 'kit' => '.'],
            'profiles' => $profiles,
        ]));

        return $path;
    }

    private function effective(array $base, array $profiles = [], ?string $profile = null): \App\Manifest\BuildConfig
    {
        return (new ManifestLoader)->load($this->writeManifest($base, $profiles))->effective($profile);
    }

    // ── Treatment → layer config ────────────────────────────────────────

    public function test_treatment_layers_default_to_the_app_config(): void
    {
        $config = $this->effective([]);

        $this->assertSame('Paper Frame', $config->treatmentConfig['Base Paper']['layer']);
        $this->assertSame('Stone Foil', $config->treatmentConfig['Stonefoil']['layer']);
        $this->assertNull($config->treatmentConfig['Base Paper']['serialColumn']);
    }

    public function test_manifest_overrides_and_extends_treatment_layers_key_by_key(): void
    {
        $config = $this->effective([
            'treatmentLayers' => [
                'Base Paper' => 'Paper Frame v2',
                'Holo Rainbow' => ['layer' => 'Holo Frame', 'serialColumn' => 'Holo Edition'],
            ],
        ]);

        $this->assertSame('Paper Frame v2', $config->treatmentConfig['Base Paper']['layer']);
        $this->assertSame('Holo Frame', $config->treatmentConfig['Holo Rainbow']['layer']);
        $this->assertSame('Holo Edition', $config->treatmentConfig['Holo Rainbow']['serialColumn']);
        // Untouched treatments keep their app-default layer.
        $this->assertSame('OCM Frame', $config->treatmentConfig['OCM']['layer']);
    }

    public function test_profile_overrides_a_single_treatment_layer(): void
    {
        $config = $this->effective(
            ['treatmentLayers' => ['Base Paper' => 'Paper Frame v2']],
            ['deluxe' => ['treatmentLayers' => ['OCM' => 'OCM Frame Deluxe']]],
            'deluxe',
        );

        $this->assertSame('Paper Frame v2', $config->treatmentConfig['Base Paper']['layer']);
        $this->assertSame('OCM Frame Deluxe', $config->treatmentConfig['OCM']['layer']);
    }

    public function test_a_treatment_layer_entry_without_a_layer_is_rejected(): void
    {
        $this->expectException(ManifestException::class);

        $this->effective(['treatmentLayers' => ['Base Paper' => ['serialColumn' => 'X']]]);
    }

    // ── White-layer directories ─────────────────────────────────────────

    public function test_white_dirs_default_to_empty(): void
    {
        $this->assertSame([], $this->effective([])->whiteDirs);
    }

    public function test_white_dirs_resolve_relative_to_the_manifest(): void
    {
        $config = $this->effective(['whiteDirs' => ['_exports/WHITE', '/abs/white']]);

        $this->assertSame([$this->setDir.'/_exports/WHITE', '/abs/white'], $config->whiteDirs);
    }

    public function test_writer_relativizes_white_dirs(): void
    {
        $path = $this->writeManifest([]);
        (new ManifestWriter)->saveBase($path, ['whiteDirs' => [$this->setDir.'/_exports/WHITE']]);

        $data = json_decode(file_get_contents($path), true);
        $this->assertSame(['_exports/WHITE'], $data['base']['whiteDirs']);
    }

    // ── Serialization ───────────────────────────────────────────────────

    public function test_serialization_defaults_off(): void
    {
        $config = $this->effective([]);

        $this->assertFalse($config->serialize);
        $this->assertSame(2, $config->serialization['pad']);
        $this->assertNull($config->serialization['column']);
    }

    public function test_serialization_config_from_manifest(): void
    {
        $config = $this->effective([
            'serialize' => true,
            'serialization' => ['pad' => 3, 'column' => 'Max Count'],
        ]);

        $this->assertTrue($config->serialize);
        $this->assertSame(3, $config->serialization['pad']);
        $this->assertSame('Max Count', $config->serialization['column']);
    }

    public function test_profile_can_toggle_serialization_keeping_base_settings(): void
    {
        $config = $this->effective(
            ['serialization' => ['column' => 'Max Count']],
            ['numbered' => ['serialize' => true]],
            'numbered',
        );

        $this->assertTrue($config->serialize);
        $this->assertSame('Max Count', $config->serialization['column']);
    }

    // ── Keywords ────────────────────────────────────────────────────────

    public function test_keywords_default_to_empty(): void
    {
        $this->assertSame([], $this->effective([])->keywords);
    }

    public function test_keywords_config_from_manifest(): void
    {
        $config = $this->effective([
            'keywords' => ['known' => ['ON PLAY', 'SURGE'], 'bare' => ['FLYING'], 'styleBare' => false],
        ]);

        $this->assertSame(['ON PLAY', 'SURGE'], $config->keywords['known']);
        $this->assertSame(['FLYING'], $config->keywords['bare']);
        $this->assertFalse($config->keywords['styleBare']);
    }

    public function test_profile_keywords_merge_key_by_key(): void
    {
        $config = $this->effective(
            ['keywords' => ['known' => ['ON PLAY'], 'styleDefault' => 'Ability Text']],
            ['alt' => ['keywords' => ['known' => ['SURGE']]]],
            'alt',
        );

        $this->assertSame(['SURGE'], $config->keywords['known']);
        $this->assertSame('Ability Text', $config->keywords['styleDefault']);
    }

    // ── Google Sheet merge references ───────────────────────────────────

    public function test_a_google_sheet_url_merge_is_not_resolved_against_the_manifest_folder(): void
    {
        $url = 'https://docs.google.com/spreadsheets/d/abc123/edit#gid=0';
        $config = $this->effective(['merge' => $url]);

        $this->assertSame($url, $config->mergePath);
    }

    public function test_writer_keeps_a_sheet_url_merge_verbatim(): void
    {
        $url = 'https://docs.google.com/spreadsheets/d/abc123/edit#gid=0';
        $path = $this->writeManifest([]);
        (new ManifestWriter)->saveBase($path, ['merge' => $url]);

        $data = json_decode(file_get_contents($path), true);
        $this->assertSame($url, $data['base']['merge']);
    }

    // ── Export options passthrough ──────────────────────────────────────

    public function test_new_export_options_flow_through(): void
    {
        $config = $this->effective([
            'export' => [
                'format' => 'pdf',
                'bleed' => 3,
                'cropMarks' => true,
                'registrationMarks' => true,
                'combine' => true,
            ],
        ]);

        $this->assertSame('pdf', $config->format);
        $this->assertSame(3, $config->exportOptions['bleed']);
        $this->assertTrue($config->exportOptions['cropMarks']);
        $this->assertTrue($config->exportOptions['registrationMarks']);
        $this->assertTrue($config->exportOptions['combine']);
    }
}
