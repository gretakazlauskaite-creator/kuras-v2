<?php

declare(strict_types=1);

namespace App\Service\Import;

final class LeaLiveApiParser
{
    public const VERSION = 'lea-live-api-v1';

    /** @var array<string,string> */
    private const FUEL_MAP = [
        'benzinas_95' => 'pb95',
        'benzinas_98' => 'pb98',
        'dyzelinas' => 'diesel',
        'snd' => 'lpg',
    ];

    public function parse(string $json): LeaLiveApiSnapshot
    {
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('LEA API grąžino netinkamą JSON.', previous: $exception);
        }

        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            throw new \RuntimeException('LEA API atsakyme nerastas kainų masyvas.');
        }

        $lastUpdated = trim((string) ($payload['last_updated'] ?? ''));
        $updatedAt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $lastUpdated,
            new \DateTimeZone('Europe/Vilnius'),
        );
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (
            $updatedAt === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $updatedAt->format('Y-m-d H:i:s') !== $lastUpdated
        ) {
            throw new \RuntimeException('LEA API atsakyme nenurodytas tinkamas atnaujinimo laikas.');
        }

        $stations = [];
        $issues = [];
        $detectedFuels = [];

        foreach ($payload['data'] as $index => $record) {
            $rowNumber = $index + 1;
            if (!is_array($record)) {
                $issues[] = "API įrašas {$rowNumber}: tikėtasi objekto.";
                continue;
            }

            $stationUuid = trim((string) ($record['uuid'] ?? ''));
            $address = trim((string) ($record['address'] ?? ''));
            $municipality = trim((string) ($record['municipality'] ?? ''));
            $company = trim((string) ($record['company_name'] ?? ''));
            $stationName = trim((string) ($record['gas_station_name'] ?? ''));
            $brand = $stationName !== '' ? $stationName : $company;
            $fuelType = trim((string) ($record['fuel_type'] ?? ''));
            $fuelSlug = self::FUEL_MAP[$fuelType] ?? null;
            $price = is_numeric($record['price'] ?? null) ? (float) $record['price'] : null;

            if ($stationUuid === '' || $brand === '' || $address === '') {
                $issues[] = "API įrašas {$rowNumber}: trūksta degalinės identifikatoriaus, pavadinimo arba adreso.";
                continue;
            }
            if ($fuelSlug === null) {
                $issues[] = "API įrašas {$rowNumber}: neatpažintas degalų tipas „{$fuelType}“.";
                continue;
            }
            if ($price === null) {
                $issues[] = "API įrašas {$rowNumber}: kaina nėra skaičius.";
                continue;
            }

            $detectedFuels[$fuelSlug] = true;
            if (!isset($stations[$stationUuid])) {
                $stations[$stationUuid] = [
                    'source_id' => $stationUuid,
                    'brand' => $brand,
                    'address' => $address,
                    'city' => $this->deriveCity($address, $municipality),
                    'municipality' => $municipality !== '' ? $municipality : null,
                    'latitude' => $this->coordinate($record['latitude'] ?? null),
                    'longitude' => $this->coordinate($record['longitude'] ?? null),
                    'prices' => [],
                    '_submitted' => [],
                ];
            }

            $submittedAt = trim((string) ($record['submitted_at'] ?? ''));
            $previousSubmittedAt = $stations[$stationUuid]['_submitted'][$fuelSlug] ?? null;
            if (
                isset($stations[$stationUuid]['prices'][$fuelSlug])
                && is_string($previousSubmittedAt)
                && $submittedAt <= $previousSubmittedAt
            ) {
                continue;
            }

            $stations[$stationUuid]['prices'][$fuelSlug] = $price;
            $stations[$stationUuid]['_submitted'][$fuelSlug] = $submittedAt;
        }

        foreach ($stations as &$station) {
            unset($station['_submitted']);
        }
        unset($station);

        $fuelOrder = ['pb95', 'pb98', 'diesel', 'lpg'];
        $fuels = array_values(array_filter(
            $fuelOrder,
            static fn (string $slug): bool => isset($detectedFuels[$slug]),
        ));

        return new LeaLiveApiSnapshot(
            parsed: new ParsedImport(
                stations: array_values($stations),
                detectedFuelSlugs: $fuels,
                rawRowCount: count($payload['data']),
                issues: $issues,
                sourceDates: [$updatedAt->format('Y-m-d')],
            ),
            sourceDate: $updatedAt->format('Y-m-d'),
            lastUpdated: $updatedAt->format(\DateTimeInterface::ATOM),
        );
    }

    private function deriveCity(string $address, string $municipality): string
    {
        $firstPart = trim(explode(',', $address, 2)[0]);
        if ($firstPart !== '') {
            return $firstPart;
        }

        return trim((string) preg_replace('/\s+(?:m|r)\.\s+sav\.$/u', '', $municipality));
    }

    private function coordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
