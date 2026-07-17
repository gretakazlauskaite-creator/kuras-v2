<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class ValidationResult
{
    /** @param list<string> $errors @param list<string> $warnings @param array<string,int|float|string> $metrics */
    public function __construct(
        public array $errors,
        public array $warnings,
        public array $metrics,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array{valid:bool,errors:list<string>,warnings:list<string>,metrics:array<string,int|float|string>} */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
        ];
    }
}
