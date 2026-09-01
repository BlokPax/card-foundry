<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ControlPlaneSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function plane()
    {
        return Livewire::test(ControlPlane::class)
            ->set('mergePath', base_path('tests/Fixtures/merge-sample.csv'));
    }

    public function test_defaults_to_cards_tab(): void
    {
        Livewire::test(ControlPlane::class)->assertSet('tab', 'cards');
    }

    public function test_nothing_selected_means_all(): void
    {
        // Selection is edited client-side (Alpine) and synced back as a plain
        // property update — the server derives $mode from whatever arrives.
        $this->plane()
            ->assertSet('mode', 'all')
            ->set('selected', ['TBC331'])
            ->assertSet('mode', 'selected')
            ->set('selected', [])
            ->assertSet('mode', 'all');
    }

    public function test_lint_issues_carry_rule_slugs(): void
    {
        $component = $this->plane();
        $issues = $component->get('issues');

        // Fixture is clean; force one by lint-with-empty-artdir via addArtDir of an empty dir.
        $dir = sys_get_temp_dir().'/wotf-empty-'.uniqid();
        mkdir($dir);

        try {
            $component->set('newArtDir', $dir)->call('addArtDir');
            $issues = $component->get('issues');

            $this->assertNotEmpty($issues);
            $this->assertSame('art-not-found', $issues[0]['rule']);
        } finally {
            @rmdir($dir);
        }
    }

    public function test_filter_by_rule_narrows_to_flagged_cards(): void
    {
        $dir = sys_get_temp_dir().'/wotf-flag-'.uniqid();
        mkdir($dir);
        // Only TBC331 has art; the other four are flagged art-not-found.
        touch($dir.'/TBC331.psd');

        try {
            $component = $this->plane()
                ->set('newArtDir', $dir)
                ->call('addArtDir')
                ->call('filterByRule', 'art-not-found');

            $this->assertSame('art-not-found', $component->get('flagFilter'));
            $refs = $component->instance()->flaggedRefs('art-not-found');
            $this->assertCount(4, $refs);
            $this->assertNotContains('TBC331', $refs);

            // toggling again clears
            $component->call('filterByRule', 'art-not-found')
                ->assertSet('flagFilter', '');
        } finally {
            @unlink($dir.'/TBC331.psd');
            @rmdir($dir);
        }
    }

    public function test_set_display_name_prefers_the_merge_files_set_name(): void
    {
        $component = $this->plane();

        $this->assertSame('Trial by Combat', $component->instance()->setDisplayName());
    }

    // ── Header checkbox ──────────────────────────────────────────────────
    // The click cycle (none → all → none; partial → invert → none) runs
    // client-side in Alpine — resources/js/selection.test.js is its spec.
    // The server derives the rendered state and restarts the invert cycle
    // when the acting set changes under it.

    private const ALL_REFS = ['TBC331', 'TBC060', 'TBC325', 'TBC197', 'TBC391'];

    public function test_selection_state_is_derived_from_the_synced_selection(): void
    {
        $this->plane()
            ->assertSet('selectionState', 'none')
            ->set('selected', ['TBC060'])
            ->assertSet('selectionState', 'partial')
            ->set('selected', self::ALL_REFS)
            ->assertSet('selectionState', 'all');
    }

    public function test_selection_state_is_scoped_to_the_filtered_set(): void
    {
        // "un" matches Ali the Unyielding (TBC060) and Ashbound (TBC197) only.
        $this->plane()
            ->set('selected', ['TBC060', 'TBC197'])
            ->assertSet('selectionState', 'partial')
            ->set('search', 'un')
            ->assertSet('selectionState', 'all');
    }

    public function test_changing_the_search_restarts_the_invert_cycle(): void
    {
        $this->plane()
            ->set('selectionInverted', true)
            ->set('search', 'un')
            ->assertSet('selectionInverted', false);
    }

    public function test_filtering_by_rule_restarts_the_invert_cycle(): void
    {
        $this->plane()
            ->set('selectionInverted', true)
            ->call('filterByRule', 'art-not-found')
            ->assertSet('selectionInverted', false);
    }

    public function test_selection_bar_wires_the_checkbox_and_rows_to_alpine(): void
    {
        $this->plane()
            ->assertSeeHtml('cardSelection')
            ->assertSeeHtml('x-on:click="cycle()"')
            ->assertDontSeeHtml('toggleSelectAll')
            ->assertDontSeeHtml('toggleCard')
            ->assertDontSeeHtml('invertSelection')
            ->assertSee('Showing 5 of 5 cards') // no selection: the existing wording stands
            ->set('selected', ['TBC060'])
            ->assertSee('1 of 5 selected')
            ->set('search', 'un')
            ->assertSee('1 of 2 matching selected');
    }
}
