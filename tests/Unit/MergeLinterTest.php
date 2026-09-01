<?php

namespace Tests\Unit;

use App\Merge\CardRecord;
use App\Merge\LintSeverity;
use App\Merge\MergeFile;
use App\Merge\MergeLinter;
use PHPUnit\Framework\TestCase;

class MergeLinterTest extends TestCase
{
    private MergeLinter $linter;

    private string $artDir;

    protected function setUp(): void
    {
        $this->linter = new MergeLinter;
        $this->artDir = sys_get_temp_dir().'/wotf-art-'.uniqid();
        mkdir($this->artDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->artDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->artDir);
    }

    private function makeFile(array $rows, ?array $headers = null): MergeFile
    {
        $headers ??= ['Name', 'Type', 'Cost', 'Text Box', 'Card Code', '@Image'];
        $records = [];
        foreach ($rows as $i => $fields) {
            $records[] = new CardRecord($i + 1, array_merge(array_fill_keys($headers, ''), $fields));
        }

        return new MergeFile('/tmp/fake.csv', $headers, $records);
    }

    private function goodRow(array $overrides = []): array
    {
        return array_merge([
            'Name' => 'Ashbound',
            'Type' => 'Wonder',
            'Cost' => '10',
            'Text Box' => 'FLYING*LB*BEGINITALQuote.ENDITAL',
            'Card Code' => '4/398',
            '@Image' => 'TBC197',
        ], $overrides);
    }

    private function messages(array $issues): array
    {
        return array_map(fn ($i) => $i->message, $issues);
    }

    private function assertHasIssueMatching(array $issues, string $needle, ?LintSeverity $severity = null): void
    {
        foreach ($issues as $issue) {
            if (str_contains(strtolower($issue->message), strtolower($needle))) {
                if ($severity === null || $issue->severity === $severity) {
                    $this->assertTrue(true);

                    return;
                }
            }
        }
        $this->fail("No issue matching '$needle' in: ".implode(' | ', $this->messages($issues)));
    }

    public function test_clean_file_has_no_issues(): void
    {
        touch($this->artDir.'/TBC197.psd');
        $file = $this->makeFile([$this->goodRow()]);

        $this->assertSame([], $this->linter->lint($file, [$this->artDir]));
    }

    public function test_missing_required_column_is_error(): void
    {
        $file = $this->makeFile([['Name' => 'X']], ['Name', 'Type']);
        $issues = $this->linter->lint($file);

        $this->assertHasIssueMatching($issues, 'missing required column', LintSeverity::Error);
        $this->assertTrue($this->linter->hasErrors($issues));
    }

    public function test_missing_name_and_image_are_errors(): void
    {
        $file = $this->makeFile([
            $this->goodRow(['Name' => '']),
            $this->goodRow(['@Image' => '', 'Card Code' => '5/398']),
        ]);
        $issues = $this->linter->lint($file);

        $this->assertHasIssueMatching($issues, 'missing a name', LintSeverity::Error);
        $this->assertHasIssueMatching($issues, '@image', LintSeverity::Error);
    }

    public function test_duplicate_card_code_is_error(): void
    {
        $file = $this->makeFile([
            $this->goodRow(),
            $this->goodRow(['Name' => 'Other', '@Image' => 'TBC198']),
        ]);

        $this->assertHasIssueMatching($this->linter->lint($file), 'duplicate card code', LintSeverity::Error);
    }

    public function test_duplicate_image_ref_is_warning(): void
    {
        $file = $this->makeFile([
            $this->goodRow(),
            $this->goodRow(['Name' => 'Other', 'Card Code' => '5/398']),
        ]);

        $this->assertHasIssueMatching($this->linter->lint($file), 'duplicate @image', LintSeverity::Warning);
    }

    public function test_unbalanced_italic_markers_is_error(): void
    {
        $file = $this->makeFile([$this->goodRow(['Text Box' => 'BEGINITALoops no end'])]);

        $this->assertHasIssueMatching($this->linter->lint($file), 'beginital', LintSeverity::Error);
    }

    public function test_malformed_linebreak_marker_is_warning(): void
    {
        $file = $this->makeFile([$this->goodRow(['Text Box' => 'Hello *LB world'])]);

        $this->assertHasIssueMatching($this->linter->lint($file), 'line-break', LintSeverity::Warning);
    }

    public function test_word_costs_like_feather_are_allowed(): void
    {
        $file = $this->makeFile([$this->goodRow(['Cost' => 'feather'])]);

        $this->assertSame([], $this->linter->lint($file));
    }

