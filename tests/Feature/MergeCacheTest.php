<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Merge\GoogleSheetFetcher;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Linked-sheet merges cache into <set>/.foundry/cache/ with a content
 * hash: the app loads instantly from the local copy and only re-downloads
 * deliberately (open, explicit refresh, build) — not on every interaction.
 */
class MergeCacheTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://docs.google.com/spreadsheets/d/cache-sheet/edit#gid=0';

    private string $setDir;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();

        $this->setDir = sys_get_temp_dir().'/wotf-cache-'.uniqid();
        mkdir($this->setDir.'/.foundry', 0755, true);
        file_put_contents($this->setDir.'/.foundry/config.json', json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => ['merge' => self::URL],
            'profiles' => [],
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->setDir);
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    private function csv(string $marker = 'Alpha'): string
    {
        return "Name,Type,Card Code,Set Name,@Image\n{$marker},Wonder,1/398,TBC,TBC331\n";
    }

    // ── Fetcher-level ───────────────────────────────────────────────────

    public function test_a_cache_dir_holds_the_download_and_its_hash_meta(): void
    {
        Http::fake(['docs.google.com/*' => Http::response($this->csv())]);

        $cacheDir = $this->setDir.'/.foundry/cache';
        $path = (new GoogleSheetFetcher)->localize(self::URL, cacheDir: $cacheDir);

        $this->assertSame($cacheDir, dirname($path));

        $meta = json_decode(file_get_contents(substr($path, 0, -4).'.meta.json'), true);
        $this->assertSame(self::URL, $meta['source']);
        $this->assertSame(hash('sha256', $this->csv()), $meta['sha256']);
        $this->assertIsInt($meta['fetchedAt']);
    }

    public function test_cache_first_mode_never_touches_the_network_when_a_copy_exists(): void
    {
        Http::fake(['docs.google.com/*' => Http::response($this->csv())]);

        $fetcher = new GoogleSheetFetcher;
        $cacheDir = $this->setDir.'/.foundry/cache';

        $fetcher->localize(self::URL, cacheDir: $cacheDir);
        $fetcher->localize(self::URL, maxAgeSeconds: PHP_INT_MAX, cacheDir: $cacheDir);
        $fetcher->localize(self::URL, maxAgeSeconds: PHP_INT_MAX, cacheDir: $cacheDir);

        Http::assertSentCount(1);
    }

    // ── App behaviour ───────────────────────────────────────────────────

    public function test_ui_interactions_reuse_the_set_cache_instead_of_redownloading(): void
    {
        Http::fake(['docs.google.com/*' => Http::response($this->csv())]);

        Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->setDir.'/.foundry/config.json')
            ->assertSet('summary.cards', 1)
            // These all re-parse the merge — none should re-download.
            ->set('newArtDir', $this->setDir)
            ->call('addArtDir')
            ->call('parseMerge');

        $this->assertFileExists($this->setDir.'/.foundry/cache/sheet-cache-sheet-0.csv');
        Http::assertSentCount(1);
    }

    public function test_refresh_merge_redownloads_and_reports_the_change(): void
    {
        Http::fake([
            'docs.google.com/*' => Http::sequence()
                ->push($this->csv('Alpha'))
                ->push($this->csv('Beta'))
                ->push($this->csv('Beta')),
        ]);

        $component = Livewire::test(ControlPlane::class)
            ->set('manifestPath', $this->setDir.'/.foundry/config.json');

        $this->assertSame('Alpha', $component->get('cards')[0]['name']);

        $component->call('refreshMerge');
        $this->assertSame('Beta', $component->get('cards')[0]['name']);
        $this->assertStringContainsString('updated', strtolower((string) $component->get('flash')));

        $component->call('refreshMerge');
        $this->assertStringContainsString('unchanged', strtolower((string) $component->get('flash')));
    }
}
