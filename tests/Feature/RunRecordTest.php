<?php

namespace Tests\Feature;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every manifest-driven run leaves an immutable record in
 * <set>/.foundry/runs/: the resolved parameters, content hashes of the JSX
 * kit and template, and a snapshot of the merge data — the concrete
 * "what built this set" history, safe from later kit upgrades.
 */
class RunRecordTest extends TestCase
{
    use RefreshDatabase;

    private string $setDir;

    private string $kitDir;

    private string $destDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/wotf-record-'.uniqid();
        mkdir($this->setDir = $base.'-set');
        mkdir($this->destDir = $base.'-out');

        mkdir($this->kitDir = $base.'-kit');
        file_put_contents($this->kitDir.'/wotfLib.jsxinc', '// kit lib v1');
        mkdir($this->kitDir.'/builds', 0755, true);
        file_put_contents($this->kitDir.'/builds/_MASTER_BUILD_TEMPLATE.indd', 'indd-bytes');

        copy(base_path('tests/Fixtures/merge-sample.csv'), $this->setDir.'/merge.csv');
        foreach (['TBC331', 'TBC060', 'TBC325', 'TBC197', 'TBC391'] as $ref) {
            touch($this->setDir.'/'.$ref.'.psd');
        }

        mkdir($this->setDir.'/.foundry');
        file_put_contents($this->setDir.'/.foundry/config.json', json_encode([
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => [
                'kit' => $this->kitDir,
                'merge' => 'merge.csv',
                'artDirs' => ['.'],
            ],
            'profiles' => [],
        ]));

        Http::fake([
            '127.0.0.1:3000/*' => Http::response([
                'result' => '{"ok":true,"stages":[]}',
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->setDir, $this->kitDir, $this->destDir] as $dir) {
            File::deleteDirectory($dir);
        }
        File::deleteDirectory(storage_path('app/runs'));

        parent::tearDown();
    }

    private function export(array $extra = []): void
    {
        $this->artisan('cards:export', array_merge([
            '--manifest' => $this->setDir.'/.foundry/config.json',
            '--dest' => $this->destDir,
            '--cards' => 'TBC331',
        ], $extra))->assertExitCode(0);
    }

    public function test_a_run_writes_an_immutable_record_with_hashes_and_merge_snapshot(): void
    {
        $this->export();

        $records = glob($this->setDir.'/.foundry/runs/*.json');
        $this->assertCount(1, $records);
        $this->assertMatchesRegularExpression(
            '/^\d{8}T\d{9}Z-[A-Za-z0-9_-]+-[0-9a-f]{4}\.json$/',
            basename($records[0]),
        );

        $record = json_decode(file_get_contents($records[0]), true);

        $this->assertSame('Trial by Combat', $record['set']);
        $this->assertSame(['Base Paper'], $record['treatments']);
        $this->assertSame('pdf', $record['format']);
        $this->assertSame(1, $record['totals']['completed']);
        $this->assertSame(md5('// kit lib v1'), $record['kit']['libHash']);
        $this->assertSame(md5('indd-bytes'), $record['template']['hash']);

        // The merge data itself is snapshotted beside the record.
        $snapshot = substr($records[0], 0, -5).'.merge.csv';
        $this->assertFileExists($snapshot);
        $this->assertStringContainsString('Aetheris', file_get_contents($snapshot));
        $this->assertSame($record['merge']['snapshot'], basename($snapshot));
    }

    public function test_runs_without_a_manifest_write_no_record(): void
    {
        $this->artisan('cards:export', [
            '--merge' => $this->setDir.'/merge.csv',
            '--art' => [$this->setDir],
            '--kit' => $this->kitDir,
            '--dest' => $this->destDir,
            '--cards' => 'TBC331',
        ])->assertExitCode(0);

        $this->assertSame([], glob($this->setDir.'/.foundry/runs/*.json'));
    }

    public function test_scripts_override_replaces_the_kit_library_and_is_recorded(): void
    {
        mkdir($this->setDir.'/.foundry/scripts');
        file_put_contents($this->setDir.'/.foundry/scripts/wotfLib.jsxinc', '// frozen lib');

        $this->export();

        // The build loads the set's own library, not the kit's.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute')
            && str_contains(str_replace('\\', '', $r->body()), '.foundry/scripts/wotfLib.jsxinc'));

        $record = json_decode(file_get_contents(glob($this->setDir.'/.foundry/runs/*.json')[0]), true);
        $this->assertSame(md5('// frozen lib'), $record['kit']['libHash']);
        $this->assertTrue($record['kit']['scriptsOverride']);
    }
}