    public function test_garbage_cost_is_warning(): void
    {
        $file = $this->makeFile([$this->goodRow(['Cost' => '3x?'])]);

        $this->assertHasIssueMatching($this->linter->lint($file), 'cost', LintSeverity::Warning);
    }

    public function test_inconsistent_card_code_denominator_is_warning(): void
    {
        $file = $this->makeFile([
            $this->goodRow(),
            $this->goodRow(['Name' => 'Other', 'Card Code' => '5/400', '@Image' => 'TBC198']),
        ]);

        $this->assertHasIssueMatching($this->linter->lint($file), 'denominator', LintSeverity::Warning);
    }

    public function test_misspelled_orbital_is_error_with_suggestion(): void
    {
        $headers = ['Name', 'Type', 'Orbital Symbol', 'Card Code', '@Image'];
        $file = $this->makeFile([$this->goodRow(['Orbital Symbol' => 'Sulfera'])], $headers);

        $issues = $this->linter->lint($file);

        $this->assertHasIssueMatching($issues, 'sulfera', LintSeverity::Error);
        $this->assertHasIssueMatching($issues, 'Solfera', LintSeverity::Error);
    }

    public function test_unknown_orbital_is_error(): void
    {
        $headers = ['Name', 'Type', 'Orbital Symbol', 'Card Code', '@Image'];
        $file = $this->makeFile([$this->goodRow(['Orbital Symbol' => 'Fireheart'])], $headers);

        $this->assertHasIssueMatching($this->linter->lint($file), 'orbital', LintSeverity::Error);
    }

    public function test_valid_and_empty_orbitals_pass(): void
    {
        $headers = ['Name', 'Type', 'Orbital Symbol', 'Card Code', '@Image'];
        $file = $this->makeFile([
            $this->goodRow(['Orbital Symbol' => 'Heliosynth']),
            $this->goodRow(['Orbital Symbol' => '', 'Card Code' => '5/398', '@Image' => 'TBC198']),
        ], $headers);

        $this->assertSame([], $this->linter->lint($file));
    }

    public function test_merge_breaking_headers_are_errors(): void
    {
        $file = $this->makeFile([$this->goodRow()], ['Name', 'Type', 'Card Code', '@Image', 'Cost: Energy', 'A/B']);

        $issues = $this->linter->lint($file);

        $this->assertHasIssueMatching($issues, 'Cost: Energy', LintSeverity::Error);
        $this->assertHasIssueMatching($issues, 'A/B', LintSeverity::Error);
    }

    public function test_artwork_found_in_any_listed_directory(): void
    {
        $second = sys_get_temp_dir().'/wotf-art2-'.uniqid();
        mkdir($second);
        touch($second.'/TBC197.png');

        try {
            $file = $this->makeFile([$this->goodRow()]);
            $this->assertSame([], $this->linter->lint($file, [$this->artDir, $second]));
        } finally {
            @unlink($second.'/TBC197.png');
            @rmdir($second);
        }
    }

    public function test_missing_artwork_is_error_when_art_dir_given(): void
    {
        $file = $this->makeFile([$this->goodRow()]);
        $issues = $this->linter->lint($file, [$this->artDir]);

        $this->assertHasIssueMatching($issues, 'artwork', LintSeverity::Error);
    }

    public function test_artwork_matched_by_any_supported_extension(): void
    {
        touch($this->artDir.'/TBC197.png');
        $file = $this->makeFile([$this->goodRow()]);

        $this->assertSame([], $this->linter->lint($file, [$this->artDir]));
    }

    public function test_suffix_fallback_artwork_is_a_warning(): void
    {
        // The merge asks for the .jpg export; only a .png is there. It
        // resolves (previews keep working) but print runs must see it.
        touch($this->artDir.'/TBC197.png');
        $file = $this->makeFile([$this->goodRow(['@Image' => 'TBC197.jpg'])]);

        $issues = $this->linter->lint($file, [$this->artDir]);

        $this->assertCount(1, $issues);
        $this->assertSame('art-suffix-fallback', $issues[0]->rule);
        $this->assertSame(LintSeverity::Warning, $issues[0]->severity);
        $this->assertStringContainsString('TBC197.png', $issues[0]->message);
        $this->assertStringContainsString('TBC197.jpg', $issues[0]->message);
    }

    public function test_exact_suffix_match_is_not_flagged(): void
    {
        touch($this->artDir.'/TBC197.jpg');
        $file = $this->makeFile([$this->goodRow(['@Image' => 'TBC197.jpg'])]);

        $this->assertSame([], $this->linter->lint($file, [$this->artDir]));
    }

    public function test_no_artwork_check_without_art_dir(): void
    {
        $file = $this->makeFile([$this->goodRow()]);

        $this->assertSame([], $this->linter->lint($file));
    }
}
