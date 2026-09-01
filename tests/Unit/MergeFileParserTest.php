<?php

namespace Tests\Unit;

use App\Merge\MergeFileParser;
use App\Merge\MergeParseException;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use OpenSpout\Common\Entity\Row;
use PHPUnit\Framework\TestCase;

class MergeFileParserTest extends TestCase
{
    private MergeFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MergeFileParser;
    }

    private function fixture(string $name): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }

    public function test_parses_csv_headers_and_records(): void
    {
        $file = $this->parser->parse($this->fixture('merge-sample.csv'));

        $this->assertSame([
            'Name', 'Power', 'Power Text', 'Orbital Symbol', 'Core', 'Faction',
            'Restriction', 'Type', 'Lineage', 'Class', 'Cost', 'Text Box',
            'Card Code', 'Set Name', 'Rarity', '@Image',
        ], $file->headers);

        $this->assertCount(5, $file->records);

        $first = $file->records[0];
        $this->assertSame(1, $first->row);
        $this->assertSame('Aetheris AE 1', $first->name());
        $this->assertSame('1/398', $first->cardCode());
        $this->assertSame('TBC331', $first->imageRef());
        $this->assertSame('Wonder', $first->type());
        $this->assertSame('Neon Circuit Arena', $first->get('Faction'));
    }

    public function test_preserves_quoted_fields_with_commas_and_quotes(): void
    {
        $file = $this->parser->parse($this->fixture('merge-sample.csv'));

        $textBox = $file->records[0]->get('Text Box');
        $this->assertStringContainsString('"Stop your productivity', $textBox);
        $this->assertStringContainsString('*LB*', $textBox);
    }

    public function test_finds_record_by_image_ref(): void
    {
        $file = $this->parser->parse($this->fixture('merge-sample.csv'));

        $this->assertSame('Ashbound', $file->findByImageRef('TBC197')?->name());
        $this->assertNull($file->findByImageRef('TBC999'));
    }

    public function test_parses_xlsx(): void
    {
        $path = sys_get_temp_dir().'/merge-test-'.uniqid().'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'Type', 'Card Code', '@Image']));
        $writer->addRow(Row::fromValues(['Ashbound', 'Wonder', '4/398', 'TBC197']));
        $writer->close();

        try {
            $file = $this->parser->parse($path);
            $this->assertSame(['Name', 'Type', 'Card Code', '@Image'], $file->headers);
            $this->assertCount(1, $file->records);
            $this->assertSame('Ashbound', $file->records[0]->name());
        } finally {
            @unlink($path);
        }
    }

    public function test_throws_on_missing_file(): void
    {
        $this->expectException(MergeParseException::class);
        $this->parser->parse('/nonexistent/nope.csv');
    }

    public function test_throws_on_unsupported_extension(): void
    {
        $this->expectException(MergeParseException::class);
        $this->parser->parse(__FILE__);
    }

    public function test_throws_on_empty_file(): void
    {
        $path = sys_get_temp_dir().'/merge-empty-'.uniqid().'.csv';
        file_put_contents($path, '');

        try {
            $this->expectException(MergeParseException::class);
            $this->parser->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_strips_bom_and_skips_blank_rows(): void
    {
        $path = sys_get_temp_dir().'/merge-bom-'.uniqid().'.csv';
        file_put_contents($path, "\u{FEFF}Name,Type,Card Code,@Image\r\nAshbound,Wonder,4/398,TBC197\r\n,,,\r\n");

        try {
            $file = $this->parser->parse($path);
            $this->assertSame('Name', $file->headers[0]);
            $this->assertCount(1, $file->records);
        } finally {
            @unlink($path);
        }
    }
}
