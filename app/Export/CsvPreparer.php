<?php

namespace App\Export;

use App\Merge\ArtResolver;
use App\Merge\MergeFile;
use RuntimeException;

/**
 * Writes the CSV that is actually handed to InDesign's Data Merge:
 * filtered to the cards being built, UTF-8 without BOM, typographer's
 * quotes (ports fix_csv_for_indesign.py's smarten pass), and every
 * @-image column resolved to an absolute path.
 */
final class CsvPreparer
{
    /**
     * @param  list<string>  $refs  cards to include (@Image / Card Code / Name); empty = all
     * @return string the output path
     */
    public function prepare(MergeFile $merge, ArtResolver $resolver, string $outputPath, array $refs = []): string
    {
        $records = $refs === []
            ? $merge->records
            : array_values(array_filter(
                $merge->records,
                fn ($record) => array_any($refs, fn ($ref) => $record->matches($ref)),
            ));

        if ($records === []) {
            throw new RuntimeException('No cards matched the selection.');
        }

        $handle = fopen($outputPath, 'w');
        if ($handle === false) {
            throw new RuntimeException("Cannot write prepared CSV: {$outputPath}");
        }

        try {
            fputcsv($handle, $merge->headers, escape: '');

            foreach ($records as $record) {
                $row = [];

                foreach ($merge->headers as $header) {
                    $value = trim((string) ($record->fields[$header] ?? ''));

                    if (str_starts_with($header, '@')) {
                        // Image columns: absolute path or hard failure — a bad
                        // path surfaces as a modal inside InDesign otherwise.
                        if ($value === '') {
                            $row[] = '';
                        } else {
                            $resolved = $resolver->resolve($value);
                            if ($resolved === null) {
                                throw new RuntimeException(
                                    "\"{$record->name()}\" (row {$record->row}): artwork \"{$value}\" not found in any art directory.",
                                );
                            }
                            $row[] = $resolved;
                        }
                    } else {
                        $row[] = $this->smarten($value);
                    }
                }

                fputcsv($handle, $row, escape: '');
            }
        } finally {
            fclose($handle);
        }

        return $outputPath;
    }

    /**
     * Straight quotes → typographer's quotes, matching fix_csv_for_indesign.py:
     * double quotes alternate open/close per field; an apostrophe closes after
     * a letter/digit, opens before one, and defaults to a right quote.
     */
    private function smarten(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $doubleOpen = true;

        foreach ($chars as $i => $char) {
            if ($char === '"') {
                $out .= $doubleOpen ? '“' : '”';
                $doubleOpen = ! $doubleOpen;

                continue;
            }

            if ($char === "'") {
                $prev = $chars[$i - 1] ?? '';
                $next = $chars[$i + 1] ?? '';

                if ($prev !== '' && preg_match('/[\p{L}\p{N}]/u', $prev)) {
                    $out .= '’';
                } elseif ($next !== '' && preg_match('/[\p{L}\p{N}]/u', $next)) {
                    $out .= '‘';
                } else {
                    $out .= '’';
                }

                continue;
            }

            $out .= $char;
        }

        return $out;
    }
}
