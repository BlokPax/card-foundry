<?php

namespace Tests\Feature;

use App\Merge\GoogleSheetFetcher;
use App\Merge\MergeParseException;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signed-in sheet reads go through the Sheets API, not the CSV-export
 * URL: the real tab list is fetched, the URL's gid picks a tab — and
 * with no gid the FIRST tab wins, whatever its id (gid 0 only exists
 * while a sheet's original first tab survives, so assuming it caused
 * HTTP 400s on real sheets).
 */
class SheetsApiFetchTest extends TestCase
{
    private const URL = 'https://docs.google.com/spreadsheets/d/api-sheet/edit';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        config(['services.google.client_id' => 'client-123', 'services.google.client_secret' => 'shhh']);

        Settings::put('google.refreshToken', 'refresh-1');
        Settings::put('google.accessToken', 'access-1');
        Settings::put('google.expiresAt', time() + 3600);
        Settings::put('google.email', 'jim@blokpax.com');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    private function fakeSheet(array $tabs, array $values): void
    {
        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/api-sheet?*' => Http::response([
                'sheets' => array_map(fn ($tab) => ['properties' => $tab], $tabs),
            ]),
            'sheets.googleapis.com/v4/spreadsheets/api-sheet/values/*' => Http::response(['values' => $values]),
        ]);
    }

    public function test_no_gid_reads_the_first_tab_whatever_its_id(): void
    {
        $this->fakeSheet(
            [['sheetId' => 588417, 'title' => 'Cards']], // no gid 0 anywhere
            [['Name', '@Image'], ['Alpha', 'TBC001']],
        );

        $path = (new GoogleSheetFetcher)->localize(self::URL);

        $this->assertStringContainsString("Name,@Image\nAlpha,TBC001", file_get_contents($path));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/values/')
            && str_contains($r->url(), rawurlencode("'Cards'"))
            && $r->header('Authorization') === ['Bearer access-1']);
    }

    public function test_an_explicit_gid_selects_its_tab_by_id_not_name(): void
    {
        $this->fakeSheet(
            [
                ['sheetId' => 588417, 'title' => 'Notes'],
                ['sheetId' => 999, 'title' => 'The Real Merge'],
            ],
            [['Name'], ['Alpha']],
        );

        (new GoogleSheetFetcher)->localize(self::URL.'#gid=999');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/values/')
            && str_contains($r->url(), rawurlencode("'The Real Merge'")));
    }

    public function test_an_explicit_gid_that_does_not_exist_lists_the_tabs(): void
    {
        $this->fakeSheet([['sheetId' => 588417, 'title' => 'Cards']], []);

        $this->expectException(MergeParseException::class);
        $this->expectExceptionMessageMatches('/Cards/');

        (new GoogleSheetFetcher)->localize(self::URL.'#gid=12345');
    }

    public function test_ragged_rows_pad_to_the_header_width(): void
    {
        $this->fakeSheet(
            [['sheetId' => 1, 'title' => 'Cards']],
            [['Name', 'Type', '@Image'], ['Alpha', 'Wonder'], ['Beta']], // trailing cells omitted by the API
        );

        $path = (new GoogleSheetFetcher)->localize(self::URL);

        $this->assertSame("Name,Type,@Image\nAlpha,Wonder,\nBeta,,\n", file_get_contents($path));
    }

    public function test_a_disabled_sheets_api_gets_a_setup_pointer(): void
    {
        Http::fake([
            'sheets.googleapis.com/*' => Http::response([
                'error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Google Sheets API has not been used in project 123 before or it is disabled.'],
            ], 403),
        ]);

        $this->expectException(MergeParseException::class);
        $this->expectExceptionMessageMatches('/Sheets API.*enable/is');

        (new GoogleSheetFetcher)->localize(self::URL);
    }

    public function test_a_denied_sheet_still_names_the_signed_in_user(): void
    {
        Http::fake([
            'sheets.googleapis.com/*' => Http::response([
                'error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission'],
            ], 403),
        ]);

        $this->expectException(MergeParseException::class);
        $this->expectExceptionMessageMatches('/jim@blokpax\.com/');

        (new GoogleSheetFetcher)->localize(self::URL);
    }
}
