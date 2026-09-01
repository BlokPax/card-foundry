<?php

namespace Tests\Feature;

use App\Export\PdfCombiner;
use App\Models\Run;
use App\Models\RunItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PdfCombinerSpy extends PdfCombiner
{
    public array $calls = [];

    public function combine(array $files, string $output): void
    {
        $this->calls[] = [$files, $output];
        touch($output);
    }
}

/**
 * v0.3.0 end-to-end: linked Google Sheet merges, white-layer directories,
 * serialized copies, marks options, and single-document PDF export.
 */
class CardsExportV030Test extends TestCase
{
    use RefreshDatabase;

    private string $artDir;

    private string $whiteDir;

    private string $destDir;

    private string $kitDir;

    private string $merge;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/wotf-v030-'.uniqid();
        mkdir($this->artDir = $base.'-art');
        mkdir($this->whiteDir = $base.'-white');
        mkdir($this->destDir = $base.'-out');

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
        foreach ([$this->artDir, $this->whiteDir, $this->destDir, $this->kitDir] as $dir) {
            File::deleteDirectory($dir);
        }
        File::deleteDirectory(storage_path('app/runs'));
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    private function fakeBridgeOk(array $extraFakes = []): void
    {
        Http::fake($extraFakes + [
            '127.0.0.1:3000/*' => Http::response([
                'result' => '{"ok":true,"stages":[{"name":"lbAndItalics","lines":["ok"],"warnings":[]}]}',
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

    private function body(Request $r): string
    {
        // The JSX rides through two JSON encodings — drop the escaping so
        // assertions can read it as written.
        return str_replace('\\', '', $r->body());
    }

    // ── Google Sheet merge ──────────────────────────────────────────────

    public function test_a_google_sheet_url_merge_builds_from_the_downloaded_csv(): void
    {
        $this->fakeBridgeOk([
            'docs.google.com/*' => Http::response(file_get_contents($this->merge)),
        ]);

        $this->artisan('cards:export', $this->baseArgs([
            '--merge' => 'https://docs.google.com/spreadsheets/d/sheet-id-123/edit#gid=7',
            '--cards' => 'TBC331',
        ]))->assertExitCode(0);

        $run = Run::first();
        $this->assertSame('completed', $run->status);
        $this->assertStringContainsString('merge-cache/sheet-sheet-id-123-7.csv', $run->merge_path);
    }

    // ── White-layer directories ─────────────────────────────────────────

    public function test_white_art_is_hunted_like_card_art_and_passed_to_the_build(): void
    {
        $this->fakeBridgeOk();

        touch($this->whiteDir.'/TBC331.psd');

        $this->artisan('cards:export', $this->baseArgs([
            '--cards' => 'TBC331',
            '--white' => [$this->whiteDir],
        ]))->assertExitCode(0);

        $this->assertSame([$this->whiteDir], Run::first()->white_dirs);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($this->body($r), $this->whiteDir.'/TBC331.psd'));
    }

    public function test_missing_white_art_warns_but_still_builds(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', $this->baseArgs([
            '--cards' => 'TBC331',
            '--white' => [$this->whiteDir],
        ]))->assertExitCode(0);

        $run = Run::first();
        $this->assertSame('completed', $run->status);
        $this->assertStringContainsString('No white-layer art', $run->log);
    }

    // ── Serialization ───────────────────────────────────────────────────

    private function serializedManifest(): string
    {
        $merge = $this->artDir.'/serial-merge.csv';
        file_put_contents($merge, implode("\n", [
            'Name,Type,Card Code,Set Name,@Image,Edition Size',
            'Alpha,Wonder,1/398,TBC,TBC331,3',
            'Beta,Wonder,2/398,TBC,TBC060,',
        ]));

        $manifest = $this->artDir.'/foundry.json';
        file_put_contents($manifest, json_encode([
            'version' => 1,
            'set' => 'TBC',
            'base' => [
                'kit' => $this->kitDir,
                'merge' => $merge,
                'artDirs' => [$this->artDir],
                'treatment' => 'OCM',
                'treatmentLayers' => ['OCM' => ['layer' => 'OCM Frame', 'serialColumn' => 'Edition Size']],
                'serialize' => true,
            ],
            'profiles' => [],
        ]));

        return $manifest;
    }

    public function test_serialized_copies_export_one_numbered_file_each(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', [
            '--manifest' => $this->serializedManifest(),
            '--dest' => $this->destDir,
            '--all' => true,
        ])->assertExitCode(0);

