<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeLivewire(ControlPlane::class);
    }

    public function test_choosing_a_merge_file_parses_and_lints_it(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
            ->assertSet('summary.cards', 5)
            ->assertSet('summary.set', 'Trial by Combat')
            ->assertSee('Aetheris AE 1')
            ->assertSee('Trial by Combat');
    }

    public function test_invalid_merge_path_shows_parse_error(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('mergePath', '/nope/missing.csv')
            ->assertSet('cards', [])
            ->assertSee('not found');
    }

    public function test_a_chosen_path_can_be_cleared(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
            ->assertSet('summary.cards', 5)
            ->call('clearPath', 'mergePath')
            ->assertSet('mergePath', '')
            ->assertSet('cards', []);
    }

    public function test_art_directories_are_ordered_and_removable(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
            ->set('newArtDir', '/tmp/a')
            ->call('addArtDir')
            ->set('newArtDir', '/tmp/b')
            ->call('addArtDir')
            ->assertSet('artDirs', ['/tmp/a', '/tmp/b']);

        $component->call('moveArtDir', 1, -1)
            ->assertSet('artDirs', ['/tmp/b', '/tmp/a']);

        $component->call('removeArtDir', 0)
            ->assertSet('artDirs', ['/tmp/a']);
    }

    public function test_choosing_a_manifest_prefills_fields_and_profiles_switch_them(): void
    {
        $dir = sys_get_temp_dir().'/wotf-ui-manifest-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => ['merge' => 'merge.csv', 'kit' => '.', 'template' => 'merge.csv', 'artDirs' => ['cmyk'], 'export' => ['format' => 'pdf']],
            'profiles' => ['preview' => ['artDirs' => ['rgb'], 'export' => ['format' => 'png', 'ppi' => 144], 'proof' => false]],
        ]));

        try {
            $component = Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->assertSet('setName', 'Trial by Combat')
                ->assertSet('profileNames', ['preview'])
                ->assertSet('mergePath', $dir.'/merge.csv')
                ->assertSet('artDirs', [$dir.'/cmyk'])
                ->assertSet('format', 'pdf')
                ->assertSet('summary.cards', 5);

            $component->set('profile', 'preview')
                ->assertSet('artDirs', [$dir.'/rgb'])
                ->assertSet('format', 'png')
                ->assertSet('ppi', 144)
                ->assertSet('proof', false);

            // Fields remain editable overrides after applying a profile.
            $component->set('format', 'pdf')->assertSet('format', 'pdf');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_save_as_profile_modal_saves_activates_and_collapses(): void
    {
        $dir = sys_get_temp_dir().'/wotf-ui-modal-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv'], 'profiles' => [],
        ]));

        try {
            Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->set('showAdvanced', true)
                ->call('openProfileModal')
                ->assertSet('showProfileModal', true)
                ->set('newProfileName', 'social')
                ->call('saveAsProfile')
                ->assertSet('showProfileModal', false)
                ->assertSet('profile', 'social')
                ->assertSet('showAdvanced', true); // lands in the setup view to configure the new profile

            $raw = json_decode(file_get_contents($dir.'/.foundry/config.json'), true); // saves migrate legacy configs into .foundry/
            $this->assertArrayHasKey('social', $raw['profiles']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_save_to_manifest_writes_the_profile(): void
    {
        $dir = sys_get_temp_dir().'/wotf-ui-save-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv'], 'profiles' => [],
        ]));

        try {
            Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->set('format', 'png')
                ->set('ppi', 200)
                ->set('newProfileName', 'social')
                ->call('saveToManifest')
                ->assertSet('profile', 'social');

            $raw = json_decode(file_get_contents($dir.'/.foundry/config.json'), true); // saves migrate legacy configs into .foundry/
            $this->assertSame('png', $raw['profiles']['social']['export']['format']);
            $this->assertSame(200, $raw['profiles']['social']['export']['ppi']);
            // Profiles store overrides only — the inherited merge path is NOT snapshotted.
            $this->assertArrayNotHasKey('merge', $raw['profiles']['social']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_defaults_to_cards_tab_with_advanced_open_when_no_manifest(): void
    {
        Livewire::test(ControlPlane::class)
            ->assertSet('tab', 'cards')
            ->assertSet('showAdvanced', true);
    }

    public function test_choosing_a_manifest_collapses_advanced(): void
    {
        $dir = sys_get_temp_dir().'/wotf-ui-adv-'.uniqid();
        mkdir($dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/foundry.json', json_encode([
            'version' => 1, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv'], 'profiles' => [],
        ]));

        try {
            Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/foundry.json')
                ->assertSet('showAdvanced', false)
                ->set('manifestPath', '')
                ->assertSet('showAdvanced', true);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_create_set_wizard_clears_context_and_gates_on_merge(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
            ->call('startCreateSet')
            ->assertSet('showCreateWizard', true)
            ->assertSet('createStep', 1)
            ->assertSet('mergePath', '')
            ->assertSet('manifestPath', '')
            ->assertSet('cards', [])
            ->call('createSetContinue')
            ->assertSet('createStep', 1) // no merge file yet
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
            ->call('createSetContinue')
            ->assertSet('createStep', 2);
    }

    public function test_logs_modal_lists_files_and_shows_tail(): void
    {
        $path = config('logging.channels.commands.path');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, implode("\n", array_map(fn ($i) => "line {$i}", range(1, 30)))."\n");

        try {
            $component = Livewire::test(ControlPlane::class)
                ->call('openLogsModal')
                ->assertSet('showLogsModal', true)
                ->set('logFile', $path)
                ->assertSee('commands.log')
                ->assertSee('line 30')
                ->assertDontSee('line 4'); // only the last 25 lines

            // Arbitrary paths outside the log list are refused.
            $component->set('logFile', '/etc/hosts');
            $this->assertSame([], $component->instance()->logLines());
        } finally {
            @unlink($path);
        }
    }

    public function test_card_detail_modal_shows_issues_and_art_search(): void
    {
        $art = sys_get_temp_dir().'/wotf-detail-'.uniqid();
        mkdir($art);

        try {
            Livewire::test(ControlPlane::class)
                ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
                ->set('newArtDir', $art)
                ->call('addArtDir')
                ->call('openCardDetail', 'TBC331')
                ->assertSet('detailRef', 'TBC331')
                ->assertSee('Aetheris AE 1')
                ->assertSee('Artwork search')
                ->assertSee('Open in Finder')
                ->set('detailRef', null)
                ->assertDontSee('Artwork search');
        } finally {
            @rmdir($art);
        }
    }

    public function test_card_list_loads_incrementally(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'));

        $this->assertSame(100, $component->get('visibleLimit'));

        $component->call('loadMore')->assertSet('visibleLimit', 200)
            ->set('search', 'ash')->assertSet('visibleLimit', 100);
    }

    public function test_build_is_blocked_without_a_live_indesign_connection(): void
    {
        $dir = sys_get_temp_dir().'/wotf-gate-'.uniqid();
        mkdir($dir);
        touch($dir.'/wotfLib.jsxinc');
        touch($dir.'/t.indd');

        try {
            Livewire::test(ControlPlane::class)
                ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
                ->set('kitPath', $dir)
                ->set('templatePath', $dir.'/t.indd')
                ->set('destDir', $dir)
                ->set('artDirs', [$dir])
                ->set('bridge', ['installed' => true, 'up' => true, 'indesign' => null, 'detail' => null])
                ->set('overrideLint', true)
                ->call('go')
                ->assertSet('runId', null)
                ->assertSee("isn't connected");
        } finally {
            @unlink($dir.'/wotfLib.jsxinc');
            @unlink($dir.'/t.indd');
            @rmdir($dir);
        }
    }

    public function test_go_requires_valid_inputs(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
            ->call('go')
            ->assertSet('runId', null)
            ->assertSee('Set a valid');
    }

    /**
     * The pill's click target is its own method so the poll and the click can
     * carry different wire:loading targets — that's what lets the green state
     * poll silently while a click always spins.
     */
    public function test_manual_refresh_bridge_refreshes_the_bridge_state(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('bridge', ['installed' => true, 'up' => true, 'indesign' => true, 'detail' => 'stale'])
            ->call('manualRefreshBridge')
            ->assertOk();

        $this->assertSame(
            ['installed', 'up', 'indesign', 'detail'],
            array_keys($component->get('bridge')),
        );
    }

    /**
     * A healthy bridge polls silently: only the click sentinel is rendered,
     * so wire:loading never reveals a spinner for the 5s poll. Any unhealthy
     * state renders the poll sentinel too, and the poll does spin.
     */
    public function test_the_poll_spinner_sentinel_is_rendered_only_when_the_bridge_is_unhealthy(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('bridge', ['installed' => true, 'up' => true, 'indesign' => true, 'detail' => null])
            ->assertSee('wire:target="manualRefreshBridge"', false)
            ->assertDontSee('wire:key="bridge-poll-sentinel"', false);

        Livewire::test(ControlPlane::class)
            ->set('bridge', ['installed' => true, 'up' => true, 'indesign' => false, 'detail' => null])
            ->assertSee('wire:target="manualRefreshBridge"', false)
            ->assertSee('wire:key="bridge-poll-sentinel"', false);
    }

    /**
     * The 5s background check runs through its own action so a quiet poll can
     * skip re-rendering the (large) component — clicks landing mid-poll then
     * have nothing heavy to queue behind.
     */
    public function test_ppi_can_be_erased_without_erroring(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('format', 'png')
            ->set('ppi', '')
            ->assertOk()
            ->assertSet('ppi', null);

        // A relaunch restores the default rather than coming back as 0.
        Livewire::test(ControlPlane::class)->assertSet('ppi', 300);
    }

    public function test_building_png_without_a_ppi_is_blocked(): void
    {
        Livewire::test(ControlPlane::class)
            ->set('format', 'png')
            ->set('ppi', '')
            ->call('go')
            ->assertSet('runId', null)
            ->assertSee('PPI');
    }

    public function test_missing_artwork_offers_details_via_chip_and_question_icon(): void
    {
        $dir = sys_get_temp_dir().'/wotf-noart-'.uniqid();
        mkdir($dir);

        try {
            // Both the chip and the ?-icon open the card-detail modal.
            // (flux:icon names compile to inline SVGs, so assert the hooks.)
            Livewire::test(ControlPlane::class)
                ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
                ->set('newArtDir', $dir)
                ->call('addArtDir')
                ->assertSeeHtml('artwork not found</span>')
                ->assertSeeHtml('title="See what was searched, and where"')
                ->assertSeeHtml('wire:click.stop="openCardDetail(');
        } finally {
            @rmdir($dir);
        }
    }

    /**
     * Prev/next in the preview and detail modals walks the visible card
     * list, showing whichever modal fits the card: matched artwork gets the
     * preview, a missing-artwork card gets the detail view.
     */
    public function test_modal_navigation_steps_through_cards_picking_the_right_modal(): void
    {
        $dir = sys_get_temp_dir().'/wotf-nav-'.uniqid();
        mkdir($dir);
        // Card order: TBC331, TBC060, TBC325, TBC197, TBC391.
        touch($dir.'/TBC331.psd');
        touch($dir.'/TBC325.psd');

        try {
            Livewire::test(ControlPlane::class)
                ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
                ->set('newArtDir', $dir)
                ->call('addArtDir')
                ->call('openArtPreview', 'TBC331')
                ->assertSee('Card 1 of 5')
                ->call('navigateCard', 1) // TBC060 has no art → detail modal
                ->assertSet('artPreviewRef', null)
                ->assertSet('detailRef', 'TBC060')
                ->assertSee('Card 2 of 5')
                ->call('navigateCard', 1) // TBC325 has art → preview modal
                ->assertSet('artPreviewRef', 'TBC325')
                ->assertSet('detailRef', null)
                ->call('navigateCard', -1) // back to the detail card
                ->assertSet('detailRef', 'TBC060')
                ->call('navigateCard', -1) // TBC331 (first)
                ->assertSet('artPreviewRef', 'TBC331')
                ->call('navigateCard', -1) // already first — stays put
                ->assertSet('artPreviewRef', 'TBC331');
        } finally {
            @unlink($dir.'/TBC331.psd');
            @unlink($dir.'/TBC325.psd');
            @rmdir($dir);
        }
    }

    public function test_modal_navigation_follows_the_active_filter(): void
    {
        $dir = sys_get_temp_dir().'/wotf-nav-'.uniqid();
        mkdir($dir);

        try {
            // "un" matches TBC060 and TBC197 only — next skips the others.
            Livewire::test(ControlPlane::class)
                ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'))
                ->set('newArtDir', $dir)
                ->call('addArtDir')
                ->set('search', 'un')
                ->call('openCardDetail', 'TBC060')
                ->assertSee('Card 1 of 2')
                ->call('navigateCard', 1)
                ->assertSet('detailRef', 'TBC197')
                ->assertSee('Card 2 of 2');
        } finally {
            @rmdir($dir);
        }
    }

    /**
     * A card whose artwork resolved through the suffix fallback still gets a
     * preview — but the row indicator turns into a warning, and the preview
     * modal says which file actually matched.
     */
    public function test_fallback_artwork_warns_in_the_row_and_the_preview_modal(): void
    {
        $dir = sys_get_temp_dir().'/wotf-fb-'.uniqid();
        mkdir($dir);
        touch($dir.'/TBC900.png'); // merge will ask for TBC900.jpg

        $csv = $dir.'/merge.csv';
        file_put_contents($csv, implode("\n", [
            'Name,Type,Card Code,Set Name,@Image',
            'Fallback Card,Wonder,1/1,Trial by Combat,TBC900.jpg',
        ])."\n");

        try {
            Livewire::test(ControlPlane::class)
                ->set('mergePath', $csv)
                ->set('newArtDir', $dir)
                ->call('addArtDir')
                ->assertSeeHtml('Artwork found in a different format')
                ->call('openArtPreview', 'TBC900.jpg')
                ->assertSee('TBC900.png')
                ->assertSee('different format');
        } finally {
            @unlink($dir.'/TBC900.png');
            @unlink($csv);
            @rmdir($dir);
        }
    }

    public function test_bridge_action_buttons_sit_before_the_logs_button(): void
    {
        $html = Livewire::test(ControlPlane::class)
            ->set('bridge', ['installed' => true, 'up' => false, 'indesign' => null, 'detail' => null])
            ->html();

        $this->assertLessThan(
            strpos($html, 'openLogsModal'),
            strpos($html, 'startBridge'),
            'Start bridge should render before the logs button',
        );
    }

    /**
     * Clicking a card row in the run panel filters the log pane down to that
     * card's lines; clicking it again clears the filter.
     */
    public function test_run_item_click_filters_the_log_to_that_card(): void
    {
        $run = \App\Models\Run::create([
            'status' => 'running',
            'merge_path' => '/m.csv',
            'art_dirs' => ['/a'],
            'template_path' => '/t.indd',
            'output_dir' => '/out',
            'total' => 2,
        ]);
        $run->items()->create(['name' => 'Ashbound', 'image_ref' => 'TBC197.jpg', 'payload' => ['ref' => 'TBC197.jpg'], 'status' => 'done']);
        $run->items()->create(['name' => 'Aetheris', 'image_ref' => 'TBC331.jpg', 'payload' => ['ref' => 'TBC331.jpg'], 'status' => 'failed']);
        $run->appendLog('✓ Ashbound (TBC197.jpg) — 3 stages');
        $run->appendLog('  ⚠ TBC197.jpg storyTokenFeather: skipped');
        $run->appendLog('✗ Aetheris (TBC331.jpg): Treatment layer not found');

        Livewire::test(ControlPlane::class)
            ->set('runId', $run->id)
            ->call('focusRunItem', 'TBC197.jpg')
            ->assertSet('activityFocusRef', 'TBC197.jpg')
            ->assertSee('storyTokenFeather')
            ->assertDontSee('Treatment layer not found')
            ->call('focusRunItem', 'TBC197.jpg') // toggle off
            ->assertSet('activityFocusRef', null)
            ->assertSee('Treatment layer not found');
    }

    public function test_poll_bridge_refreshes_the_bridge_state(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->set('bridge', ['installed' => true, 'up' => true, 'indesign' => true, 'detail' => 'stale'])
            ->call('pollBridge')
            ->assertOk();

        $this->assertSame(
            ['installed', 'up', 'indesign', 'detail'],
            array_keys($component->get('bridge')),
        );
    }

    public function test_the_pill_polls_via_poll_bridge(): void
    {
        Livewire::test(ControlPlane::class)
            ->assertSee('wire:poll.5s="pollBridge"', false)
            ->assertDontSee('wire:poll.5s="refreshBridge"', false);
    }
}
