<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class ParsedImport
{
    /**
     * @param list<array{
     *   source_id?:string,
     *   brand:string,
     *   address:string,
     *   city:string,
     *   municipality:?string,
     *   latitude?:?float,
     *   longitude?:?float,
     *   prices:array<string,float>,
     *   price_updated_at?:array<string,string>,
     *   unavailable_fuels?:list<string>
     * }> $stations
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
