<?php

namespace App\Console\Commands;

use App\InDesign\BridgeClient;
use App\InDesign\BridgeSupervisor;
use Illuminate\Console\Command;
use Throwable;

class BridgeInstall extends Command
{
    protected $signature = 'bridge:install';

    protected $description = 'Prepare the bundled InDesign bridge (installs npm dependencies if missing).';

    public function handle(BridgeSupervisor $supervisor): int
    {
        try {
            $supervisor->install(fn (string $line) => $this->components->info($line));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('One-time InDesign step: load the UXP plugin at '
            .$supervisor->pluginPath().' (via Adobe UXP Developer Tool), '
            .'then enable it via Window → Plugins → InDesign Bridge.');

        return self::SUCCESS;
    }
}
