<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const LOGGED_COMMAND_PREFIXES = ['cards:', 'bridge:'];

    /** @var array<string, float> command name => start time */
    private array $commandStartedAt = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (! $this->shouldLog($event->command)) {
                return;
            }

            $this->commandStartedAt[$event->command] = microtime(true);

            Log::channel('commands')->info(
                "▶ {$event->command} ".mb_strimwidth((string) $event->input, 0, 1000, '…'),
                ['pid' => getmypid()],
            );
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if (! $this->shouldLog($event->command)) {
                return;
            }

            $startedAt = $this->commandStartedAt[$event->command] ?? null;
            $duration = $startedAt !== null ? number_format(microtime(true) - $startedAt, 1).'s' : '?';

            Log::channel('commands')->info(
                "■ {$event->command} exit {$event->exitCode} after {$duration}",
                ['pid' => getmypid()],
            );
        });
    }

    private function shouldLog(?string $command): bool
    {
        return $command !== null
            && array_any(self::LOGGED_COMMAND_PREFIXES, fn (string $prefix) => str_starts_with($command, $prefix));
    }
}
