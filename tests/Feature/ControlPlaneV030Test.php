<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ControlPlaneV030Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        File::deleteDirectory(storage_path('app/merge-cache'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    public function test_a_google_sheet_url_merge_downloads_and_parses(): void
    {
        Http::fake([
            'docs.google.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/merge-sample.csv'))),
        ]);

        Livewire::test(ControlPlane::class)
            ->set('mergePath', 'https://docs.google.com/spreadsheets/d/gui-sheet/edit#gid=3')
            ->assertSet('parseError', null)
            ->assertSet('summary.cards', 5)
            // The URL must not be mangled into a filesystem path.
            ->assertSet('mergePath', 'https://docs.google.com/spreadsheets/d/gui-sheet/edit#gid=3');
    }

    public function test_a_private_sheet_shows_the_sharing_hint_as_parse_error(): void
    {
        Http::fake(['docs.google.com/*' => Http::response('<html>Sign in</html>')]);

        Livewire::test(ControlPlane::class)
            ->set('mergePath', 'https://docs.google.com/spreadsheets/d/gui-sheet/edit')
            ->assertSet('summary', []);

        $component = Livewire::test(ControlPlane::class)
            ->set('mergePath', 'https://docs.google.com/spreadsheets/d/gui-sheet/edit');

        $this->assertStringContainsString('Anyone with the link', $component->get('parseError'));
    }

    public function test_pdf_options_and_serialize_save_into_the_manifest(): void
    {
        $dir = sys_get_temp_dir().'/wotf-cp-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');

        try {
            mkdir($dir.'/WHITE');

            Livewire::test(ControlPlane::class)
                ->set('mergePath', $dir.'/merge.csv')
                ->set('cropMarks', true)
                ->set('bleed', true)
                ->set('combine', true)
                ->set('serialize', true)
                ->set('newWhiteDir', $dir.'/WHITE')
                ->call('addWhiteDir')
                ->call('saveToManifest');

            $data = json_decode(file_get_contents($dir.'/.foundry/config.json'), true);

            $this->assertTrue($data['base']['export']['cropMarks']);
            $this->assertTrue($data['base']['export']['bleed']);
            $this->assertTrue($data['base']['export']['combine']);
            $this->assertTrue($data['base']['serialize']);
            $this->assertSame(['WHITE'], $data['base']['whiteDirs']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_manifest_export_options_and_treatments_prefill_the_ui(): void
    {
        $dir = sys_get_temp_dir().'/wotf-cp-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => [
                'merge' => 'merge.csv',
                'treatment' => ['Holo Rainbow'],
                'treatmentLayers' => ['Holo Rainbow' => 'Holo Frame'],
                'serialize' => true,
                'whiteDirs' => ['WHITE'],
                'export' => ['format' => 'pdf', 'registrationMarks' => true, 'combine' => true],
            ],
            'profiles' => [],
        ]));

        try {
            Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->assertSet('registrationMarks', true)
                ->assertSet('combine', true)
                ->assertSet('serialize', true)
                ->assertSet('whiteDirs', [$dir.'/WHITE'])
                ->assertSet('treatments', ['Holo Rainbow'])
                ->assertSee('Holo Rainbow');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
