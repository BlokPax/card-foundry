<?php

namespace Tests\Feature;

use App\Manifest\FoundryLayout;
use App\Manifest\ManifestException;
use App\Manifest\ManifestLoader;
use App\Manifest\ManifestWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Config files carry a formatVersion (schema, gates writes and drives
 * future migrations) and the appVersion that wrote them (for humans). An
 * app never writes into a file with a HIGHER format than it understands —
 * older apps can still read newer sets, they just can't damage them.
 */
class ManifestVersionTest extends TestCase
{
    private string $setDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setDir = sys_get_temp_dir().'/wotf-ver-'.uniqid();
        mkdir($this->setDir.'/.foundry', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->setDir);

        parent::tearDown();
    }

    private function writeConfig(array $extra = []): string
    {
        $path = $this->setDir.'/.foundry/config.json';
        file_put_contents($path, json_encode($extra + [
            'version' => 1,
            'set' => 'TBC',
            'base' => ['merge' => 'merge.csv'],
            'profiles' => [],
        ]));

        return $path;
    }

    public function test_saves_stamp_the_format_and_app_versions(): void
    {
        $path = $this->writeConfig();

        (new ManifestWriter)->saveBase($path, ['proof' => false]);

        $data = json_decode(file_get_contents($path), true);
        $this->assertSame(FoundryLayout::FORMAT_VERSION, $data['version']);
        $this->assertSame(config('nativephp.version'), $data['appVersion']);
    }

    public function test_create_stamps_the_versions_too(): void
    {
        $path = $this->setDir.'/.foundry/config.json';
        (new ManifestWriter)->create($path, 'TBC', ['merge' => 'merge.csv']);

        $data = json_decode(file_get_contents($path), true);
        $this->assertSame(FoundryLayout::FORMAT_VERSION, $data['version']);
        $this->assertSame(config('nativephp.version'), $data['appVersion']);
    }

    public function test_a_newer_format_still_loads_for_viewing(): void
    {
        $path = $this->writeConfig(['version' => FoundryLayout::FORMAT_VERSION + 1]);

        $manifest = (new ManifestLoader)->load($path);

        $this->assertSame('TBC', $manifest->set);
        $this->assertSame(FoundryLayout::FORMAT_VERSION + 1, $manifest->formatVersion);
        $this->assertTrue($manifest->newerThanApp());
    }

    public function test_writing_into_a_newer_format_is_refused(): void
    {
        $path = $this->writeConfig(['version' => FoundryLayout::FORMAT_VERSION + 1]);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/newer version of Card Foundry/');

        (new ManifestWriter)->saveBase($path, ['proof' => false]);
    }

    public function test_legacy_unversioned_files_read_and_write_fine(): void
    {
        $path = $this->setDir.'/.foundry/config.json';
        file_put_contents($path, json_encode([
            'set' => 'TBC',
            'base' => ['merge' => 'merge.csv'],
            'profiles' => [],
        ]));

        $this->assertSame(1, (new ManifestLoader)->load($path)->formatVersion);
        $this->assertFalse((new ManifestLoader)->load($path)->newerThanApp());

        (new ManifestWriter)->saveBase($path, ['proof' => false]);
        $this->assertSame(FoundryLayout::FORMAT_VERSION, json_decode(file_get_contents($path), true)['version']);
    }
}
