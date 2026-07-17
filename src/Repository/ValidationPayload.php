<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class ValidationPayload
{
    /** @param array<string,mixed> $report */
    public function __construct(
        public bool $valid,
        public int $rawRows,
        public int $stations,
        public int $prices,
        public array $report,
    ) {
    }
}
