<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class LeaLiveApiSnapshot
{
    public function __construct(
        public ParsedImport $parsed,
        public string $sourceDate,
        public string $lastUpdated,
    ) {
    }
}
