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

            $address = trim((string) ($record['address'] ?? ''));
            $municipality = trim((string) ($record['municipality'] ?? ''));
            $company = trim((string) ($record['company_name'] ?? ''));
            $stationName = trim((string) ($record['gas_station_name'] ?? ''));
            $brand = $stationName !== '' ? $stationName : $company;
            $latitude = $this->coordinate($record['latitude'] ?? null);
            $longitude = $this->coordinate($record['longitude'] ?? null);
            $fuelType = trim((string) ($record['fuel_type'] ?? ''));
            $fuelSlug = self::FUEL_MAP[$fuelType] ?? null;
            $price = is_numeric($record['price'] ?? null) ? (float) $record['price'] : null;

            if ($brand === '' || $address === '') {
                $issues[] = "API įrašas {$rowNumber}: trūksta degalinės pavadinimo arba adreso.";
                continue;
            }
            if ($fuelSlug === null) {
                $issues[] = "API įrašas {$rowNumber}: neatpažintas degalų tipas „{$fuelType}“.";
                continue;
            }
            // LEA's `uuid` identifies the individual price submission, not the
            // physical station. Build a stable station identity from the public
            // station fields so all fuel types end up under one map marker.
            $stationKey = $this->stationKey(
                $company,
                $stationName,
                $municipality,
                $address,
                $latitude,
                $longitude,
            );
            $detectedFuels[$fuelSlug] = true;
            if (!isset($stations[$stationKey])) {
                $stations[$stationKey] = [
                    'source_id' => $stationKey,
                    'brand' => $brand,
                    'address' => $address,
                    'city' => $this->deriveCity($address, $municipality),
                    'municipality' => $municipality !== '' ? $municipality : null,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'prices' => [],
                    'price_updated_at' => [],
                    'unavailable_fuels' => [],
                    '_submitted' => [],
                ];
            }

            $submittedAt = trim((string) ($record['submitted_at'] ?? ''));
            $previousSubmittedAt = $stations[$stationKey]['_submitted'][$fuelSlug] ?? null;
            if (
                array_key_exists($fuelSlug, $stations[$stationKey]['_submitted'])
                && is_string($previousSubmittedAt)
                && $submittedAt <= $previousSubmittedAt
            ) {
                continue;
            }

            $stations[$stationKey]['_submitted'][$fuelSlug] = $submittedAt;
            if ($price === null) {
                // The new LEA portal deliberately includes stations which sell
                // this fuel but have not submitted its current price.
                unset(
                    $stations[$stationKey]['prices'][$fuelSlug],
                    $stations[$stationKey]['price_updated_at'][$fuelSlug]
                );
                $stations[$stationKey]['unavailable_fuels'][$fuelSlug] = true;
                continue;
            }

            $stations[$stationKey]['prices'][$fuelSlug] = $price;
            $normalizedSubmittedAt = $this->submittedAt($submittedAt);
            if ($normalizedSubmittedAt !== null) {
                $stations[$stationKey]['price_updated_at'][$fuelSlug] = $normalizedSubmittedAt;
            }
            unset($stations[$stationKey]['unavailable_fuels'][$fuelSlug]);
        }

        foreach ($stations as &$station) {
            unset($station['_submitted']);
            $station['unavailable_fuels'] = array_keys($station['unavailable_fuels']);
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

    private function submittedAt(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('Europe/Vilnius'),
        );

        return $date === false ? null : $date->format(\DateTimeInterface::ATOM);
    }

    private function stationKey(
        string $company,
        string $stationName,
        string $municipality,
        string $address,
        ?float $latitude,
        ?float $longitude,
    ): string {
        $identity = implode('|', [
            $this->normalize($company),
            $this->normalize($stationName),
            $this->normalize($municipality),
            $this->normalize($address),
            $latitude === null ? '' : sprintf('%.7F', $latitude),
            $longitude === null ? '' : sprintf('%.7F', $longitude),
        ]);

        return substr(hash('sha256', $identity), 0, 32);
    }

    private function normalize(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($value)));
    }
}
