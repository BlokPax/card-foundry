<?php

namespace App\Merge;

final class MergeLinter
{
    private const REQUIRED_COLUMNS = ['Name', 'Type', 'Card Code', '@Image'];

    private const TEXT_COLUMNS = ['Text Box', 'Power Text'];

    // The seven orbitals the template knows. Anything else — including the
    // document's historical "Sulfera" misspelling — is a hard error.
    public const ORBITALS = ['Petraia', 'Thalwind', 'Solfera', 'Umbrathene', 'Heliosynth', 'Boundless', 'Pinwheel'];

    private const DEFAULT_ART_EXTENSIONS = ['psd', 'psb', 'tif', 'tiff', 'png', 'jpg', 'jpeg', 'ai', 'eps', 'pdf'];

    /** @var list<string> */
    private readonly array $artExtensions;

    /**
     * @param  list<string>|null  $artExtensions
     */
    public function __construct(?array $artExtensions = null)
    {
        $this->artExtensions = $artExtensions ?? self::DEFAULT_ART_EXTENSIONS;
    }

    /**
     * @return list<LintIssue>
     */
    /**
     * @param  list<string>|null  $artDirectories  searched in order
     */
    public function lint(MergeFile $file, ?array $artDirectories = null): array
    {
        $issues = [];
        $resolver = $artDirectories ? new ArtResolver($artDirectories, $this->artExtensions) : null;

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! in_array($column, $file->headers, true)) {
                $issues[] = new LintIssue(LintSeverity::Error, "Missing required column \"{$column}\".", column: $column, rule: 'missing-column');
            }
        }

        // Header shapes that break InDesign's Data Merge outright.
        foreach ($file->headers as $header) {
            if ($header === '') {
                $issues[] = new LintIssue(LintSeverity::Error, 'Empty column header — Data Merge rejects it.', rule: 'empty-header');
            } elseif (str_contains($header, ':') || str_contains($header, '/')) {
                $issues[] = new LintIssue(LintSeverity::Error, "Column \"{$header}\" contains ':' or '/' — Data Merge rejects it.", column: $header, rule: 'illegal-header');
            } elseif (strcasecmp($header, 'table') === 0) {
                $issues[] = new LintIssue(LintSeverity::Error, "Column \"{$header}\" — \"table\" is reserved by Data Merge.", column: $header, rule: 'reserved-header');
            }
        }

        $duplicateHeaders = array_diff_assoc($file->headers, array_unique($file->headers));
        foreach (array_unique($duplicateHeaders) as $header) {
            $issues[] = new LintIssue(LintSeverity::Error, "Duplicate column \"{$header}\".", column: $header, rule: 'duplicate-column');
        }

        $seenCodes = [];
        $seenImages = [];
        $denominators = [];

        foreach ($file->records as $record) {
            $issues = [...$issues, ...$this->lintRecord($record, $file, $resolver)];

            if (($code = $record->cardCode()) !== '') {
                if (isset($seenCodes[$code])) {
                    $issues[] = new LintIssue(
                        LintSeverity::Error,
                        "Duplicate card code \"{$code}\" (also on row {$seenCodes[$code]}).",
                        row: $record->row,
                        column: 'Card Code', rule: 'duplicate-code');
                } else {
                    $seenCodes[$code] = $record->row;
                }

                if (preg_match('#^\d+/(\d+)$#', $code, $m)) {
                    $denominators[$m[1]][] = $record->row;
                }
            }

            if (($image = $record->imageRef()) !== '') {
                if (isset($seenImages[$image])) {
                    $issues[] = new LintIssue(
                        LintSeverity::Warning,
                        "Duplicate @Image \"{$image}\" (also on row {$seenImages[$image]}).",
                        row: $record->row,
                        column: '@Image', rule: 'duplicate-image');
                } else {
                    $seenImages[$image] = $record->row;
                }
            }
        }

        if (count($denominators) > 1) {
            $counts = array_map(count(...), $denominators);
            arsort($counts);
            $majority = array_key_first($counts);
            foreach ($denominators as $denominator => $rows) {
                if ((string) $denominator === (string) $majority) {
                    continue;
                }
                foreach ($rows as $row) {
                    $issues[] = new LintIssue(
                        LintSeverity::Warning,
                        "Card code denominator \"/{$denominator}\" differs from the rest of the set (\"/{$majority}\").",
                        row: $row,
                        column: 'Card Code', rule: 'denominator');
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<LintIssue>  $issues
     */
    public function hasErrors(array $issues): bool
    {
        return array_any($issues, fn (LintIssue $issue) => $issue->severity === LintSeverity::Error);
    }

    /**
     * @return list<LintIssue>
     */
    private function lintRecord(CardRecord $record, MergeFile $file, ?ArtResolver $resolver): array
    {
        $issues = [];

        $orbital = $record->get('Orbital Symbol');
        if (in_array('Orbital Symbol', $file->headers, true) && $orbital !== '' && ! in_array($orbital, self::ORBITALS, true)) {
            $suggestion = $this->closestOrbital($orbital);
            $issues[] = new LintIssue(
                LintSeverity::Error,
                "\"{$record->name()}\" has unknown orbital \"{$orbital}\"."
                    .($suggestion !== null ? " Did you mean \"{$suggestion}\"?" : ''),
                row: $record->row,
                column: 'Orbital Symbol',
                rule: 'unknown-orbital',
            );
        }

        if (in_array('Name', $file->headers, true) && $record->name() === '') {
            $issues[] = new LintIssue(LintSeverity::Error, "Row {$record->row} is missing a Name.", row: $record->row, column: 'Name', rule: 'missing-name');
        }

        if (str_starts_with($record->imageRef(), '/')) {
            $issues[] = new LintIssue(LintSeverity::Warning,
                "\"{$record->name()}\": @Image is a full path — machine-specific; prefer just the filename "
                ."(\"".basename($record->imageRef())."\"), hunted in the art folders.",
                row: $record->row, column: '@Image', rule: 'absolute-image-ref');
        }

        if (in_array('@Image', $file->headers, true) && $record->imageRef() === '') {
            $issues[] = new LintIssue(LintSeverity::Error, "\"{$record->name()}\" has no @Image reference.", row: $record->row, column: '@Image', rule: 'missing-image-ref');
        }

        if (in_array('Type', $file->headers, true) && $record->type() === '') {
            $issues[] = new LintIssue(LintSeverity::Warning, "\"{$record->name()}\" has no Type.", row: $record->row, column: 'Type', rule: 'missing-type');
        }

        // Costs are numeric, or a named token like "feather" — flag anything else.
        $cost = $record->get('Cost');
        if (in_array('Cost', $file->headers, true) && $cost !== '' && ! is_numeric($cost) && ! preg_match('/^[A-Za-z]+$/', $cost)) {
            $issues[] = new LintIssue(
                LintSeverity::Warning,
                "\"{$record->name()}\" has an unrecognized Cost \"{$cost}\".",
                row: $record->row,
                column: 'Cost', rule: 'bad-cost');
        }

        foreach (self::TEXT_COLUMNS as $column) {
            if (! in_array($column, $file->headers, true)) {
                continue;
            }

            $text = $record->get($column);

            if (substr_count($text, 'BEGINITAL') !== substr_count($text, 'ENDITAL')) {
                $issues[] = new LintIssue(
                    LintSeverity::Error,
                    "\"{$record->name()}\": unbalanced BEGINITAL/ENDITAL markers in {$column}.",
                    row: $record->row,
                    column: $column, rule: 'unbalanced-italics');
            }

            // A "*LB" or "LB*" fragment that is not part of a full "*LB*" marker.
            if (preg_match('/(?<!\*)\*LB(?!\*)|(?<!\*)LB\*(?!\S*\*)/', str_replace('*LB*', '', $text))) {
                $issues[] = new LintIssue(
                    LintSeverity::Warning,
                    "\"{$record->name()}\": malformed line-break marker in {$column} (expected \"*LB*\").",
                    row: $record->row,
                    column: $column, rule: 'bad-linebreak');
            }
        }

        if ($resolver !== null && $record->imageRef() !== '') {
            $matches = $resolver->resolveAll($record->imageRef());

            if ($matches === []) {
                $extensions = implode('/', $this->artExtensions);
                $issues[] = new LintIssue(
                    LintSeverity::Error,
                    "\"{$record->name()}\": artwork \"{$record->imageRef()}\" not found in any art directory (looked for .{$extensions}).",
                    row: $record->row,
                    column: '@Image', rule: 'art-not-found');
            } elseif (count($matches) > 1) {
                $issues[] = new LintIssue(
                    LintSeverity::Warning,
                    "\"{$record->name()}\": artwork \"{$record->imageRef()}\" exists in multiple art directories — using \"{$matches[0]}\".",
                    row: $record->row,
                    column: '@Image', rule: 'art-ambiguous');
            }

            // Resolved, but only via the suffix fallback — a different export
            // than the merge names. Fine for previews; a print run exported
            // with a specific format/profile should hear about it.
            if ($matches !== [] && $resolver->usedSuffixFallback($record->imageRef(), $matches[0])) {
                $issues[] = new LintIssue(
                    LintSeverity::Warning,
                    "\"{$record->name()}\": exact artwork \"{$record->imageRef()}\" isn't in the art folders — using \"".basename($matches[0]).'" instead (different format).',
                    row: $record->row,
                    column: '@Image', rule: 'art-suffix-fallback');
            }
        }

        return $issues;
    }

    private function closestOrbital(string $value): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::ORBITALS as $orbital) {
            $distance = levenshtein(strtolower($value), strtolower($orbital));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $orbital;
            }
        }

        return $bestDistance <= 3 ? $best : null;
    }
}
