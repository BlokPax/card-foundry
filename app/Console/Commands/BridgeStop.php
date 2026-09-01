<?php

namespace App\Console\Commands;

use App\InDesign\BridgeClient;
use App\InDesign\BridgeSupervisor;
use Illuminate\Console\Command;
use Throwable;

class BridgeStop extends Command
{
    protected $signature = 'bridge:stop';

    protected $description = 'Stop the InDesign UXP bridge server.';

    public function handle(BridgeSupervisor $supervisor): int
    {
        $supervisor->stop();
        $this->components->info('Bridge stopped.');

        return self::SUCCESS;
    }
}
