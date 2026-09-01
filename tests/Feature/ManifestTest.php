<?php

namespace Tests\Feature;

use App\Manifest\ManifestException;
use App\Manifest\ManifestLoader;
use App\Manifest\ManifestWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ManifestTest extends TestCase
{
    private string $setDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setDir = sys_get_temp_dir().'/wotf-set-'.uniqid();
        mkdir($this->setDir.'/_exports/CMYK', 0755, true);
        mkdir($this->setDir.'/_exports/RGB', 0755, true);
        mkdir($this->setDir.'/../'.basename($this->setDir).'-kit', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->setDir);
        File::deleteDirectory($this->setDir.'-kit');

        parent::tearDown();
    }

    private function writeManifest(array $data): string
    {
        $path = $this->setDir.'/foundry.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    private function sample(): array
    {
        return [
            'version' => 1,
            'set' => 'Trial by Combat',
            'base' => [
                'kit' => '../'.basename($this->setDir).'-kit',
                'merge' => 'merge.csv',
                'template' => 'template.indd',
                'artDirs' => ['_exports/CMYK'],
                'treatment' => 'Base Paper',
                'proof' => true,
                'export' => ['format' => 'pdf', 'ppi' => 300],
            ],
            'profiles' => [
                'print' => [],
                'preview' => [
                    'artDirs' => ['_exports/RGB'],
                    'export' => ['format' => 'png'],
                    'treatment' => 'Classic Foil',
                ],
            ],
        ];
    }

    public function test_loads_base_config_with_paths_resolved_relative_to_manifest(): void
    {
        $manifest = (new ManifestLoader)->load($this->writeManifest($this->sample()));
        $config = $manifest->effective('print');

        $this->assertSame('Trial by Combat', $manifest->set);
        $this->assertSame(['print', 'preview'], $manifest->profileNames());
        $this->assertSame($this->setDir.'/merge.csv', $config->mergePath);
        $this->assertSame([$this->setDir.'/_exports/CMYK'], $config->artDirs);
        $this->assertSame($this->setDir.'-kit', $config->kitPath);
        $this->assertSame('pdf', $config->format);
        $this->assertSame(['Base Paper'], $config->treatments);
    }

    public function test_profile_overrides_any_base_option(): void
    {
        $manifest = (new ManifestLoader)->load($this->writeManifest($this->sample()));
        $config = $manifest->effective('preview');

        $this->assertSame([$this->setDir.'/_exports/RGB'], $config->artDirs);
        $this->assertSame('png', $config->format);
        $this->assertSame(['Classic Foil'], $config->treatments);
        // Un-overridden keys fall through to base.
        $this->assertSame($this->setDir.'/merge.csv', $config->mergePath);
        $this->assertSame(300, $config->exportOptions['ppi']);
    }

    public function test_runtime_overrides_beat_the_profile(): void
    {
        $manifest = (new ManifestLoader)->load($this->writeManifest($this->sample()));
        $config = $manifest->effective('preview', ['format' => 'pdf', 'treatment' => ['OCM']]);

        $this->assertSame('pdf', $config->format);
        $this->assertSame(['OCM'], $config->treatments);
        $this->assertSame([$this->setDir.'/_exports/RGB'], $config->artDirs);
    }

    public function test_absolute_paths_pass_through_unchanged(): void
    {
        $data = $this->sample();
        $data['base']['merge'] = '/absolute/elsewhere.csv';

        $config = (new ManifestLoader)->load($this->writeManifest($data))->effective('print');

        $this->assertSame('/absolute/elsewhere.csv', $config->mergePath);
    }

    public function test_unknown_profile_throws(): void
    {
        $manifest = (new ManifestLoader)->load($this->writeManifest($this->sample()));

        $this->expectException(ManifestException::class);
        $manifest->effective('social');
    }

    public function test_invalid_json_and_missing_fields_throw(): void
    {
        $path = $this->setDir.'/foundry.json';

        file_put_contents($path, '{nope');
        try {
            (new ManifestLoader)->load($path);
            $this->fail('Expected ManifestException for invalid JSON');
        } catch (ManifestException) {
        }

        file_put_contents($path, json_encode(['version' => 1]));
        $this->expectException(ManifestException::class);
        (new ManifestLoader)->load($path);
    }

    public function test_writer_round_trips_and_relativizes_paths(): void
    {
        $path = $this->writeManifest($this->sample());

        (new ManifestWriter)->saveProfile($path, 'preview', [
            'artDirs' => [$this->setDir.'/_exports/RGB', '/somewhere/outside/celebs'],
            'export' => ['format' => 'png', 'ppi' => 144],
            'proof' => false,
        ]);

        $raw = json_decode(file_get_contents($path), true);

        // Inside-the-tree path relativized; outside path kept absolute.
        $this->assertSame(['_exports/RGB', '/somewhere/outside/celebs'], $raw['profiles']['preview']['artDirs']);
        $this->assertSame(144, $raw['profiles']['preview']['export']['ppi']);
        $this->assertFalse($raw['profiles']['preview']['proof']);
        // Base is untouched.
        $this->assertSame(['_exports/CMYK'], $raw['base']['artDirs']);

        // And it still loads with the new values effective.
        $config = (new ManifestLoader)->load($path)->effective('preview');
        $this->assertSame(144, $config->exportOptions['ppi']);
        $this->assertFalse($config->proof);
    }

    public function test_writer_can_create_a_manifest_from_scratch(): void
    {
        $path = $this->setDir.'/new-foundry.json';

        (new ManifestWriter)->create($path, 'Existence', [
            'merge' => $this->setDir.'/merge.csv',
            'artDirs' => [$this->setDir.'/_exports/CMYK'],
            'treatment' => 'Base Paper',
            'export' => ['format' => 'pdf'],
        ]);

        $manifest = (new ManifestLoader)->load($path);

        $this->assertSame('Existence', $manifest->set);
        $this->assertSame($this->setDir.'/merge.csv', $manifest->effective(null)->mergePath);
    }

    public function test_default_profile_name_round_trips(): void
    {
        $path = $this->writeManifest($this->sample());

        $this->assertSame('Default', (new ManifestLoader)->load($path)->defaultProfileName);

        (new ManifestWriter)->setDefaultProfileName($path, 'Production');
        $this->assertSame('Production', (new ManifestLoader)->load($path)->defaultProfileName);
    }

    public function test_named_profiles_can_be_renamed(): void
    {
        $path = $this->writeManifest($this->sample());

        (new ManifestWriter)->renameProfile($path, 'preview', 'web');

        $manifest = (new ManifestLoader)->load($path);
        $this->assertSame(['print', 'web'], $manifest->profileNames());
        $this->assertSame('png', $manifest->effective('web')->format);
    }

    public function test_named_profiles_can_be_deleted(): void
    {
        $path = $this->writeManifest($this->sample());

        (new ManifestWriter)->deleteProfile($path, 'preview');

        $this->assertSame(['print'], (new ManifestLoader)->load($path)->profileNames());
    }

    public function test_deleting_an_unknown_profile_throws(): void
    {
        $path = $this->writeManifest($this->sample());

        $this->expectException(\App\Manifest\ManifestException::class);
        (new ManifestWriter)->deleteProfile($path, 'nope');
    }

    public function test_portability_is_a_parent_distance_check(): void
    {
        $base = $this->setDir;

        // Inside the set folder: portable.
        $this->assertSame('_exports/CMYK', \App\Manifest\Portability::relativize($base.'/_exports/CMYK', $base));
        // Siblings and near ancestors (≤ 3 climbs): portable.
        $this->assertSame('../'.basename($base).'-kit', \App\Manifest\Portability::relativize($base.'-kit', $base));
        // More than 3 parents away: machine-specific.
        $deep = $base.'/a/b/c/d';
        $this->assertNull(\App\Manifest\Portability::relativize($base.'/other', $deep));
        $this->assertSame('../../../other', \App\Manifest\Portability::relativize($base.'/other', $base.'/a/b/c'));
        // Data URIs are never paths; relative input passes through.
        $this->assertNull(\App\Manifest\Portability::relativize('data:image/jpeg;base64,xx', $base));
        $this->assertSame('already/relative', \App\Manifest\Portability::relativize('already/relative', $base));

        // Cloud roots aren't required — but they are boundaries.
        $dropbox = '/Users/someone/Library/CloudStorage/Dropbox-LoudDoor';
        // Within one cloud root: fine.
        $this->assertSame(
            '../_WoTF Kit',
            \App\Manifest\Portability::relativize("{$dropbox}/Card Art/_WoTF Kit", "{$dropbox}/Card Art/TBC"),
        );
        // Manifest outside cloud storage reaching INTO it: crosses the boundary.
        $this->assertNull(\App\Manifest\Portability::relativize("{$dropbox}/Card Art", '/Users/someone/Downloads'));
        // Manifest inside cloud storage climbing OUT of it: crosses the boundary.
        $this->assertNull(\App\Manifest\Portability::relativize('/Users/someone/Desktop/x', "{$dropbox}/Card Art"));
    }

    public function test_effective_without_profile_uses_base_only(): void
    {
        $manifest = (new ManifestLoader)->load($this->writeManifest($this->sample()));

        $this->assertSame('pdf', $manifest->effective(null)->format);
    }
}
