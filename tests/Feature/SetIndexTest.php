<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Manifest\ManifestLoader;
use App\Manifest\ManifestWriter;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wotf-index-'.uniqid();
        mkdir($this->dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $this->dir.'/merge.csv');
        file_put_contents($this->dir.'/cover.png', 'png-bytes');
        file_put_contents($this->dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => ['merge' => 'merge.csv', 'cover' => 'cover.png'],
            'profiles' => ['preview' => []],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_manifest_exposes_a_resolved_cover_path(): void
    {
        $manifest = (new ManifestLoader)->load($this->dir.'/foundry.json');

        $this->assertSame($this->dir.'/cover.png', $manifest->cover());
    }

    public function test_writer_relativizes_the_cover(): void
    {
        (new ManifestWriter)->saveBase($this->dir.'/foundry.json', [
            'cover' => $this->dir.'/cover.png',
        ]);

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame('cover.png', $raw['base']['cover']);
    }

    public function test_opening_a_manifest_records_it_in_recent_sets(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json');

        $recents = Settings::get('recentSets', []);

        $this->assertArrayHasKey($this->dir.'/foundry.json', $recents);
        $entry = $recents[$this->dir.'/foundry.json'];
        $this->assertSame('Trial by Combat', $entry['set']);
        $this->assertSame($this->dir.'/cover.png', $entry['cover']);
        $this->assertSame(5, $entry['cards']);
        // The base config counts: 1 named profile + Default = 2.
        $this->assertSame(2, $entry['profiles']);
    }

    public function test_named_profile_can_be_deleted_from_the_editor(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('profile', 'preview')
            ->call('deleteProfile')
            ->assertSet('profile', '')       // falls back to the Default (base) profile
            ->assertSet('profileNames', []);

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame([], (array) $raw['profiles']);
    }

    public function test_the_default_profile_cannot_be_deleted(): void
    {
        // profile '' = the base config; deleting it is refused.
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->call('deleteProfile')
            ->assertSet('profileNames', ['preview']);

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertArrayHasKey('preview', $raw['profiles']);
    }

    // ── The manifest is authoritative: startup, reload, external edits ───

    public function test_startup_applies_the_manifest_not_stale_settings(): void
    {
        // Last session remembered values from a profile that no longer
        // exists — the file on disk wins when the app comes back up.
        Settings::put('paths.manifestPath', $this->dir.'/foundry.json');
        Settings::put('paths.mergePath', '/tmp/stale-from-last-session.csv');
        Settings::put('paths.profile', 'deleted-by-hand');

        Livewire::test(ControlPlane::class)
            ->assertSet('profile', '')
            ->assertSet('mergePath', $this->dir.'/merge.csv');
    }

    public function test_focus_check_notices_external_manifest_edits(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->call('checkManifestFreshness')
            ->assertSet('showManifestChanged', false);

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $raw['set'] = 'Edited Outside';
        file_put_contents($this->dir.'/foundry.json', json_encode($raw));

        $component->call('checkManifestFreshness')
            ->assertSet('showManifestChanged', true)
            ->call('reloadManifest')
            ->assertSet('showManifestChanged', false)
            ->assertSet('setName', 'Edited Outside');
    }

    public function test_manifest_change_prompt_can_be_dismissed(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json');

        file_put_contents($this->dir.'/foundry.json', json_encode(
            ['version' => 1, 'set' => 'Changed', 'base' => ['merge' => 'merge.csv']],
        ));

        $component->call('checkManifestFreshness')
            ->assertSet('showManifestChanged', true)
            ->call('ackManifestChange')
            ->assertSet('showManifestChanged', false)
            // acknowledged: the same change doesn't re-prompt
            ->call('checkManifestFreshness')
            ->assertSet('showManifestChanged', false);
    }

    public function test_header_offers_a_manifest_reload_button(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->assertSeeHtml('wire:click="reloadManifest"');
    }

    public function test_set_name_is_editable_from_the_setup(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->assertSet('setName', 'Trial by Combat')
            ->set('setName', 'TBC Reborn')
            ->call('saveToManifest');

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame('TBC Reborn', $raw['set']);

        // The Sets-screen tile follows the rename.
        $entry = Settings::get('recentSets', [])[$this->dir.'/foundry.json'];
        $this->assertSame('TBC Reborn', $entry['set']);
    }

    public function test_display_name_prefers_the_stored_set_name(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('setName', 'Renamed');

        $this->assertSame('Renamed', $component->instance()->setDisplayName());
    }

    public function test_create_wizard_prefills_the_set_name_from_the_merge(): void
    {
        Livewire::test(ControlPlane::class)
            ->call('startCreateSet')
            ->set('mergePath', $this->dir.'/merge.csv')
            ->call('createSetContinue')
            ->assertSet('createStep', 2)
            ->assertSet('setName', 'Trial by Combat');
    }

    public function test_saving_a_new_set_defaults_the_name_from_the_merge(): void
    {
        // No manifest yet: saving creates one next to the merge file, and the
        // set name comes from the merge's Set Name column — not the folder.
        unlink($this->dir.'/foundry.json');

        Livewire::test(ControlPlane::class)
            ->set('mergePath', $this->dir.'/merge.csv')
            ->call('saveToManifest');

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame('Trial by Combat', $raw['set']);
    }

    public function test_setup_footer_shows_the_manifest_file_with_finder_access(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            // click-to-copy token carrying the absolute path…
            ->assertSeeHtml('data-path="'.$this->dir.'/foundry.json"')
            // …and a folder button to reveal it in Finder
            ->assertSeeHtml('title="Show foundry.json in Finder"');
    }

    public function test_setup_panel_offers_a_set_name_input(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->assertSeeHtml('wire:model.blur="setName"');
    }

    public function test_default_profile_is_renamable_from_the_editor(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->assertSet('defaultProfileName', 'Default')
            ->assertSet('editProfileName', 'Default')
            ->set('editProfileName', 'Production')
            ->call('saveToManifest')
            ->assertSet('defaultProfileName', 'Production');

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame('Production', $raw['defaultProfileName']);
    }

    public function test_named_profile_is_renamable_from_the_editor(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('profile', 'preview')
            ->set('editProfileName', 'web')
            ->call('saveToManifest')
            ->assertSet('profile', 'web')
            ->assertSet('profileNames', ['web']);
    }

    public function test_new_profile_option_reverts_selection_and_opens_modal(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('profile', 'preview')
            ->set('profile', '__new__')
            ->assertSet('profile', 'preview')
            ->assertSet('showProfileModal', true);
    }

    public function test_open_set_from_the_index_switches_to_cards(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('tab', 'sets')
            ->call('openSet', $this->dir.'/foundry.json')
            ->assertSet('tab', 'cards')
            ->assertSet('setName', 'Trial by Combat')
            ->assertSet('summary.cards', 5);
    }

    public function test_forget_set_removes_it_from_the_index(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->call('forgetSet', $this->dir.'/foundry.json');

        $this->assertArrayNotHasKey($this->dir.'/foundry.json', Settings::get('recentSets', []));
    }

    public function test_activity_scopes_to_the_current_set_with_an_all_sets_toggle(): void
    {
        $make = fn (string $set, ?string $manifest) => \App\Models\Run::create([
            'status' => 'completed', 'set_name' => $set, 'manifest_path' => $manifest,
            'merge_path' => '/m.csv', 'art_dirs' => ['/a'], 'template_path' => '/t.indd',
            'output_dir' => '/out', 'total' => 1,
        ]);
        $make('Trial by Combat', $this->dir.'/foundry.json');
        $make('Other Set', '/elsewhere/foundry.json');

        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('tab', 'activity');

        $this->assertCount(1, $component->viewData('recentRuns'));
        $this->assertSame('Trial by Combat', $component->viewData('recentRuns')->first()->set_name);

        $component->set('activityAllSets', true);
        $this->assertCount(2, $component->viewData('recentRuns'));

        // No set open → everything shows.
        $component->set('activityAllSets', false)->call('clearPath', 'manifestPath');
        $this->assertCount(2, $component->viewData('recentRuns'));
    }

    public function test_data_uri_covers_pass_through_the_manifest_unresolved(): void
    {
        $uri = 'data:image/jpeg;base64,'.base64_encode('fake-jpeg-bytes');
        file_put_contents($this->dir.'/foundry.json', json_encode([
            'version' => 1, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv', 'cover' => $uri], 'profiles' => [],
        ]));

        $this->assertSame($uri, (new ManifestLoader)->load($this->dir.'/foundry.json')->cover());

        // The writer must not mangle a data URI while relativizing paths.
        (new ManifestWriter)->saveBase($this->dir.'/foundry.json', ['cover' => $uri]);
        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame($uri, $raw['base']['cover']);
    }

    public function test_cover_always_saves_to_base_even_when_editing_a_profile(): void
    {
        $uri = 'data:image/jpeg;base64,'.base64_encode('cropped');

        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json')
            ->set('profile', 'preview')
            ->set('coverPath', $uri)
            ->call('saveToManifest');

        $raw = json_decode(file_get_contents($this->dir.'/foundry.json'), true);
        $this->assertSame($uri, $raw['base']['cover']);
        $this->assertArrayNotHasKey('cover', $raw['profiles']['preview']);
    }

    public function test_dropped_paths_route_to_the_right_fields(): void
    {
        $art = $this->dir.'/art';
        mkdir($art);

        try {
            $component = Livewire::test(ControlPlane::class)
                ->set('manifestPath', $this->dir.'/foundry.json')
                ->call('setPathFromDrop', 'artDirs', $art.'/')
                ->assertSet('artDirs', [$art])
                ->call('setPathFromDrop', 'mergePath', $this->dir.'/merge.csv')
                ->assertSet('mergePath', $this->dir.'/merge.csv');

            // A file dropped on a folder field is refused with guidance.
            $component->call('setPathFromDrop', 'kitPath', $this->dir.'/merge.csv')
                ->assertSet('kitPath', '')
                ->assertSee('build-kit folder');

            // Portability: inside the manifest's tree → relative; outside → null.
            $this->assertSame('art', $component->instance()->portableDisplay($art));
            $this->assertNull($component->instance()->portableDisplay('/somewhere/else/entirely'));
        } finally {
            @rmdir($art);
        }
    }

    public function test_local_image_route_serves_registered_covers(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->dir.'/foundry.json');

        $this->get('/local-image?p='.rtrim(strtr(base64_encode($this->dir.'/cover.png'), '+/', '-_'), '='))
            ->assertOk();

        // A path that is not an image is refused.
        $this->get('/local-image?p='.rtrim(strtr(base64_encode($this->dir.'/foundry.json'), '+/', '-_'), '='))
            ->assertNotFound();
    }
}
