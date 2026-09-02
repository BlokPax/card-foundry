<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A new set needs a home. The wizard asks for the SET FOLDER — the
 * directory that IS the project (it receives .foundry/, run history, and
 * usually holds the art) — defaulting to the merge file's folder when the
 * merge is local. A sheet-linked merge has no folder, so the choice is
 * explicit.
 */
class WizardSetFolderTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();

        $this->dir = sys_get_temp_dir().'/wotf-home-'.uniqid();
        mkdir($this->dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $this->dir.'/merge.csv');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    public function test_a_local_merge_prefills_the_set_folder(): void
    {
        Livewire::test(ControlPlane::class)
            ->call('startCreateSet')
            ->set('mergePath', $this->dir.'/merge.csv')
            ->call('createSetContinue')
            ->assertSet('setFolder', $this->dir);
    }

    public function test_finishing_the_wizard_creates_the_set_in_the_chosen_folder(): void
    {
        Http::fake([
            'docs.google.com/*' => Http::response("Name,Type,Card Code,Set Name,@Image\nAlpha,Wonder,1/398,Picked Set,TBC001\n"),
        ]);

        $home = $this->dir.'/Picked Set';

        Livewire::test(ControlPlane::class)
            ->call('startCreateSet')
            ->set('mergePath', 'https://docs.google.com/spreadsheets/d/sheet-77/edit')
            ->call('createSetContinue')
            ->assertSet('setFolder', '') // a sheet merge has no folder to infer
            ->set('setFolder', $home)    // may not exist yet — it gets created
            ->call('createSetFinish')
            ->assertSet('showCreateWizard', false)
            ->assertSet('manifestPath', $home.'/.foundry/config.json');

        $data = json_decode(file_get_contents($home.'/.foundry/config.json'), true);
        $this->assertSame('Picked Set', $data['set']);
        $this->assertSame('https://docs.google.com/spreadsheets/d/sheet-77/edit', $data['base']['merge']);
    }

    public function test_a_folder_that_already_holds_a_set_is_refused(): void
    {
        mkdir($this->dir.'/.foundry');
        file_put_contents($this->dir.'/.foundry/config.json', json_encode([
            'version' => 2, 'set' => 'Existing', 'base' => [], 'profiles' => [],
        ]));

        $component = Livewire::test(ControlPlane::class)
            ->call('startCreateSet')
            ->set('mergePath', $this->dir.'/merge.csv')
            ->call('createSetContinue')
            ->set('setName', 'Clobber Attempt')
            ->call('createSetFinish')
            ->assertSet('showCreateWizard', true); // still open — nothing saved

        $this->assertStringContainsString('already holds a set', (string) $component->get('flash'));
        $this->assertSame('Existing', json_decode(file_get_contents($this->dir.'/.foundry/config.json'), true)['set']);
    }

    public function test_without_a_folder_the_wizard_asks_for_one(): void
    {
        Http::fake([
            'docs.google.com/*' => Http::response("Name,Type,Card Code,Set Name,@Image\nAlpha,Wonder,1/398,TBC,TBC001\n"),
        ]);

        $component = Livewire::test(ControlPlane::class)
            ->call('startCreateSet')
            ->set('mergePath', 'https://docs.google.com/spreadsheets/d/sheet-77/edit')
            ->call('createSetContinue')
            ->set('setName', 'TBC')
            ->call('createSetFinish')
            ->assertSet('showCreateWizard', true);

        $this->assertStringContainsString('folder', strtolower((string) $component->get('flash')));
    }
}
