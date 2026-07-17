<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class ParsedImport
{
    /**
     * @param list<array{brand:string,address:string,city:string,municipality:?string,prices:array<string,float>}> $stations
     * @param list<string> $detectedFuelSlugs
     * @param list<string> $issues
     * @param list<string> $sourceDates
     */
    public function __construct(
        public array $stations,
        public array $detectedFuelSlugs,
        public int $rawRowCount,
        public array $issues = [],
        public array $sourceDates = [],
    ) {
    }

    public function priceCount(): int
    {
        return array_sum(array_map(
            static fn (array $station): int => count($station['prices']),
            $this->stations,
        ));
    }
}
