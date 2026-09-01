<?php

namespace Tests\Feature;

use App\Merge\GoogleSheetFetcher;
use App\Merge\MergeParseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSheetFetcherTest extends TestCase
{
    private const URL = 'https://docs.google.com/spreadsheets/d/1AbC-123_xyz/edit#gid=42';

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/merge-cache'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/merge-cache'));

        parent::tearDown();
    }

    public function test_recognizes_google_sheet_urls(): void
    {
        $this->assertTrue(GoogleSheetFetcher::isSheetUrl(self::URL));
        $this->assertTrue(GoogleSheetFetcher::isSheetUrl('https://docs.google.com/spreadsheets/d/xyz/view'));
        $this->assertFalse(GoogleSheetFetcher::isSheetUrl('/Users/x/merge.csv'));
        $this->assertFalse(GoogleSheetFetcher::isSheetUrl('https://example.com/merge.csv'));
    }

    public function test_local_paths_pass_through_untouched(): void
    {
        $this->assertSame('/Users/x/merge.csv', (new GoogleSheetFetcher)->localize('/Users/x/merge.csv'));
    }

    public function test_downloads_the_sheet_as_csv_and_returns_a_local_path(): void
    {
        Http::fake(['docs.google.com/*' => Http::response("Name,@Image\nAlpha,TBC001\n")]);

        $path = (new GoogleSheetFetcher)->localize(self::URL);

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.csv', $path);
        $this->assertStringContainsString('Alpha,TBC001', file_get_contents($path));

        // The CSV export endpoint for the sheet's document id and tab (gid).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/spreadsheets/d/1AbC-123_xyz/export')
            && str_contains($r->url(), 'format=csv')
            && str_contains($r->url(), 'gid=42'));
    }

    public function test_a_fresh_copy_is_fetched_when_the_cache_is_stale(): void
    {
        Http::fake(['docs.google.com/*' => Http::response("Name\nOne\n")]);

        $fetcher = new GoogleSheetFetcher;
        $fetcher->localize(self::URL, maxAgeSeconds: 3600);
        $fetcher->localize(self::URL, maxAgeSeconds: 3600); // cache hit
        Http::assertSentCount(1);

        $fetcher->localize(self::URL); // maxAge 0 = always refetch
        Http::assertSentCount(2);
    }

    public function test_a_private_sheet_login_page_is_a_clear_error(): void
    {
        Http::fake(['docs.google.com/*' => Http::response('<!DOCTYPE html><html>Sign in</html>')]);

        $this->expectException(MergeParseException::class);
        $this->expectExceptionMessageMatches('/[Aa]nyone with the link/');

        (new GoogleSheetFetcher)->localize(self::URL);
    }

    public function test_a_failed_download_is_a_clear_error(): void
    {
        Http::fake(['docs.google.com/*' => Http::response('nope', 404)]);

        $this->expectException(MergeParseException::class);

        (new GoogleSheetFetcher)->localize(self::URL);
    }

    public function test_non_google_urls_are_rejected(): void
    {
        $this->expectException(MergeParseException::class);

        (new GoogleSheetFetcher)->localize('https://example.com/merge.csv');
    }
}
