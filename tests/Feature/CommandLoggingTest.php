<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The framework intentionally skips console events while running unit tests
 * (Kernel::rerouteSymfonyCommandEvents), so these dispatch the events our
 * listener receives in real runs.
 */
class CommandLoggingTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = config('logging.channels.commands.path');
        @unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    private function dispatch(string $command, array $input = []): void
    {
        $in = new ArrayInput($input);
        $out = new NullOutput;

        event(new CommandStarting($command, $in, $out));
        event(new CommandFinished($command, $in, $out, 0));
    }

    public function test_app_commands_are_logged_with_input_and_exit_code(): void
    {
        $this->dispatch('cards:export', ['--show-plan' => true]);

        $this->assertFileExists($this->logPath);
        $log = file_get_contents($this->logPath);

        $this->assertStringContainsString('▶ cards:export', $log);
        $this->assertStringContainsString('--show-plan', $log);
        $this->assertStringContainsString('■ cards:export exit 0', $log);
    }

    public function test_bridge_commands_are_logged(): void
    {
        $this->dispatch('bridge:status');

        $this->assertStringContainsString('bridge:status', file_get_contents($this->logPath));
    }

    public function test_framework_commands_are_not_logged(): void
    {
        $this->dispatch('about');
        $this->dispatch('migrate');

        $this->assertFileDoesNotExist($this->logPath);
    }
}
