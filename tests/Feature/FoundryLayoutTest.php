<?php

namespace Tests\Feature;

use App\Manifest\FoundryLayout;
use App\Manifest\ManifestLoader;
use App\Manifest\ManifestWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The .foundry/ set layout: config lives in <set>/.foundry/config.json,
 * relative paths resolve against the SET folder (not .foundry), every save
 * records an immutable copy under versions/, and bare foundry.json keeps
 * working as the legacy fallback.
 */
class FoundryLayoutTest extends TestCase
{
    private string $root;

    private string $setDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/wotf-layout-'.uniqid();
        $this->setDir = $this->root.'/Trial by Combat';
        mkdir($this->setDir.'/.foundry', 0755, true);
        mkdir($this->root.'/shared');
        touch($this->root.'/shared/master.indd');
        touch($this->setDir.'/merge.csv');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function writeConfig(array $base = [], string $file = '.foundry/config.json'): string
    {
        $path = $this->setDir.'/'.$file;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode([
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => $base + ['merge' => 'merge.csv', 'kit' => '.'],
            'profiles' => [],
        ]));

        return $path;
    }

    // ── Discovery + resolution ──────────────────────────────────────────

    public function test_opening_the_set_folder_finds_foundry_config(): void
    {
        $this->writeConfig();

        $manifest = (new ManifestLoader)->load($this->setDir);

        $this->assertSame($this->setDir.'/.foundry/config.json', $manifest->path);
        $this->assertSame('Trial by Combat', $manifest->set);
    }

    public function test_relative_paths_resolve_against_the_set_folder_not_foundry(): void
    {
        $this->writeConfig(['template' => '../shared/master.indd']);

        $config = (new ManifestLoader)->load($this->setDir.'/.foundry/config.json')->effective(null);

        $this->assertSame($this->setDir.'/merge.csv', $config->mergePath);
        // A template ABOVE the set folder — climbing out of the set works.
        $this->assertSame($this->root.'/shared/master.indd', $config->templatePath);
    }

    public function test_legacy_foundry_json_still_works(): void
    {
        $this->writeConfig([], 'foundry.json');
        File::deleteDirectory($this->setDir.'/.foundry');

        $manifest = (new ManifestLoader)->load($this->setDir);

        $this->assertSame($this->setDir.'/foundry.json', $manifest->path);
        $this->assertSame($this->setDir.'/merge.csv', $manifest->effective(null)->mergePath);
    }

    public function test_foundry_config_wins_over_a_legacy_file(): void
    {
        $this->writeConfig();
        $this->writeConfig([], 'foundry.json');

        $this->assertSame(
            $this->setDir.'/.foundry/config.json',
            (new ManifestLoader)->load($this->setDir)->path,
        );
    }

    // ── Writer: set-folder-relative paths + versions/ ───────────────────

    public function test_writer_relativizes_against_the_set_folder(): void
    {
        $path = $this->writeConfig();

        (new ManifestWriter)->saveBase($path, [
            'merge' => $this->setDir.'/merge.csv',
            'template' => $this->root.'/shared/master.indd',
        ]);

        $data = json_decode(file_get_contents($path), true);
        $this->assertSame('merge.csv', $data['base']['merge']);
        $this->assertSame('../shared/master.indd', $data['base']['template']);
    }

    public function test_every_save_records_a_version_copy(): void
    {
        $path = $this->writeConfig();

        (new ManifestWriter)->saveBase($path, ['proof' => false]);
        (new ManifestWriter)->saveBase($path, ['proof' => true]);

        $versions = glob($this->setDir.'/.foundry/versions/*.json');
        $this->assertCount(2, $versions);

        // Deterministic, cross-platform names: stamp (no colons!), user, content hash.
        foreach ($versions as $version) {
            $this->assertMatchesRegularExpression(
                '/^config-\d{8}T\d{9}Z-[A-Za-z0-9_-]+-[0-9a-f]{8}\.json$/',
                basename($version),
            );
        }

        // The newest version is byte-identical to the live config.
        sort($versions);
        $this->assertSame(file_get_contents($path), file_get_contents(end($versions)));
    }

    public function test_an_unchanged_save_does_not_add_a_version(): void
    {
        $path = $this->writeConfig();

        (new ManifestWriter)->saveBase($path, ['proof' => false]);
        (new ManifestWriter)->saveBase($path, ['proof' => false]); // no-op

        $this->assertCount(1, glob($this->setDir.'/.foundry/versions/*.json'));
    }

    public function test_legacy_saves_record_versions_into_a_sibling_foundry_dir(): void
    {
        File::deleteDirectory($this->setDir.'/.foundry');
        $path = $this->writeConfig([], 'foundry.json');

        (new ManifestWriter)->saveBase($path, ['proof' => false]);

        $this->assertCount(1, glob($this->setDir.'/.foundry/versions/*.json'));
    }

    public function test_create_builds_the_foundry_layout(): void
    {
        $target = $this->setDir.'/sub-set/.foundry/config.json';

        (new ManifestWriter)->create($target, 'Sub Set', ['merge' => 'merge.csv']);

        $this->assertFileExists($target);
        $this->assertSame('Sub Set', json_decode(file_get_contents($target), true)['set']);
    }

    // ── FoundryLayout helpers ───────────────────────────────────────────

    public function test_base_dir_for_both_layouts(): void
    {
        $this->assertSame($this->setDir, FoundryLayout::baseDirFor($this->setDir.'/.foundry/config.json'));
        $this->assertSame($this->setDir, FoundryLayout::baseDirFor($this->setDir.'/foundry.json'));
    }

    public function test_foundry_dir_for_both_layouts(): void
    {
        $this->assertSame($this->setDir.'/.foundry', FoundryLayout::foundryDirFor($this->setDir.'/.foundry/config.json'));
        $this->assertSame($this->setDir.'/.foundry', FoundryLayout::foundryDirFor($this->setDir.'/foundry.json'));
    }
}
