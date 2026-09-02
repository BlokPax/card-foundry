<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ControlPlanePreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
    }

    public function test_preferences_panel_opens_and_closes(): void
    {
        Livewire::test(ControlPlane::class)
            ->assertSet('showPreferences', false)
            ->call('openPreferences')
            ->assertSet('showPreferences', true)
            ->assertSee('Preferences')
            ->set('showPreferences', false)
            ->assertSet('showPreferences', false);
    }

    public function test_editor_mode_defaults_on_and_persists(): void
    {
        Livewire::test(ControlPlane::class)
            ->assertSet('editorMode', true)
            ->set('editorMode', false);

        $this->assertFalse(Settings::get('prefs.editorMode'));

        Livewire::test(ControlPlane::class)->assertSet('editorMode', false);
    }

    public function test_editor_mode_off_blocks_config_saves(): void
    {
        $dir = sys_get_temp_dir().'/wotf-prefs-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv'], 'profiles' => [],
        ]));
        $before = file_get_contents($dir.'/foundry.json');

        try {
            $component = Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->set('editorMode', false)
                ->set('proof', false)
                ->call('saveToManifest');

            $this->assertStringContainsString('Preferences', (string) $component->get('flash'));
            // Nothing written, nothing migrated.
            $this->assertSame($before, file_get_contents($dir.'/foundry.json'));
            $this->assertFileDoesNotExist($dir.'/.foundry/config.json');

            // Per-run tweaks (not saved to the file) stay available.
            $component->assertSet('proof', false);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_editor_mode_off_blocks_profile_deletion(): void
    {
        $dir = sys_get_temp_dir().'/wotf-prefs-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv'],
            'profiles' => ['preview' => []],
        ]));

        try {
            Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->set('editorMode', false)
                ->set('profile', 'preview')
                ->call('deleteProfile');

            $this->assertArrayHasKey(
                'preview',
                json_decode(file_get_contents($dir.'/foundry.json'), true)['profiles'],
            );
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_google_connection_shows_the_identity_and_disconnects(): void
    {
        Settings::put('google.refreshToken', 'refresh-1');
        Settings::put('google.email', 'jim@example.com');

        Livewire::test(ControlPlane::class)
            ->call('openPreferences')
            ->assertSet('googleEmail', 'jim@example.com')
            ->assertSee('jim@example.com')
            ->call('disconnectGoogle')
            ->assertSet('googleEmail', '');

        $this->assertNull(Settings::get('google.refreshToken'));
    }
}
