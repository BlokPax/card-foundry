<?php

namespace Tests\Feature;

use App\InDesign\BridgeSupervisor;
use Tests\TestCase;

class BridgeSupervisorTest extends TestCase
{
    public function test_defaults_to_the_vendored_bridge(): void
    {
        $supervisor = new BridgeSupervisor;

        $this->assertSame(resource_path('bridge'), $supervisor->bridgePath());
        $this->assertTrue($supervisor->isInstalled(), 'Vendored bridge/server.js should ship with the app.');
    }

    public function test_bridge_path_can_be_overridden(): void
    {
        config(['wotf.bridge_path' => '/custom/checkout']);

        $this->assertSame('/custom/checkout', (new BridgeSupervisor)->bridgePath());
    }

    public function test_plugin_folder_ships_with_the_app(): void
    {
        $supervisor = new BridgeSupervisor;

        $this->assertFileExists($supervisor->pluginPath().'/manifest.json');
    }

    public function test_prefers_the_dependency_free_bundle(): void
    {
        $supervisor = new BridgeSupervisor;

        $this->assertStringEndsWith('server.bundle.js', $supervisor->serverScript());
        $this->assertFileExists($supervisor->serverScript());
        // The bundle carries its dependencies — no node_modules required.
        $this->assertTrue($supervisor->isInstalled());
        $this->assertStringContainsString('BRIDGE_TIMEOUT_MS', file_get_contents($supervisor->serverScript()));
    }

    public function test_explicit_node_binary_is_used_verbatim(): void
    {
        config(['wotf.node_binary' => '/custom/node']);

        [$binary, $env] = (new BridgeSupervisor)->nodeCommand();

        $this->assertSame('/custom/node', $binary);
        $this->assertSame([], $env);
    }

    public function test_electron_binary_runs_as_node_by_default(): void
    {
        config(['wotf.node_binary' => 'node']);

        $stub = sys_get_temp_dir().'/wotf-electron-'.uniqid();
        touch($stub);
        config(['wotf.electron_binary' => $stub]);

        try {
            [$binary, $env] = (new BridgeSupervisor)->nodeCommand();

            $this->assertSame($stub, $binary);
            $this->assertSame(['ELECTRON_RUN_AS_NODE' => '1'], $env);
        } finally {
            @unlink($stub);
        }
    }

    public function test_falls_back_to_the_dev_electron_install(): void
    {
        config(['wotf.node_binary' => 'node', 'wotf.electron_binary' => null]);

        [$binary, $env] = (new BridgeSupervisor)->nodeCommand();

        // native:install put a dev Electron in vendor — it should be found.
        $this->assertStringContainsString('Electron', $binary);
        $this->assertSame(['ELECTRON_RUN_AS_NODE' => '1'], $env);
    }

    public function test_vendored_server_honors_configurable_timeout(): void
    {
        $server = file_get_contents((new BridgeSupervisor)->serverScript());

        $this->assertStringContainsString('BRIDGE_TIMEOUT_MS', $server);
    }
}
