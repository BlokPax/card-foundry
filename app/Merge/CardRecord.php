<?php

namespace App\Merge;

final class CardRecord
{
    /**
     * @param  int  $row  1-based data row number (header excluded)
     * @param  array<string, string>  $fields  values keyed by header
     */
    public function __construct(
        public readonly int $row,
        public readonly array $fields,
    ) {}

    public function get(string $key): string
    {
        return trim((string) ($this->fields[$key] ?? ''));
    }

    public function name(): string
    {
        return $this->get('Name');
    }

    public function type(): string
    {
        return $this->get('Type');
    }

    public function cardCode(): string
    {
        return $this->get('Card Code');
    }

    public function imageRef(): string
    {
        return $this->get('@Image');
    }

    public function matches(string $ref): bool
    {
        $ref = trim($ref);

        return strcasecmp($this->imageRef(), $ref) === 0
            || strcasecmp($this->cardCode(), $ref) === 0
            || strcasecmp($this->name(), $ref) === 0;
    }
}
