<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Import\ParsedImport;

final class StaticDataExporter
{
    /** @param array<string,array<string,mixed>> $coordinates */
    public function __construct(private readonly array $coordinates = [])
    {
    }

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
            $id = substr(hash('sha256', $identity), 0, 16);
            $coordinate = $this->validCoordinate($this->coordinates[$id] ?? null);

            return [
                'id' => $id,
                'name' => $station['brand'],
                'brand' => $station['brand'],
                'address' => $station['address'],
                'city' => $station['city'],
                'municipality' => $station['municipality'],
                'latitude' => $coordinate['latitude'] ?? null,
                'longitude' => $coordinate['longitude'] ?? null,
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
                'coordinate_count' => count(array_filter(
                    $stations,
                    static fn (array $station): bool => $station['latitude'] !== null && $station['longitude'] !== null,
                )),
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

    /**
     * @param mixed $coordinate
     * @return array{latitude:float,longitude:float}|null
     */
    private function validCoordinate(mixed $coordinate): ?array
    {
        if (!is_array($coordinate) || !is_numeric($coordinate['latitude'] ?? null) || !is_numeric($coordinate['longitude'] ?? null)) {
            return null;
        }

        $latitude = (float) $coordinate['latitude'];
        $longitude = (float) $coordinate['longitude'];
        if ($latitude < 53.8 || $latitude > 56.5 || $longitude < 20.6 || $longitude > 26.9) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }
}
