<?php

namespace App\Console\Commands;

use App\InDesign\BridgeClient;
use App\InDesign\BridgeSupervisor;
use Illuminate\Console\Command;
use Throwable;

class BridgeStart extends Command
{
    protected $signature = 'bridge:start';

    protected $description = 'Start the InDesign UXP bridge server in the background.';

    public function handle(BridgeSupervisor $supervisor): int
    {
        try {
            $supervisor->ensureRunning();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Bridge is running on '.config('wotf.bridge_url').'.');

        return self::SUCCESS;
    }
}
