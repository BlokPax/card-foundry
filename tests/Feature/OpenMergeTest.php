<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The set header's "open the merge" button: a linked sheet opens in the
 * browser, a local file opens in its default app — no need to enter the
 * setup editor at all.
 */
class OpenMergeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();

        $this->dir = sys_get_temp_dir().'/wotf-open-'.uniqid();
        mkdir($this->dir);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $this->dir.'/merge.csv');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_sheet_merge_opens_in_the_browser(): void
    {
        Process::fake();
        $url = 'https://docs.google.com/spreadsheets/d/abc/edit#gid=0';

        $component = Livewire::test(ControlPlane::class);
        $component->set('mergePath', $url); // parse fails offline — opening still works
        $component->call('openMerge');

        Process::assertRan(fn ($process) => in_array($url, $process->command, true));
    }

    public function test_a_local_merge_opens_in_its_default_app(): void
    {
        Process::fake();

        Livewire::test(ControlPlane::class)
            ->set('mergePath', $this->dir.'/merge.csv')
            ->call('openMerge');

        Process::assertRan(fn ($process) => in_array($this->dir.'/merge.csv', $process->command, true));
    }

    public function test_a_missing_merge_explains_instead_of_failing(): void
    {
        Process::fake();

        $component = Livewire::test(ControlPlane::class)
            ->set('mergePath', $this->dir.'/gone.csv')
            ->call('openMerge');

        $this->assertStringContainsString('missing', strtolower((string) $component->get('flash')));
        Process::assertNothingRan();
    }
}
