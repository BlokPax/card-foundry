<?php

namespace App\Merge;

final class MergeFile
{
    /**
     * @param  list<string>  $headers
     * @param  list<CardRecord>  $records
     */
    public function __construct(
        public readonly string $path,
        public readonly array $headers,
        public readonly array $records,
    ) {}

    public function count(): int
    {
        return count($this->records);
    }

    public function findByImageRef(string $ref): ?CardRecord
    {
        foreach ($this->records as $record) {
            if ($record->matches($ref)) {
                return $record;
            }
        }

        return null;
    }

    public function setName(): string
    {
        return ($this->records[0] ?? null)?->get('Set Name') ?? '';
    }
}
