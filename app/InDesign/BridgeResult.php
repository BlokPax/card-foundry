<?php

namespace App\InDesign;

final class BridgeResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(mixed $data): self
    {
        return new self(true, $data);
    }

    public static function failure(string $error): self
    {
        return new self(false, error: $error);
    }
}
