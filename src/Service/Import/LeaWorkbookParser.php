<?php

declare(strict_types=1);

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class LeaWorkbookParser
{
    public const VERSION = 'lea-xlsx-v1';

    /** @var array<string,list<string>> */
    private const COLUMN_ALIASES = [
        'brand' => ['įmonė', 'degalinių tinklas', 'tinklas', 'brand', 'kompanija'],
        'address' => ['vieta (gyvenvietė, gatvė)', 'gyvenvietė, gatvė', 'adresas', 'address'],
        'city' => ['miestas', 'city', 'town'],
        'municipality' => ['vieta (savivaldybė)', 'savivaldybė', 'municipality', 'rajonas'],
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
        $detectedFuelSlugs = array_values(array_intersect(self::FUEL_SLUGS, array_keys($columnMap)));
        $stations = [];
        $issues = [];
        $rawRowCount = 0;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerIndex) {
                continue;
            }

            $cells = array_map(static fn (mixed $value): string => trim((string) ($value ?? '')), $row);
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            ++$rawRowCount;
            $brand = $this->normalizeBrand($this->cell($cells, $columnMap, 'brand'));
            $address = $this->cell($cells, $columnMap, 'address');
            $municipality = $this->normalizeMunicipality($this->cell($cells, $columnMap, 'municipality'));
            $city = $this->cell($cells, $columnMap, 'city');
            $city = $this->deriveCity($city, $address, $municipality);
            $prices = [];

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

        return new ParsedImport($stations, $detectedFuelSlugs, $rawRowCount, $issues);
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

        if ($bestIndex === null || !isset($bestMap['brand'], $bestMap['address'])) {
            throw new \RuntimeException('LEA Excel faile nepavyko patikimai aptikti antraštės ir degalinės stulpelių.');
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

        // Short aliases such as 95, 98 and ON must only match the whole header.
        return mb_strlen($alias) >= 4 && str_contains($header, $alias);
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["\u{00A0}", "\n", "\r", "\t"], ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
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

    private function parsePrice(string $raw): ?float
    {
        $normalized = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($raw));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
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
        $city = trim($city);
        if ($city !== '') {
            return $city;
        }

        $candidate = trim(explode(',', $address, 2)[0]);
        $looksLikeStreet = (bool) preg_match('/\d|\b(g|pr|al|pl|šosin|plentas|gatv)\b\.?/ui', $candidate);
        if ($candidate !== '' && mb_strtolower($candidate) !== 'lietuva' && !$looksLikeStreet) {
            return $candidate;
        }

        return self::MUNICIPALITY_CITY_MAP[$municipality]
            ?? trim((string) preg_replace('/\s*(r\.\s*sav\.|m\.\s*sav\.|sav\.)\s*$/ui', '', $municipality));
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
}
