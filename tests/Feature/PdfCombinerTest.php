<?php

namespace Tests\Feature;

use App\Export\PdfCombiner;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class PdfCombinerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wotf-pdf-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function requireNode(): void
    {
        $node = trim((string) shell_exec('command -v node'));

        if ($node === '') {
            $this->markTestSkipped('No node binary on this machine.');
        }

        config(['wotf.node_binary' => $node]);
    }

    /**
     * A structurally valid single-page PDF, offsets computed for real.
     */
    private function minimalPdf(string $path): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 283] >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        file_put_contents($path, $pdf);

        return $path;
    }

    public function test_combines_pdfs_one_card_per_page(): void
    {
        $this->requireNode();

        $a = $this->minimalPdf($this->dir.'/a.pdf');
        $b = $this->minimalPdf($this->dir.'/b.pdf');
        $out = $this->dir.'/combined.pdf';

        (new PdfCombiner)->combine([$a, $b], $out);

        $this->assertFileExists($out);
        $this->assertStringContainsString('/Count 2', file_get_contents($out));
    }

    public function test_a_bad_input_is_a_clear_error(): void
    {
        $this->requireNode();

        file_put_contents($bad = $this->dir.'/bad.pdf', 'not a pdf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not read/');

        (new PdfCombiner)->combine([$bad], $this->dir.'/combined.pdf');
    }
}
