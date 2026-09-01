<?php

namespace Tests\Unit;

use App\Export\CsvPreparer;
use App\Merge\ArtResolver;
use App\Merge\MergeFileParser;
use PHPUnit\Framework\TestCase;

class CsvPreparerTest extends TestCase
{
    private string $artDir;

    private string $outDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/wotf-prep-'.uniqid();
        mkdir($this->artDir = $base.'-art');
        mkdir($this->outDir = $base.'-out');
    }

    protected function tearDown(): void
    {
        foreach ([$this->artDir, $this->outDir] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function prepare(array $refs = [], ?string $csv = null): string
    {
        $csv ??= "Name,Type,Card Code,Text Box,@Image\n"
            ."Ashbound,Wonder,4/398,\"He said \"\"go\"\" and it's done\",TBC197\n"
            ."Ali,Wonder,2/398,Plain text,TBC060\n";

        $source = $this->outDir.'/source.csv';
        file_put_contents($source, $csv);

        touch($this->artDir.'/TBC197.jpg');
        touch($this->artDir.'/TBC060.jpg');

        $merge = (new MergeFileParser)->parse($source);
        $resolver = new ArtResolver([$this->artDir], ['jpg', 'psd']);
        $out = $this->outDir.'/prepared.csv';

        (new CsvPreparer)->prepare($merge, $resolver, $out, $refs);

        return $out;
    }

    public function test_writes_utf8_without_bom(): void
    {
        $bytes = file_get_contents($this->prepare());

        $this->assertFalse(str_starts_with($bytes, "\u{FEFF}"));
        $this->assertTrue(mb_check_encoding($bytes, 'UTF-8'));
    }

    public function test_rewrites_image_columns_to_absolute_paths(): void
    {
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), file($this->prepare()));

        $imageIndex = array_search('@Image', $rows[0], true);
        $this->assertSame($this->artDir.'/TBC197.jpg', $rows[1][$imageIndex]);
    }

    public function test_filters_to_selected_refs_preserving_sheet_order(): void
    {
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), file($this->prepare(['TBC060'])));

        $this->assertCount(2, $rows); // header + one card
        $this->assertSame('Ali', $rows[1][0]);
    }

    public function test_smartens_quotes_outside_image_columns(): void
    {
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), file($this->prepare()));

        $textIndex = array_search('Text Box', $rows[0], true);
        $this->assertSame('He said “go” and it’s done', $rows[1][$textIndex]);
    }

    public function test_image_columns_are_never_smartened(): void
    {
        $content = file_get_contents($this->prepare());

        $this->assertStringContainsString('TBC197.jpg', $content);
        $this->assertStringNotContainsString('’', basename($this->artDir));
    }

    public function test_unresolvable_art_throws(): void
    {
        $csv = "Name,Type,Card Code,@Image\nGhost,Wonder,9/398,NOPE999\n";

        $this->expectExceptionMessage('NOPE999');
        $this->prepare([], $csv);
    }
}
