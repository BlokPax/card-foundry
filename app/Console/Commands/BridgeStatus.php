<?php

namespace App\Console\Commands;

use App\InDesign\BridgeClient;
use App\InDesign\BridgeSupervisor;
use Illuminate\Console\Command;
use Throwable;

class BridgeStatus extends Command
{
    protected $signature = 'bridge:status';

    protected $description = 'Show bridge and InDesign connection status.';

    public function handle(BridgeSupervisor $supervisor, BridgeClient $client): int
    {
        $this->components->twoColumnDetail('Bridge ready', $supervisor->isInstalled() ? '<fg=green>yes</>' : '<fg=red>no</>');
        $this->components->twoColumnDetail('UXP plugin folder', $supervisor->pluginPath());
        $this->components->twoColumnDetail('Bridge server up', $supervisor->isRunning() ? '<fg=green>yes</>' : '<fg=red>no</>');

        if ($supervisor->isRunning()) {
            $ping = $client->ping();
            $this->components->twoColumnDetail(
                'InDesign connected',
                $ping->ok ? "<fg=green>yes</> <fg=gray>({$ping->data})</>" : "<fg=red>no</> <fg=gray>({$ping->error})</>",
            );
        }

        return self::SUCCESS;
    }
}
