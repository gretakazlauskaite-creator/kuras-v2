<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class ImportResult
{
    public function __construct(
        public string $status,
        public string $sourceDate,
        public int $stationCount,
        public int $priceCount,
        public int $newStationCount,
        public ValidationResult $validation,
        public ?int $runId = null,
    ) {
    }

    public function wasPublished(): bool
    {
        return $this->status === 'published';
    }
}
