<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ControlPlaneFoundryTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();

        $this->dir = sys_get_temp_dir().'/wotf-cpf-'.uniqid();
        mkdir($this->dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $this->dir.'/merge.csv');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_new_manifest_is_created_in_the_foundry_layout(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('mergePath', $this->dir.'/merge.csv')
            ->call('saveToManifest')
            ->assertSet('manifestPath', $this->dir.'/.foundry/config.json');

        $this->assertFileExists($this->dir.'/.foundry/config.json');
        $this->assertFileDoesNotExist($this->dir.'/foundry.json');
        // The save itself is already in the version history.
        $this->assertNotEmpty(glob($this->dir.'/.foundry/versions/config-*.json'));
    }

    public function test_saving_a_legacy_manifest_migrates_it_into_foundry(): void
    {
        file_put_contents($this->dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => ['merge' => 'merge.csv'],
            'profiles' => [],
        ]));

        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('proof', false)
            ->call('saveToManifest')
            ->assertSet('manifestPath', $this->dir.'/.foundry/config.json');

        $this->assertFileDoesNotExist($this->dir.'/foundry.json');
        $data = json_decode(file_get_contents($this->dir.'/.foundry/config.json'), true);
        $this->assertSame('TBC', $data['set']);
        // Relative paths survive the move — resolution is set-folder based.
        $this->assertSame('merge.csv', $data['base']['merge']);
        $this->assertFalse($data['base']['proof']);
    }

    public function test_opening_the_set_folder_itself_loads_the_config(): void
    {
        file_put_contents($this->dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => ['merge' => 'merge.csv'],
            'profiles' => [],
        ]));

        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir)
            ->assertSet('setName', 'TBC')
            ->assertSet('mergePath', $this->dir.'/merge.csv');
    }

    public function test_a_config_changed_on_disk_blocks_the_save(): void
    {
        file_put_contents($this->dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => ['merge' => 'merge.csv'],
            'profiles' => [],
        ]));

        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json');

        // Someone else edits the file after we loaded it…
        file_put_contents($this->dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'TBC edited elsewhere',
            'base' => ['merge' => 'merge.csv'],
            'profiles' => [],
        ]));

        $component->set('proof', false)
            ->call('saveToManifest')
            ->assertSet('showManifestChanged', true);

        // Their edit survives; ours was not written.
        $data = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame('TBC edited elsewhere', $data['set']);
        $this->assertArrayNotHasKey('proof', $data['base']);
    }
}
