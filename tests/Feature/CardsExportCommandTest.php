<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Models\RunItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CardsExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $artDir;

    private string $celebDir;

    private string $destDir;

    private string $kitDir;

    private string $merge;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/wotf-'.uniqid();
        mkdir($this->artDir = $base.'-art');
        mkdir($this->celebDir = $base.'-celebs');
        mkdir($this->destDir = $base.'-out');

        // A minimal build kit: library, Links, master template.
        mkdir($this->kitDir = $base.'-kit');
        touch($this->kitDir.'/wotfLib.jsxinc');
        mkdir($this->kitDir.'/Links');
        mkdir($this->kitDir.'/builds', 0755, true);
        touch($this->kitDir.'/builds/_MASTER_BUILD_TEMPLATE.indd');

        $this->merge = base_path('tests/Fixtures/merge-sample.csv');

        foreach (['TBC331', 'TBC060', 'TBC325', 'TBC197', 'TBC391'] as $ref) {
            touch($this->artDir.'/'.$ref.'.psd');
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->artDir, $this->celebDir, $this->destDir, $this->kitDir] as $dir) {
            File::deleteDirectory($dir);
        }
        File::deleteDirectory(storage_path('app/runs'));

        parent::tearDown();
    }

    private function fakeBridgeOk(): void
    {
        Http::fake([
            '127.0.0.1:3000/*' => Http::response([
                'result' => '{"ok":true,"stages":[{"name":"lbAndItalics","lines":["ok"],"warnings":[]}],"pdf":"/out/x.pdf"}',
            ]),
        ]);
    }

    private function baseArgs(array $extra = []): array
    {
        return array_merge([
            '--merge' => $this->merge,
            '--art' => [$this->artDir],
            '--dest' => $this->destDir,
            '--kit' => $this->kitDir,
        ], $extra);
    }

    public function test_builds_a_single_card_per_card_pipeline(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331']))
            ->assertExitCode(0);

        $run = Run::first();
        $this->assertSame('completed', $run->status);
        $this->assertSame('Base Paper', $run->treatment);
        $this->assertTrue($run->proof);
        $this->assertSame($this->kitDir, $run->kit_path);
        $this->assertSame([$this->artDir], $run->art_dirs);
        $this->assertSame('done', $run->items->first()->status);

        // The build script references this card's own one-row CSV.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($r->body(), 'TBC331.csv'));

        // And that CSV carries the card with an absolute art path.
        $csv = glob(storage_path('app/runs/*/TBC331.csv'))[0] ?? null;
        $this->assertNotNull($csv);
        $content = file_get_contents($csv);
        $this->assertStringContainsString('Aetheris AE 1', $content);
        $this->assertStringContainsString($this->artDir.'/TBC331.psd', $content);
    }

    public function test_builds_all_cards_as_independent_items(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs(['--all' => true]))
            ->assertExitCode(0);

        $this->assertSame(5, RunItem::where('status', 'done')->count());
        // ping + 5 per-card builds
        Http::assertSentCount(6);
    }

    public function test_art_found_in_any_listed_directory(): void
    {
        $this->fakeBridgeOk();

        rename($this->artDir.'/TBC331.psd', $this->celebDir.'/TBC331.psd');

        $this->artisan('cards:export', $this->baseArgs([
            '--art' => [$this->artDir, $this->celebDir],
            '--cards' => 'TBC331',
        ]))->assertExitCode(0);
    }

    public function test_treatment_selects_the_matching_layer(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331', '--treatment' => 'Stonefoil']))
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($r->body(), 'Stone Foil'));
    }

    public function test_unknown_treatment_fails(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331', '--treatment' => 'Holo Rainbow']))
            ->assertExitCode(1);
    }

    public function test_lint_errors_on_unselected_cards_do_not_block(): void
    {
        $this->fakeBridgeOk();

        unlink($this->artDir.'/TBC197.psd'); // not part of the selection

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331,TBC060']))
            ->assertExitCode(0);

        $this->assertSame(2, RunItem::where('status', 'done')->count());
    }

    public function test_lint_errors_on_selected_cards_block_without_force(): void
    {
        $this->fakeBridgeOk();

        unlink($this->artDir.'/TBC331.psd');

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331']))
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/execute'));
        $this->assertSame(0, Run::count());
    }

    public function test_dry_run_lints_without_building(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs(['--dry-run' => true]))
            ->assertExitCode(0);

        $this->assertSame(0, Run::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/execute'));
    }

    public function test_bridge_report_failure_marks_item_failed(): void
    {
        Http::fake([
            '127.0.0.1:3000/execute' => Http::sequence()
                ->push(['result' => 'pong: fake'])
                ->push(['result' => '{"ok":false,"stages":[{"name":"orbitalParents","warnings":["No parent found"]}],"error":"Treatment layer not found: Stone Foil"}']),
        ]);

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331']))
            ->assertExitCode(1);

        $item = RunItem::first();
        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('Treatment layer not found', $item->error);
        $this->assertSame('failed', Run::first()->status);
    }

    /**
     * Step warnings ("no feather layer — step skipped") are template
     * quirks, not failures: the export completed. The item finishes as done
     * with the warnings kept on it for the UI.
     */
    public function test_stage_warnings_do_not_fail_a_completed_build(): void
    {
        Http::fake([
            '127.0.0.1:3000/execute' => Http::sequence()
                ->push(['result' => 'pong: fake'])
                ->push(['result' => '{"ok":true,"stages":[{"name":"storyTokenFeather","lines":[],"warnings":["No \"feather\" layer in this document -- step skipped."]}],"pdf":"/out/x.pdf"}']),
        ]);

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331']))
            ->assertExitCode(0);

        $item = RunItem::first();
        $this->assertSame('done', $item->status);
        $this->assertStringContainsString('feather', $item->error); // warnings ride along
        $this->assertSame('completed', Run::first()->status);
    }

    public function test_missing_kit_fails_fast(): void
    {
        $this->fakeBridgeOk();

        unlink($this->kitDir.'/wotfLib.jsxinc');

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331']))
            ->assertExitCode(1);

        $this->assertSame(0, Run::count());
    }

    public function test_manifest_profile_drives_the_whole_run(): void
    {
        $this->fakeBridgeOk();

        // RGB art lives in the celeb dir for this test's "preview" profile.
        touch($this->celebDir.'/TBC331.png');

        $manifest = $this->artDir.'/foundry.json';
        file_put_contents($manifest, json_encode([
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => [
                'kit' => $this->kitDir,
                'merge' => $this->merge,
                'artDirs' => [$this->artDir],
                'treatment' => 'Base Paper',
            ],
            'profiles' => [
                'preview' => [
                    'artDirs' => [$this->celebDir],
                    'export' => ['format' => 'png', 'ppi' => 144],
                    'proof' => false,
                    'pipeline' => [
                        ['step' => 'lbAndItalics', 'opts' => []],
                        ['step' => 'placeArtInBox', 'opts' => []],
                    ],
                ],
            ],
        ]));

        $this->artisan('cards:export', [
            '--manifest' => $manifest,
            '--profile' => 'preview',
            '--dest' => $this->destDir,
            '--cards' => 'TBC331',
        ])->assertExitCode(0);

        $run = Run::first();
        $this->assertSame('preview', $run->profile);
        $this->assertSame('png', $run->format);
        $this->assertSame(['ppi' => 144], $run->export_options);
        $this->assertFalse($run->proof);
        $this->assertSame([$this->celebDir], $run->art_dirs);
        $this->assertSame('Trial by Combat', $run->set_name);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($r->body(), 'PNG_FORMAT')
            && str_contains($r->body(), '144'));

        // The profile's custom pipeline replaces the app default.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($r->body(), 'lbAndItalics')
            && ! str_contains($r->body(), 'frauncesFonts'));
    }

    public function test_cli_flags_override_the_manifest_profile(): void
    {
        $this->fakeBridgeOk();

        $manifest = $this->artDir.'/foundry.json';
        file_put_contents($manifest, json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => [
                'kit' => $this->kitDir,
                'merge' => $this->merge,
                'artDirs' => [$this->artDir],
                'export' => ['format' => 'png'],
            ],
            'profiles' => [],
        ]));

        $this->artisan('cards:export', [
            '--manifest' => $manifest,
            '--dest' => $this->destDir,
            '--cards' => 'TBC331',
            '--format' => 'pdf',
            '--treatment' => 'Stonefoil',
        ])->assertExitCode(0);

        $run = Run::first();
        $this->assertSame('pdf', $run->format);
        $this->assertSame('Stonefoil', $run->treatment);
    }

    public function test_multiple_treatments_build_each_card_per_treatment_into_subfolders(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs([
            '--cards' => 'TBC331,TBC060',
            '--treatment' => ['Base Paper', 'Stonefoil'],
        ]))->assertExitCode(0);

        $run = Run::first();
        $this->assertSame(['Base Paper', 'Stonefoil'], $run->treatments);
        $this->assertSame(4, $run->items()->count()); // 2 cards × 2 treatments
        $this->assertSame(4, RunItem::where('status', 'done')->count());

        $itemTreatments = RunItem::all()->pluck('payload.treatment')->countBy()->all();
        $this->assertSame(['Base Paper' => 2, 'Stonefoil' => 2], $itemTreatments);

        // Each treatment exports into its own subfolder of the destination.
        $body = fn (Request $r) => str_replace('\\/', '/', $r->body());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($body($r), $this->destDir.'/Base Paper/'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($body($r), $this->destDir.'/Stonefoil/'));
    }

    public function test_single_treatment_exports_flat(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs(['--cards' => 'TBC331']))
            ->assertExitCode(0);

        $body = fn (Request $r) => str_replace('\\/', '/', $r->body());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($body($r), $this->destDir.'/TBC331'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($body($r), $this->destDir.'/Base Paper/'));
    }

    public function test_show_plan_prints_pipeline(): void
    {
        $this->artisan('cards:export', ['--show-plan' => true])
            ->expectsOutputToContain('lbAndItalics')
            ->assertExitCode(0);
    }
}