        $run = Run::first();
        $this->assertSame(4, $run->total); // Alpha ×3 + Beta ×1
        $this->assertSame(4, RunItem::where('status', 'done')->count());

        // Each copy exports under its own numbered name with its numerator.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($this->body($r), '02of3')
            && str_contains($this->body($r), '"numerator":"02"'));

        // The unserialized card keeps the default numerator and a plain name.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($this->body($r), 'TBC060')
            && str_contains($this->body($r), '"numerator":"01"')
            && ! str_contains($this->body($r), 'of3'));
    }

    public function test_serialize_can_be_disabled_from_the_cli(): void
    {
        $this->fakeBridgeOk();

        $this->artisan('cards:export', [
            '--manifest' => $this->serializedManifest(),
            '--dest' => $this->destDir,
            '--no-serialize' => true,
            '--all' => true,
        ])->assertExitCode(0);

        $this->assertSame(2, Run::first()->total);
    }

    // ── Marks + keywords flow through the run ───────────────────────────

    public function test_marks_and_keyword_config_reach_the_build_script(): void
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
                'export' => ['format' => 'pdf', 'cropMarks' => true, 'bleed' => 3],
                'keywords' => ['known' => ['ON PLAY'], 'styleDefault' => 'Ability Text v2'],
            ],
            'profiles' => [],
        ]));

        $this->artisan('cards:export', [
            '--manifest' => $manifest,
            '--dest' => $this->destDir,
            '--cards' => 'TBC331',
        ])->assertExitCode(0);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains($this->body($r), 'cropMarks = true')
            && str_contains($this->body($r), '3mm')
            && str_contains($this->body($r), 'KNOWN_KEYWORDS')
            && str_contains($this->body($r), 'Ability Text v2'));
    }

    // ── Combine into a single PDF ───────────────────────────────────────

    public function test_combine_merges_the_runs_pdfs_into_one_document(): void
    {
        $this->fakeBridgeOk();

        $spy = new PdfCombinerSpy;
        $this->app->instance(PdfCombiner::class, $spy);

        $this->artisan('cards:export', $this->baseArgs([
            '--cards' => 'TBC331,TBC060',
            '--combine' => true,
        ]))->assertExitCode(0);

        $run = Run::first();
        $this->assertNotEmpty($spy->calls);
        [$files, $output] = $spy->calls[0];
        $this->assertCount(2, $files);
        $this->assertStringContainsString('TBC331', $files[0]);
        $this->assertStringEndsWith('.pdf', $output);
        $this->assertStringContainsString($this->destDir, $output);
        $this->assertStringContainsString('Combined', $run->log);
    }

    public function test_combine_failure_warns_but_keeps_the_per_card_pdfs(): void
    {
        $this->fakeBridgeOk();

        $this->app->instance(PdfCombiner::class, new class extends PdfCombiner
        {
            public function combine(array $files, string $output): void
            {
                throw new \RuntimeException('node exploded');
            }
        });

        $this->artisan('cards:export', $this->baseArgs([
            '--cards' => 'TBC331',
            '--combine' => true,
        ]))->assertExitCode(0);

        $run = Run::first();
        $this->assertSame('completed', $run->status);
        $this->assertStringContainsString('node exploded', $run->log);
    }
}
