<?php

namespace App\Merge;

final class LintIssue
{
    public function __construct(
        public readonly LintSeverity $severity,
        public readonly string $message,
        public readonly ?int $row = null,
        public readonly ?string $column = null,
        public readonly string $rule = '',
    ) {}

    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'message' => $this->message,
            'row' => $this->row,
            'column' => $this->column,
            'rule' => $this->rule,
        ];
    }
}
