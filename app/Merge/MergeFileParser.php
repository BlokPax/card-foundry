<?php

namespace App\Merge;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

final class MergeFileParser
{
    public function parse(string $path): MergeFile
    {
        if (! is_file($path)) {
            throw new MergeParseException("Merge file not found: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $reader = match ($extension) {
            'csv' => new CsvReader,
            'xlsx' => new XlsxReader,
            default => throw new MergeParseException("Unsupported merge file type \".{$extension}\" — use .csv or .xlsx."),
        };

        $headers = [];
        $records = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(
                        fn ($value) => is_scalar($value) ? (string) $value : '',
                        $row->toArray(),
                    );

                    if ($headers === []) {
                        $cells[0] = $this->stripBom($cells[0] ?? '');
                        $headers = array_map(trim(...), $cells);

                        continue;
                    }

                    if (trim(implode('', $cells)) === '') {
                        continue; // skip blank rows
                    }

                    $fields = [];
                    foreach ($headers as $i => $header) {
                        $fields[$header] = $cells[$i] ?? '';
                    }

                    $records[] = new CardRecord(count($records) + 1, $fields);
                }

                break; // first sheet only
            }
        } catch (MergeParseException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MergeParseException("Could not read merge file: {$e->getMessage()}", previous: $e);
        } finally {
            $reader->close();
        }

        if ($headers === [] || $headers === ['']) {
            throw new MergeParseException('Merge file is empty — no header row found.');
        }

        return new MergeFile($path, $headers, $records);
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\u{FEFF}") ? substr($value, 3) : $value;
    }
}
