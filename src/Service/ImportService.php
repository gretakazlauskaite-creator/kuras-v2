<?php

namespace App\Service;

use App\Database;
use App\Repository\StationRepository;
use App\Repository\PriceRepository;

class ImportService
{
    private \PDO $db;
    private StationRepository $stationRepo;
    private PriceRepository $priceRepo;
    private GeocodingService $geocoder;

    public int $importedPrices = 0;
    public int $newStations    = 0;

    // Known Lithuanian fuel column header patterns → our slugs
    private const FUEL_COLUMN_MAP = [
        'pb95'   => ['pb95', '95 benzinas', '95', 'petrol 95', 'benzinas 95', 'a95', '98 benzinas', 'pb98', '98'],
        'pb98'   => ['pb98', '98 benzinas', '98 petrol', 'petrol 98'],
        'diesel' => ['diesel', 'dyzelinas', 'diz', 'on', 'дизель', 'dyzelinai'],
        'lpg'    => ['lpg', 'snd', 'dujos', 'gaz', 'autodujos', 'suslėgtos', 'suskystintosios'],
    ];

    // Lithuanian city municipalities: municipality name → canonical city name.
    // These are fully urban municipalities, so every station inside is in that city.
    private const MUNICIPALITY_CITY_MAP = [
        'Vilniaus m. sav.'   => 'Vilnius',
        'Kauno m. sav.'      => 'Kaunas',
        'Klaipėdos m. sav.'  => 'Klaipėda',
        'Šiaulių m. sav.'    => 'Šiauliai',
        'Panevėžio m. sav.'  => 'Panevėžys',
        'Alytaus m. sav.'    => 'Alytus',
        'Marijampolės sav.'  => 'Marijampolė',
    ];

    public function __construct()
    {
        $this->db          = Database::getInstance();
        $this->stationRepo = new StationRepository();
        $this->priceRepo   = new PriceRepository();
        $this->geocoder    = new GeocodingService();
    }

