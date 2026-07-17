#!/usr/bin/env php
<?php
/**
 * Batch geocode all stations that lack coordinates.
 * Usage:  php bin/geocode.php [--limit=N] [--dry-run]
 * Nominatim rate-limit: 1 req/s — 700 stations ≈ 12 min
 */

require __DIR__ . '/../src/Bootstrap.php';

use App\Database;
use App\Repository\StationRepository;
use App\Service\GeocodingService;

$opts    = getopt('', ['limit:', 'dry-run']);
$limit   = (int)($opts['limit'] ?? 0);   // 0 = unlimited (loop until done)
$dryRun  = isset($opts['dry-run']);
$batchSz = 100;

$db         = Database::getInstance();
$stationRepo = new StationRepository();
$geocoder   = new GeocodingService();

function ts(): string { return '[' . date('H:i:s') . '] '; }

/**
 * Extract a searchable city name from Lithuanian municipality name.
 * "Alytaus m. sav." → "Alytus"   "Vilniaus m. sav." → "Vilnius"
 * "Klaipėdos m. sav." → "Klaipėda"  "Šiaulių m. sav." → "Šiauliai"
 * Falls back to removing trailing "sav." / "r. sav." etc.
 */
function extractMunicipalityCity(string $muni): string
{
    static $map = [
        'Vilniaus'     => 'Vilnius',
        'Kauno'        => 'Kaunas',
        'Klaipėdos'    => 'Klaipėda',
        'Šiaulių'      => 'Šiauliai',
        'Panevėžio'    => 'Panevėžys',
        'Alytaus'      => 'Alytus',
        'Marijampolės' => 'Marijampolė',
        'Druskininkų'  => 'Druskininkai',
        'Palangos'     => 'Palanga',
        'Neringos'     => 'Neringa',
        'Visagino'     => 'Visaginas',
        'Birštono'     => 'Birštonas',
    ];
    $first = explode(' ', trim($muni))[0];
    if (isset($map[$first])) return $map[$first];
    // Strip inflectional suffix + "sav." / "r. sav." → return first word as-is
    return $first;
}

$total = (int)$db->query("SELECT COUNT(*) FROM stations WHERE lat IS NULL")->fetchColumn();
echo ts() . "Stations without coordinates: $total\n";

if ($total === 0) {
    echo ts() . "Nothing to do.\n";
    exit(0);
}

$done = 0;
$failed = 0;
$skippedIds = []; // IDs that failed geocoding — exclude from next batch

do {
    $fetchSize = ($limit > 0) ? min($batchSz, $limit - $done) : $batchSz;
    $stations  = $stationRepo->findWithoutCoordinates($fetchSize, $skippedIds);
    if (empty($stations)) break;

    foreach ($stations as $s) {
        // Detect bad city extraction: starts with space or looks like a street
        $city = trim($s['city'] ?? '');
        $cityLooksWrong = empty($city) || str_ends_with($city, ' g.') || str_ends_with($city, 'g.');

        // Primary: full address (already contains city for most stations)
        $query = $s['address'] . ', Lithuania';

        echo ts() . "#{$s['id']} {$s['name']} — {$query} … ";

        if ($dryRun) {
            echo "[dry-run]\n";
            $done++;
            continue;
        }

        $coords = $geocoder->geocode($query);

        // Fallback 1: city + Lithuania (if city looks valid and differs from address)
        if (!$coords && !$cityLooksWrong && $city !== $s['address']) {
            sleep(1);
            $coords = $geocoder->geocode($city . ', Lithuania');
        }

        // Fallback 2: extract city from municipality (e.g. "Alytaus m. sav." → "Alytus")
        if (!$coords && !empty($s['municipality'])) {
            sleep(1);
            $muniCity = extractMunicipalityCity($s['municipality']);
            if ($muniCity) {
                $coords = $geocoder->geocode($muniCity . ', Lithuania');
            }
        }

        if ($coords) {
            $stationRepo->updateCoordinates($s['id'], $coords['lat'], $coords['lng']);
            echo "✓ {$coords['lat']}, {$coords['lng']}\n";
            $done++;
        } else {
            echo "✗ not found\n";
            $skippedIds[] = $s['id'];
            $failed++;
        }

        sleep(1);
    }

    if ($limit > 0 && $done >= $limit) break;

} while (count($stations) === $fetchSize);

echo ts() . "Done. Geocoded: $done, Failed: $failed\n";
