<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Import\ParsedImport;

final class StaticDataExporter
{
    /** @return array<string,mixed> */
    public function export(
        ParsedImport $parsed,
        string $sourceDate,
        string $generatedAt,
        string $sourcePageUrl,
        string $checksum,
    ): array {
        $stations = array_map(function (array $station): array {
            $identity = $this->key($station['brand']) . '|' . $this->key($station['address']);

            return [
                'id' => substr(hash('sha256', $identity), 0, 16),
                'name' => $station['brand'],
                'brand' => $station['brand'],
                'address' => $station['address'],
                'city' => $station['city'],
                'municipality' => $station['municipality'],
                'latitude' => null,
                'longitude' => null,
                'prices' => $station['prices'],
            ];
        }, $parsed->stations);

        usort($stations, static fn (array $left, array $right): int => [
            $left['brand'], $left['city'], $left['address'],
        ] <=> [
            $right['brand'], $right['city'], $right['address'],
        ]);

        return [
            'schema_version' => 1,
            'demo' => false,
            'source' => [
                'name' => 'Lietuvos energetikos agentūra (LEA)',
                'page_url' => $sourcePageUrl,
                'source_date' => $sourceDate,
                'generated_at' => $generatedAt,
                'checksum_sha256' => $checksum,
                'parser_version' => Import\LeaWorkbookParser::VERSION,
            ],
            'summary' => [
                'station_count' => count($stations),
                'price_count' => $parsed->priceCount(),
                'fuels' => $parsed->detectedFuelSlugs,
            ],
            'stations' => $stations,
        ];
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/\s+/u', ' ', $value);
    }
}
