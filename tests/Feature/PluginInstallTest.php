<?php

namespace Tests\Feature;

use App\InDesign\BridgeSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $stub;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/wotf-upia-'.uniqid();
        mkdir($dir);
        $this->log = $dir.'/args.log';
        $this->stub = $dir.'/upia';
        file_put_contents($this->stub, "#!/bin/sh\necho \"$@\" > {$this->log}\necho 'Installed 1 of 1'\n");
        chmod($this->stub, 0755);
    }

    protected function tearDown(): void
    {
        @unlink($this->stub);
        @unlink($this->log);
        @rmdir(dirname($this->stub));

        parent::tearDown();
    }

    public function test_the_ccx_ships_with_the_app(): void
    {
        $this->assertFileExists((new BridgeSupervisor)->pluginPackage());
    }

    public function test_install_plugin_uses_upia_when_available(): void
    {
        config(['wotf.upia_path' => $this->stub]);

        $this->artisan('bridge:install-plugin')->assertExitCode(0);

        $args = trim(file_get_contents($this->log));
        $this->assertStringContainsString('--install', $args);
        $this->assertStringContainsString('com.ads.indesign-bridge_ID.ccx', $args);
    }

    public function test_plugin_installed_detection_parses_upia_list(): void
    {
        $listing = dirname($this->stub).'/upia-list';
        file_put_contents($listing, "#!/bin/sh\necho 'com.ads.indesign-bridge InDesign Bridge 1.0.0'\n");
        chmod($listing, 0755);
        config(['wotf.upia_path' => $listing]);

        $this->assertTrue((new BridgeSupervisor)->pluginInstalled());

        file_put_contents($listing, "#!/bin/sh\necho 'No plugins installed'\n");
        $this->assertFalse((new BridgeSupervisor)->pluginInstalled());

        config(['wotf.upia_path' => '/nonexistent/upia']);
        $this->assertNull((new BridgeSupervisor)->pluginInstalled());

        @unlink($listing);
    }

    public function test_setup_wizard_reports_step_states(): void
    {
        config(['wotf.upia_path' => '/nonexistent/upia']);

        \Livewire\Livewire::test(\App\Livewire\ControlPlane::class)
            ->call('openSetupWizard')
            ->assertSet('showSetupWizard', true)
            ->set('bridge', ['installed' => true, 'up' => true, 'indesign' => false, 'detail' => null])
            ->assertSee('Set up InDesign')
            ->assertSee('Window → Plugins → InDesign Bridge');
    }

    public function test_missing_upia_fails_with_guidance_when_open_is_unavailable(): void
    {
        config(['wotf.upia_path' => '/nonexistent/upia', 'wotf.open_binary' => '/nonexistent/open']);

        $this->artisan('bridge:install-plugin')
            ->expectsOutputToContain('.ccx')
            ->assertExitCode(1);
    }
}
