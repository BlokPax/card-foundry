<?php

namespace Tests\Feature;

use App\Livewire\ControlPlane;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "New set from Google Sheet": the Sets screen opens the Google Picker
 * (browsing Drive AS the signed-in user), and the picked spreadsheet
 * becomes the linked merge file.
 */
class SheetPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        config(['services.google.client_id' => 'client-123']);
        config(['services.google.client_secret' => 'shhh']);
        config(['services.google.api_key' => 'AIza-key']);
        config(['services.google.app_id' => '123456789']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    private function connect(): void
    {
        Settings::put('google.refreshToken', 'refresh-1');
        Settings::put('google.accessToken', 'access-1');
        Settings::put('google.expiresAt', time() + 3600);
        Settings::put('google.email', 'jim@example.com');
        Settings::put('google.scopes', \App\Google\GoogleAuth::SCOPES);
    }

    public function test_the_picker_opens_with_the_users_token(): void
    {
        $this->connect();

        Livewire::test(ControlPlane::class)
            ->call('openSheetPicker')
            ->assertDispatched('open-google-picker',
                token: 'access-1',
                apiKey: 'AIza-key',
                appId: '123456789',
            );
    }

    public function test_without_a_google_connection_the_picker_points_at_preferences(): void
    {
        $component = Livewire::test(ControlPlane::class)
            ->call('openSheetPicker')
            ->assertNotDispatched('open-google-picker');

        $this->assertStringContainsString('Preferences', (string) $component->get('flash'));
    }

    public function test_a_connection_missing_the_picker_scope_asks_to_reconnect(): void
    {
        $this->connect();
        Settings::put('google.scopes', 'openid email https://www.googleapis.com/auth/spreadsheets.readonly');

        $component = Livewire::test(ControlPlane::class)
            ->call('openSheetPicker')
            ->assertNotDispatched('open-google-picker');

        $this->assertStringContainsString('econnect', (string) $component->get('flash'));
    }

    public function test_without_an_api_key_the_picker_explains_the_setup(): void
    {
        $this->connect();
        config(['services.google.api_key' => '']);

        $component = Livewire::test(ControlPlane::class)
            ->call('openSheetPicker')
            ->assertNotDispatched('open-google-picker');

        $this->assertStringContainsString('GOOGLE_API_KEY', (string) $component->get('flash'));
    }

    public function test_a_picked_sheet_becomes_the_linked_merge(): void
    {
        $this->connect();
        Http::fake([
            'docs.google.com/*' => Http::response("Name,Type,Card Code,Set Name,@Image\nAlpha,Wonder,1/398,Picked Set,TBC001\n"),
        ]);

        Livewire::test(ControlPlane::class)
            ->call('startCreateFromSheet')
            ->assertSet('showCreateWizard', true)
            ->assertDispatched('open-google-picker')
            ->call('sheetPicked', 'sheet-id-77', 'TBC Handoff')
            ->assertSet('mergePath', 'https://docs.google.com/spreadsheets/d/sheet-id-77/edit')
            ->assertSet('summary.cards', 1)
            // The wizard can continue on a linked sheet (no local file).
            ->call('createSetContinue')
            ->assertSet('createStep', 2)
            ->assertSet('setName', 'Picked Set');
    }
}
