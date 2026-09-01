<?php

namespace App\Console\Commands;

use App\InDesign\BridgeSupervisor;
use Illuminate\Console\Command;

class BridgeInstallPlugin extends Command
{
    protected $signature = 'bridge:install-plugin';

    protected $description = 'Install the bundled InDesign UXP plugin (.ccx) via Creative Cloud.';

    public function handle(BridgeSupervisor $supervisor): int
    {
        [$ok, $message] = $supervisor->installPlugin();

        $ok ? $this->components->info($message) : $this->error($message);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