    public function importFromFile(string $filePath, string $date, bool $dryRun = false): void
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, false, true); // raw values, keyed by column letters

        if (empty($rows)) {
            throw new \RuntimeException('Excel file appears empty.');
        }

        // Detect header row (first row with at least 3 non-empty cells)
        $headerRow = null;
        $headerIdx = null;
        foreach ($rows as $rowIdx => $row) {
            $nonEmpty = array_filter($row, fn($v) => $v !== null && $v !== '');
            if (count($nonEmpty) >= 3) {
                $headerRow = array_map(fn($v) => mb_strtolower(trim((string)$v)), $row);
                $headerIdx = $rowIdx;
                break;
            }
        }

        if ($headerRow === null) {
            throw new \RuntimeException('Could not detect header row in Excel.');
        }

        // Map column letters to field names
        $colMap = $this->detectColumns($headerRow);

        $fuelTypes = [];
        foreach ($this->priceRepo->getFuelTypes() as $ft) {
            $fuelTypes[$ft['slug']] = (int)$ft['id'];
        }

        foreach ($rows as $rowIdx => $row) {
            if ($rowIdx <= $headerIdx) continue;
            $cells = array_map(fn($v) => trim((string)($v ?? '')), $row);

            $brandName = $this->getCell($cells, $colMap, 'brand');
            $stAddress = $this->getCell($cells, $colMap, 'address');
            $stCity    = $this->getCell($cells, $colMap, 'city');
            $stMuni    = $this->getCell($cells, $colMap, 'municipality');

            if (empty($brandName) && empty($stAddress)) continue;

            // Municipality column contains "X sav., Seniūnija sen." — keep only the "X sav." part
            if (!empty($stMuni) && str_contains($stMuni, ',')) {
                $stMuni = trim(explode(',', $stMuni)[0]);
            }

            // The LEA address column format is "Locality, Street address".
            // Extract city from the FIRST comma-part (locality), not the last.
            if (empty($stCity) && !empty($stAddress)) {
                $parts  = array_map('trim', explode(',', $stAddress));
                $stCity = $parts[0];
                // Sanity-check: if the first part looks like a street (contains a digit or
                // street abbreviations), it has no locality prefix — fall back to municipality.
                if (
                    empty($stCity)
                    || mb_strtolower($stCity) === 'lietuva'
                    || preg_match('/\d|(\b(g|pr|al|pl|šosin|plentas|gatv)\b\.?)/ui', $stCity)
                ) {
                    // Known city municipality → use the canonical nominative city name.
                    // (Stripping "m. sav." suffix gives genitive form like "Vilniaus",
                    // not the correct nominative "Vilnius".)
                    $stCity = self::MUNICIPALITY_CITY_MAP[$stMuni]
                        ?? ($stMuni ? preg_replace('/\s*(r\.\s*sav\.|m\.\s*sav\.|sav\.)\s*$/ui', '', $stMuni) : '');
                }
            }

            // Normalize brand name
            $brandName = $this->normalizeBrand($brandName ?: 'Kita');

            if ($dryRun) {
                echo "  DRY: $brandName | $stAddress | $stCity | $stMuni\n";
                continue;
            }

            // Ensure brand exists
            $brandId = $this->upsertBrand($brandName);

            // Upsert station
            $isNew = false;
            $stationId = $this->stationRepo->upsertReturnNew([
                ':brand_id'     => $brandId,
                ':name'         => $brandName . ($stAddress ? ' — ' . $stAddress : ''),
                ':address'      => $stAddress,
                ':city'         => $stCity,
                ':municipality' => $stMuni ?: null,
            ], $isNew);

            if ($isNew) {
                $this->newStations++;
            }

            // Import fuel prices
            foreach (self::FUEL_COLUMN_MAP as $slug => $aliases) {
                $price = $this->getCell($cells, $colMap, $slug);
                if ($price === '' || $price === null) continue;

                // Raw float from PhpSpreadsheet, or European string "1,699"
                $priceFloat = is_numeric($price)
                    ? (float)$price
                    : (float)str_replace(',', '.', str_replace(' ', '', $price));
                if ($priceFloat < 0.1 || $priceFloat > 10) continue; // sanity check

                $fuelTypeId = $fuelTypes[$slug] ?? null;
                if ($fuelTypeId === null) continue;

                $this->priceRepo->insertPrice($stationId, $fuelTypeId, $priceFloat, $date);
                $this->importedPrices++;
            }
        }
    }

    private function detectColumns(array $headerRow): array
    {
        $colMap = [];

        $patterns = [
            'brand'        => ['įmonė', 'tinklas', 'brand', 'pavadinimas', 'name', 'компания', 'degalinių tinklas'],
            'address'      => ['gyvenvietė', 'gatvė', 'address', 'adresas', 'vieta (gyvenvietė', 'адрес', 'gyvenviet'],
            'city'         => ['city', 'miestas', 'town', 'город'],
            'municipality' => ['savivaldybė', 'municipality', 'rajonas', 'district', 'район', 'vieta (savivaldybė'],
        ];

        // Add fuel patterns
        foreach (self::FUEL_COLUMN_MAP as $slug => $aliases) {
            $patterns[$slug] = $aliases;
        }

        foreach ($headerRow as $colLetter => $header) {
            foreach ($patterns as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if (str_contains($header, $alias)) {
                        $colMap[$field] = $colLetter;
                        break 2;
                    }
                }
            }
        }

        return $colMap;
    }

    private function getCell(array $cells, array $colMap, string $field): string
    {
        $col = $colMap[$field] ?? null;
        if ($col === null) return '';
        return $cells[$col] ?? '';
    }

    private function normalizeBrand(string $raw): string
    {
        $map = [
            'circle k'  => 'Circle K',
            'circlek'   => 'Circle K',
            'viada'     => 'Viada',
            'neste'     => 'Neste',
            'lukoil'    => 'Lukoil',
            'orlen'     => 'Orlen',
            'baltic petroleum' => 'Baltic Petroleum',
            'e.on'      => 'E.ON',
        ];
        $lower = mb_strtolower(trim($raw));
        return $map[$lower] ?? ucfirst($lower);
    }

    private function upsertBrand(string $name): int
    {
        $stmt = $this->db->prepare('SELECT id FROM brands WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $existing = $stmt->fetchColumn();
        if ($existing) return (int)$existing;

        $stmt = $this->db->prepare('INSERT INTO brands (name) VALUES (:name)');
        $stmt->execute([':name' => $name]);
        return (int)$this->db->lastInsertId();
    }

    public function geocodeNewStations(): int
    {
        $stations = $this->stationRepo->findWithoutCoordinates(100);
        $count = 0;
        foreach ($stations as $station) {
            $query = $station['address'] . ', ' . $station['city'] . ', Lithuania';
            $coords = $this->geocoder->geocode($query);
            if ($coords) {
                $this->stationRepo->updateCoordinates($station['id'], $coords['lat'], $coords['lng']);
                $count++;
            }
            sleep(1); // Nominatim rate limit: 1 req/sec
        }
        return $count;
    }
}
