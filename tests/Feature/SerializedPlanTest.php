<?php

namespace Tests\Feature;

use App\Export\ExportPlanner;
use App\Manifest\BuildConfig;
use App\Merge\MergeFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class SerializedPlanTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $merge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wotf-serial-'.uniqid();
        mkdir($this->dir);

        $this->merge = $this->dir.'/merge.csv';
        file_put_contents($this->merge, implode("\n", [
            'Name,Card Code,Set Name,@Image,OCM Edition,Dragon Edition',
            'Alpha,1/398,TBC,TBC001,3,10',
            'Beta,2/398,TBC,TBC002,,5',
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function config(array $overrides = []): BuildConfig
    {
        $treatmentConfig = [
            'OCM' => ['layer' => 'OCM Frame', 'serialColumn' => 'OCM Edition'],
            'Dragon Scale' => ['layer' => 'Dragon Foil', 'serialColumn' => 'Dragon Edition'],
            'Base Paper' => ['layer' => 'Paper Frame', 'serialColumn' => null],
        ];

        return new BuildConfig(
            set: 'TBC',
            profile: null,
            mergePath: $this->merge,
            kitPath: $this->dir,
            templatePath: $this->dir.'/t.indd',
            artDirs: [$this->dir],
            treatments: $overrides['treatments'] ?? ['OCM'],
            proof: true,
            format: 'pdf',
            exportOptions: [],
            pipeline: null,
            whiteDirs: $overrides['whiteDirs'] ?? [],
            treatmentConfig: $overrides['treatmentConfig'] ?? $treatmentConfig,
            serialize: $overrides['serialize'] ?? true,
            serialization: $overrides['serialization'] ?? ['pad' => 2, 'column' => null],
        );
    }

    private function plan(BuildConfig $config): \App\Models\Run
    {
        $merge = (new MergeFileParser)->parse($this->merge);

        return (new ExportPlanner)->plan($merge, $config, $this->dir);
    }

    public function test_serialized_plan_expands_one_item_per_copy(): void
    {
        $run = $this->plan($this->config());

        // Alpha: OCM Edition 3 → 3 copies; Beta: empty → 1 unserialized item.
        $this->assertSame(4, $run->total);
        $this->assertSame(4, $run->items()->count());

        $alpha = $run->items()->where('card_code', '1/398')->get();
        $this->assertSame([1, 2, 3], $alpha->pluck('payload.serial')->all());
        $this->assertSame([3, 3, 3], $alpha->pluck('payload.serialMax')->all());

        $beta = $run->items()->where('card_code', '2/398')->first();
        $this->assertArrayNotHasKey('serial', $beta->payload);
    }

    public function test_each_treatment_uses_its_own_serial_column(): void
    {
        $run = $this->plan($this->config(['treatments' => ['OCM', 'Dragon Scale']]));

        // OCM: 3 + 1 (Beta unserialized); Dragon Scale: 10 + 5.
        $this->assertSame(19, $run->total);
    }

    public function test_unserialized_plan_is_unchanged(): void
    {
        $run = $this->plan($this->config(['serialize' => false]));

        $this->assertSame(2, $run->total);
        $this->assertArrayNotHasKey('serial', $run->items()->first()->payload);
    }

    public function test_global_serial_column_is_the_fallback(): void
    {
        $run = $this->plan($this->config([
            'treatments' => ['Base Paper'],
            'serialization' => ['pad' => 2, 'column' => 'OCM Edition'],
        ]));

        $this->assertSame(4, $run->total); // 3 + 1
    }

    public function test_treatments_are_validated_against_the_treatment_config(): void
    {
        // A custom treatment defined only in the manifest passes…
        $run = $this->plan($this->config([
            'serialize' => false,
            'treatments' => ['Holo Rainbow'],
            'treatmentConfig' => ['Holo Rainbow' => ['layer' => 'Holo Frame', 'serialColumn' => null]],
        ]));
        $this->assertSame(2, $run->total);

        // …an unknown one fails.
        $this->expectException(InvalidArgumentException::class);
        $this->plan($this->config(['treatments' => ['Nope']]));
    }

    public function test_run_snapshots_the_v030_config(): void
    {
        $run = $this->plan($this->config(['whiteDirs' => [$this->dir]]));

        $this->assertSame('OCM Frame', $run->treatment_layers['OCM']);
        $this->assertSame([$this->dir], $run->white_dirs);
        $this->assertTrue($run->serialization['enabled']);
        $this->assertSame(2, $run->serialization['pad']);
        $this->assertSame('OCM Edition', $run->serialization['columns']['OCM']);
    }
}
