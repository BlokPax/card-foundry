<?php

namespace Tests\Feature;

use App\Google\GoogleAuth;
use App\Merge\GoogleSheetFetcher;
use App\Merge\MergeParseException;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-user Google sign-in: sheet downloads act AS the signed-in person,
 * so the sheet's own share list decides who can read it — access travels
 * with the identity, not with the app.
 */
class GoogleOAuthTest extends TestCase
{
    private const URL = 'https://docs.google.com/spreadsheets/d/locked-sheet/edit#gid=0';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        config(['services.google.client_id' => 'client-123.apps.googleusercontent.com']);
        config(['services.google.client_secret' => 'shhh']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    private function connect(int $expiresIn = 3600): void
    {
        Settings::put('google.refreshToken', 'refresh-1');
        Settings::put('google.accessToken', 'access-1');
        Settings::put('google.expiresAt', time() + $expiresIn);
        Settings::put('google.email', 'jim@example.com');
    }

    public function test_connected_downloads_use_the_sheets_api_with_the_users_token(): void
    {
        $this->connect();
        $this->fakeSheetsApi();

        (new GoogleSheetFetcher)->localize(self::URL);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sheets.googleapis.com')
            && $r->header('Authorization') === ['Bearer access-1']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'docs.google.com'));
    }

    private function fakeSheetsApi(array $extra = []): void
    {
        Http::fake($extra + [
            'sheets.googleapis.com/v4/spreadsheets/locked-sheet?*' => Http::response([
                'sheets' => [['properties' => ['sheetId' => 7, 'title' => 'Cards']]],
            ]),
            'sheets.googleapis.com/v4/spreadsheets/locked-sheet/values/*' => Http::response([
                'values' => [['Name', '@Image'], ['Alpha', 'TBC001']],
            ]),
        ]);
    }

    public function test_an_expired_token_refreshes_first(): void
    {
        $this->connect(expiresIn: -10);
        $this->fakeSheetsApi([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'expires_in' => 3600]),
        ]);

        (new GoogleSheetFetcher)->localize(self::URL);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            && str_contains($r->body(), 'grant_type=refresh_token')
            && str_contains($r->body(), 'refresh-1'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sheets.googleapis.com')
            && $r->header('Authorization') === ['Bearer access-2']);
        $this->assertSame('access-2', Settings::get('google.accessToken'));
    }

    public function test_without_a_connection_downloads_stay_anonymous(): void
    {
        Http::fake(['docs.google.com/*' => Http::response("Name\nOne\n")]);

        (new GoogleSheetFetcher)->localize(self::URL);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'docs.google.com')
            && $r->header('Authorization') === []);
    }

    public function test_a_denied_sheet_names_the_signed_in_user(): void
    {
        $this->connect();
        Http::fake(['sheets.googleapis.com/*' => Http::response([
            'error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission'],
        ], 403)]);

        $this->expectException(MergeParseException::class);
        $this->expectExceptionMessageMatches('/jim@example\.com/');

        (new GoogleSheetFetcher)->localize(self::URL);
    }

    public function test_a_failed_refresh_says_to_reconnect(): void
    {
        $this->connect(expiresIn: -10);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(MergeParseException::class);
        $this->expectExceptionMessageMatches('/Preferences/');

        (new GoogleSheetFetcher)->localize(self::URL);
    }

    public function test_exchange_code_stores_tokens_and_identity(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
            ]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'printer@partner.com']),
        ]);

        $email = (new GoogleAuth)->exchangeCode('auth-code', 'http://127.0.0.1:8734/', 'verifier-xyz');

        $this->assertSame('printer@partner.com', $email);
        $this->assertSame('refresh-1', Settings::get('google.refreshToken'));
        $this->assertSame('printer@partner.com', Settings::get('google.email'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            && str_contains($r->body(), 'grant_type=authorization_code')
            && str_contains($r->body(), 'code_verifier=verifier-xyz'));
    }

    public function test_disconnect_clears_the_stored_identity(): void
    {
        $this->connect();
        Http::fake(['oauth2.googleapis.com/*' => Http::response('ok')]);

        GoogleAuth::disconnect();

        $this->assertFalse(GoogleAuth::connected());
        $this->assertNull(Settings::get('google.refreshToken'));
        $this->assertNull(Settings::get('google.email'));
    }

    public function test_the_auth_url_carries_pkce_and_offline_access(): void
    {
        $url = (new GoogleAuth)->authUrl('http://127.0.0.1:8734/', 'state-1', 'verifier-xyz');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('client_id=client-123.apps.googleusercontent.com', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('spreadsheets.readonly', $url);
        $this->assertStringContainsString('state=state-1', $url);
    }
}
