<?php

declare(strict_types=1);

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class LeaWorkbookParser
{
    public const VERSION = 'lea-xlsx-v4';

    /** @var array<string,list<string>> */
    private const COLUMN_ALIASES = [
        'brand' => ['įmonė', 'degalinių tinklas', 'tinklas', 'brand', 'kompanija'],
        'address' => ['vieta (gyvenvietė, gatvė)', 'gyvenvietė, gatvė', 'adresas', 'address'],
        'city' => ['miestas', 'city', 'town'],
        'municipality' => ['vieta (savivaldybė)', 'savivaldybė', 'municipality', 'rajonas'],
        'fuel_type' => ['degalų tipas', 'kuro tipas', 'fuel type'],
        'price' => ['kaina (eur/l)', 'kaina eur/l', 'kaina', 'price'],
        'source_date' => ['pateikimo data', 'duomenų data', 'data', 'date'],
        'pb95' => ['pb 95', 'pb95', 'benzinas a95', 'benzinas 95', '95 benzinas', 'a95', '95'],
        'pb98' => ['pb 98', 'pb98', 'benzinas a98', 'benzinas 98', '98 benzinas', 'a98', '98'],
        'diesel' => ['dyzelinas', 'dyzelinai', 'diesel', 'diz', 'on'],
        'lpg' => ['suskystintos naftos dujos', 'suskystintosios naftos dujos', 'autodujos', 'dujos', 'lpg', 'snd'],
    ];

    /** @var array<string,string> */
    private const MUNICIPALITY_CITY_MAP = [
        'Vilniaus m. sav.' => 'Vilnius',
        'Kauno m. sav.' => 'Kaunas',
        'Klaipėdos m. sav.' => 'Klaipėda',
        'Šiaulių m. sav.' => 'Šiauliai',
        'Panevėžio m. sav.' => 'Panevėžys',
        'Alytaus m. sav.' => 'Alytus',
        'Marijampolės sav.' => 'Marijampolė',
    ];

    /** @var array<string,string> */
    private const CITY_ALIASES = [
        'panevežys' => 'Panevėžys',
    ];

    /** @var list<string> */
    private const FUEL_SLUGS = ['pb95', 'pb98', 'diesel', 'lpg'];

    public function parse(string $filePath): ParsedImport
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("LEA failas nerastas arba neperskaitomas: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, true);
        $spreadsheet->disconnectWorksheets();

        if ($rows === []) {
            throw new \RuntimeException('LEA Excel failas yra tuščias.');
        }

        [$headerIndex, $columnMap] = $this->detectHeaderAndColumns($rows);
        if (isset($columnMap['fuel_type'], $columnMap['price'])) {
            return $this->parseLongFormat($rows, $headerIndex, $columnMap);
        }

        return $this->parseWideFormat($rows, $headerIndex, $columnMap);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $columnMap
     */
    private function parseLongFormat(array $rows, int $headerIndex, array $columnMap): ParsedImport
    {
        $groupedStations = [];
        $detectedFuelSlugs = [];
        $sourceDates = [];
        $issues = [];
        $rawRowCount = 0;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerIndex) {
                continue;
            }

            $cells = $this->normalizeCells($row);
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            $brand = $this->normalizeBrand($this->cell($cells, $columnMap, 'brand'));
            $address = $this->cell($cells, $columnMap, 'address');
            $municipality = $this->normalizeMunicipality($this->cell($cells, $columnMap, 'municipality'));
            $city = $this->deriveCity($this->cell($cells, $columnMap, 'city'), $address, $municipality);
            $fuelLabel = $this->cell($cells, $columnMap, 'fuel_type');
            $priceRaw = $this->cell($cells, $columnMap, 'price');
            $sourceDateRaw = $this->cell($cells, $columnMap, 'source_date');
            if ($fuelLabel === '' && $priceRaw === '' && $sourceDateRaw === '') {
                continue;
            }

            ++$rawRowCount;
            $fuelSlug = $this->mapFuelLabel($fuelLabel);
            $price = $this->parsePrice($priceRaw);
            $sourceDate = $this->parseSourceDate($sourceDateRaw);

            if ($sourceDate !== null) {
                $sourceDates[$sourceDate] = true;
            } elseif (isset($columnMap['source_date'])) {
                $issues[] = "Eilutė {$rowIndex}: nepavyko perskaityti pateikimo datos.";
            }

            if ($fuelSlug === null) {
                $issues[] = "Eilutė {$rowIndex}: neatpažintas degalų tipas „{$fuelLabel}“.";
                continue;
            }
            if ($price === null) {
                $issues[] = "Eilutė {$rowIndex}: {$fuelSlug} kaina „{$priceRaw}“ nėra skaičius.";
                continue;
            }

            $detectedFuelSlugs[$fuelSlug] = true;
            $stationKey = $this->key($brand) . '|' . $this->key($address);
            if (!isset($groupedStations[$stationKey])) {
                $groupedStations[$stationKey] = [
                    'brand' => $brand,
                    'address' => $address,
                    'city' => $city,
                    'municipality' => $municipality !== '' ? $municipality : null,
                    'prices' => [],
                ];
            }

            if (isset($groupedStations[$stationKey]['prices'][$fuelSlug])) {
                $existingPrice = $groupedStations[$stationKey]['prices'][$fuelSlug];
                if (abs($existingPrice - $price) < 0.000001) {
                    continue;
                }
                $issues[] = sprintf(
                    'Eilutė %d: pasikartojanti %s kaina nesutampa (%s / %s: %.3f ir %.3f).',
                    $rowIndex,
                    $fuelSlug,
                    $brand,
                    $address,
                    $existingPrice,
                    $price,
                );
                continue;
            }
            $groupedStations[$stationKey]['prices'][$fuelSlug] = $price;
        }

        return new ParsedImport(
            stations: array_values($groupedStations),
            detectedFuelSlugs: $this->orderedFuelSlugs(array_keys($detectedFuelSlugs)),
            rawRowCount: $rawRowCount,
            issues: $issues,
            sourceDates: array_keys($sourceDates),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $columnMap
     */
    private function parseWideFormat(array $rows, int $headerIndex, array $columnMap): ParsedImport
    {
        $detectedFuelSlugs = array_values(array_intersect(self::FUEL_SLUGS, array_keys($columnMap)));
        $stations = [];
        $sourceDates = [];
        $issues = [];
        $rawRowCount = 0;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerIndex) {
                continue;
            }

            $cells = $this->normalizeCells($row);
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            ++$rawRowCount;
            $brand = $this->normalizeBrand($this->cell($cells, $columnMap, 'brand'));
            $address = $this->cell($cells, $columnMap, 'address');
            $municipality = $this->normalizeMunicipality($this->cell($cells, $columnMap, 'municipality'));
            $city = $this->deriveCity($this->cell($cells, $columnMap, 'city'), $address, $municipality);
            $prices = [];

            $sourceDate = $this->parseSourceDate($this->cell($cells, $columnMap, 'source_date'));
            if ($sourceDate !== null) {
                $sourceDates[$sourceDate] = true;
            }

            foreach ($detectedFuelSlugs as $slug) {
                $rawPrice = $this->cell($cells, $columnMap, $slug);
                if ($rawPrice === '') {
                    continue;
                }

                $price = $this->parsePrice($rawPrice);
                if ($price === null) {
                    $issues[] = "Eilutė {$rowIndex}: {$slug} kaina „{$rawPrice}“ nėra skaičius.";
                    continue;
                }
                $prices[$slug] = $price;
            }

            $stations[] = [
                'brand' => $brand,
                'address' => $address,
                'city' => $city,
                'municipality' => $municipality !== '' ? $municipality : null,
                'prices' => $prices,
            ];
        }

        return new ParsedImport(
            stations: $stations,
            detectedFuelSlugs: $detectedFuelSlugs,
            rawRowCount: $rawRowCount,
            issues: $issues,
            sourceDates: array_keys($sourceDates),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{int,array<string,string>}
     */
    private function detectHeaderAndColumns(array $rows): array
    {
        $bestIndex = null;
        $bestMap = [];

        foreach (array_slice($rows, 0, 30, true) as $rowIndex => $row) {
            $map = $this->detectColumns($row);
            if (count($map) > count($bestMap)) {
                $bestIndex = (int) $rowIndex;
                $bestMap = $map;
            }
        }

        $hasIdentity = isset($bestMap['brand'], $bestMap['address']);
        $hasLongPrices = isset($bestMap['fuel_type'], $bestMap['price']);
        $hasWidePrices = array_intersect(self::FUEL_SLUGS, array_keys($bestMap)) !== [];
        if ($bestIndex === null || !$hasIdentity || (!$hasLongPrices && !$hasWidePrices)) {
            throw new \RuntimeException('LEA Excel faile nepavyko patikimai aptikti antraštės ir kainų stulpelių.');
        }

        return [$bestIndex, $bestMap];
    }

    /** @param array<string,mixed> $headerRow @return array<string,string> */
    private function detectColumns(array $headerRow): array
    {
        $columnMap = [];

        foreach ($headerRow as $column => $rawHeader) {
            $header = $this->normalizeHeader((string) ($rawHeader ?? ''));
            if ($header === '') {
                continue;
            }

            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                if (isset($columnMap[$field])) {
                    continue;
                }

                foreach ($aliases as $alias) {
                    if ($this->headerMatches($header, $this->normalizeHeader($alias))) {
                        $columnMap[$field] = (string) $column;
                        break;
                    }
                }
            }
        }

        return $columnMap;
    }

    private function headerMatches(string $header, string $alias): bool
    {
        if ($header === $alias) {
            return true;
        }

        return mb_strlen($alias) >= 4 && str_contains($header, $alias);
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["\u{00A0}", "\n", "\r", "\t"], ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @param array<string,mixed> $row @return array<string,string> */
    private function normalizeCells(array $row): array
    {
        return array_map(
            static fn (mixed $value): string => trim(str_replace("\u{00A0}", ' ', (string) ($value ?? ''))),
            $row,
        );
    }

    /** @param array<string,string> $cells */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,string> $cells @param array<string,string> $columnMap */
    private function cell(array $cells, array $columnMap, string $field): string
    {
        $column = $columnMap[$field] ?? null;
        return $column === null ? '' : ($cells[$column] ?? '');
    }

    private function mapFuelLabel(string $label): ?string
    {
        $label = $this->normalizeHeader($label);
        if (preg_match('/(?:pb\s*98|a98|\b98\s+benzinas\b|\bbenzinas\s+98\b)/u', $label)) {
            return 'pb98';
        }
        if (preg_match('/(?:pb\s*95|a95|\b95\s+benzinas\b|\bbenzinas\s+95\b)/u', $label)) {
            return 'pb95';
        }
        if (str_contains($label, 'dyzel') || $label === 'diesel' || $label === 'on') {
            return 'diesel';
        }
        if ($label === 'snd' || $label === 'lpg' || str_contains($label, 'dujos')) {
            return 'lpg';
        }

        return null;
    }

    /** @param list<string> $slugs @return list<string> */
    private function orderedFuelSlugs(array $slugs): array
    {
        return array_values(array_filter(
            self::FUEL_SLUGS,
            static fn (string $slug): bool => in_array($slug, $slugs, true),
        ));
    }

    private function parsePrice(string $raw): ?float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($raw));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
    }

    private function parseSourceDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                return ExcelDate::excelToDateTimeObject(
                    (float) $raw,
                    new \DateTimeZone('Europe/Vilnius'),
                )->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^(?<date>\d{4}-\d{2}-\d{2})(?:T|$)/', $raw, $match)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match['date']);
            if ($date !== false && $date->format('Y-m-d') === $match['date']) {
                return $match['date'];
            }
        }

        foreach (['!Y-m-d', '!Y.m.d', '!d.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw, new \DateTimeZone('Europe/Vilnius'));
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeMunicipality(string $value): string
    {
        if (str_contains($value, ',')) {
            return trim(explode(',', $value, 2)[0]);
        }

        return trim($value);
    }

    private function deriveCity(string $city, string $address, string $municipality): string
    {
        $city = $this->normalizeCity($city);
        if ($city !== '') {
            return $city;
        }

        $addressParts = array_values(array_filter(
            array_map('trim', explode(',', $address)),
            static fn (string $part): bool => $part !== '',
        ));
        foreach ($addressParts as $part) {
            if (preg_match('/^(.+?\s(?:k|vs|mstl)\.)(?:\s|$)/ui', $part, $match)) {
                return $this->normalizeCity($match[1]);
            }
            if (preg_match('/^(.+?\s(?:kaimas|viensėdis|miestelis))(?:\s|$)/ui', $part, $match)) {
                return $this->normalizeCity($match[1]);
            }
        }

        $candidate = $addressParts[0] ?? '';
        $looksLikeStreet = (bool) preg_match('/\d|\b(g|pr|al|pl|šosin|plentas|gatv)\b\.?/ui', $candidate);
        if ($candidate !== '' && mb_strtolower($candidate) !== 'lietuva' && !$looksLikeStreet) {
            return $this->normalizeCity($candidate);
        }

        $fallback = self::MUNICIPALITY_CITY_MAP[$municipality]
            ?? trim((string) preg_replace('/\s*(r\.\s*sav\.|m\.\s*sav\.|sav\.)\s*$/ui', '', $municipality));

        return $this->normalizeCity($fallback);
    }

    private function normalizeCity(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        return self::CITY_ALIASES[mb_strtolower($value)] ?? $value;
    }

    private function normalizeBrand(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $canonical = [
            'circle k' => 'Circle K',
            'circlek' => 'Circle K',
            'viada' => 'Viada',
            'neste' => 'Neste',
            'lukoil' => 'Lukoil',
            'orlen' => 'Orlen',
            'baltic petroleum' => 'Baltic Petroleum',
            'e.on' => 'E.ON',
        ];

        $lower = mb_strtolower($raw);
        return $canonical[$lower] ?? $raw;
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/\s+/u', ' ', $value);
    }
}
