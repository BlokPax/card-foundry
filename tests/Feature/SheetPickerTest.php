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

    private function fakeSheetsApi(string $id, array $values): void
    {
        Http::fake([
            "sheets.googleapis.com/v4/spreadsheets/{$id}?*" => Http::response([
                'sheets' => [['properties' => ['sheetId' => 3, 'title' => 'Cards']]],
            ]),
            "sheets.googleapis.com/v4/spreadsheets/{$id}/values/*" => Http::response(['values' => $values]),
        ]);
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

    public function test_an_open_set_can_switch_to_a_picked_sheet(): void
    {
        $this->connect();
        $this->fakeSheetsApi('other-sheet-42', [['Name', 'Type', 'Card Code', 'Set Name', '@Image'], ['Beta', 'Wonder', '2/398', 'TBC', 'TBC002']]);

        $dir = sys_get_temp_dir().'/wotf-swap-'.uniqid();
        mkdir($dir.'/.foundry', 0755, true);
        copy(base_path('tests/Fixtures/merge-sample.csv'), $dir.'/merge.csv');
        file_put_contents($dir.'/.foundry/config.json', json_encode([
            'version' => 2, 'set' => 'TBC', 'base' => ['merge' => 'merge.csv'], 'profiles' => [],
        ]));

        try {
            $component = Livewire::test(ControlPlane::class)
                ->set('manifestPath', $dir.'/.foundry/config.json')
                ->assertSet('summary.cards', 5) // the local merge, before the switch
                ->call('sheetPicked', 'other-sheet-42', 'Replacement')
                ->assertSet('mergePath', 'https://docs.google.com/spreadsheets/d/other-sheet-42/edit')
                ->assertSet('summary.cards', 1);

            // A picked sheet is an override like any merge change — the set
            // file keeps its merge until the user saves…
            $this->assertSame(
                'merge.csv',
                json_decode(file_get_contents($dir.'/.foundry/config.json'), true)['base']['merge'],
            );

            // …and Save writes the sheet URL into the set.
            $component->call('saveToManifest');
            $this->assertSame(
                'https://docs.google.com/spreadsheets/d/other-sheet-42/edit',
                json_decode(file_get_contents($dir.'/.foundry/config.json'), true)['base']['merge'],
            );
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_a_picked_sheet_becomes_the_linked_merge(): void
    {
        $this->connect();
        $this->fakeSheetsApi('sheet-id-77', [['Name', 'Type', 'Card Code', 'Set Name', '@Image'], ['Alpha', 'Wonder', '1/398', 'Picked Set', 'TBC001']]);

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
