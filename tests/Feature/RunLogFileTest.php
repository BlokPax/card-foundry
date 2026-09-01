<?php

namespace Tests\Feature;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunLogFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_log_lines_are_mirrored_to_the_runs_log_file(): void
    {
        $path = config('logging.channels.runs.path');
        @unlink($path);

        $run = Run::create([
            'status' => 'running',
            'merge_path' => '/m.csv',
            'art_dirs' => ['/a'],
            'template_path' => '/t.indd',
            'output_dir' => '/out',
            'total' => 1,
        ]);

        $run->appendLog('✓ Ashbound (TBC197)');

        $this->assertFileExists($path);
        $log = file_get_contents($path);
        $this->assertStringContainsString("[run {$run->id}]", $log);
        $this->assertStringContainsString('✓ Ashbound (TBC197)', $log);

        // The DB copy (what the GUI tails) still gets the line too.
        $this->assertStringContainsString('✓ Ashbound (TBC197)', $run->fresh()->log);
    }
}
