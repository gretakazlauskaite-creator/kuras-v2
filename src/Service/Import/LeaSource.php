<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class LeaSource
{
    public function __construct(
        public string $pageUrl,
        public string $downloadUrl,
        public string $sourceDate,
    ) {
    }
}
