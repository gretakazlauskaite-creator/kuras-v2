<?php

namespace App\Controller\Api;

use App\Repository\StationRepository;
use App\Repository\PriceRepository;

class StationsApiController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        $repo      = new StationRepository();
        $priceRepo = new PriceRepository();
        $priceDate = $priceRepo->getLatestPriceDate();

        // Cities for a given municipality (cascading filter)
        if (isset($_GET['cities_for_municipality'])) {
            $muni = trim($_GET['cities_for_municipality']);
            echo json_encode($muni ? $repo->getCitiesByMunicipality($muni) : []);
            return;
        }

        // Brands available in the current municipality + fuel context
        if (isset($_GET['available_brands'])) {
            $muni     = trim($_GET['municipality'] ?? '');
            $fuelSlug = trim($_GET['fuel'] ?? '');
            echo json_encode($repo->getAvailableBrands($muni, $fuelSlug, $priceDate));
            return;
        }

        $fuel   = $_GET['fuel']   ?? '';
        $city   = $_GET['city']   ?? '';
        $brand  = $_GET['brand']  ?? '';
        $muni   = $_GET['municipality'] ?? '';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $bounds = $_GET['bounds'] ?? '';  // "minLng,minLat,maxLng,maxLat"

        // Map view: return GeoJSON within bounds
        if ($bounds) {
            [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', explode(',', $bounds));
            $fuelSlug = $fuel ?: 'pb95';
            $stations = $repo->findInBounds($minLat, $minLng, $maxLat, $maxLng, $fuelSlug, 500, $priceDate);

            // Get national average for color coding
            $fuelType = $priceRepo->getFuelTypeBySlug($fuelSlug);
            $avgPrice = $fuelType ? $priceRepo->getNationalAverage((int)$fuelType['id'], $priceDate) : 0;

            $features = [];
            foreach ($stations as $s) {
                $price = $s['price'] !== null ? (float)$s['price'] : null;
                $tier  = 'unknown';
                if ($price !== null && $avgPrice > 0) {
                    $diff = $price - $avgPrice;
                    if ($diff <= -0.02)      $tier = 'cheap';
                    elseif ($diff >= 0.02)   $tier = 'expensive';
                    else                     $tier = 'average';
                }

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type'        => 'Point',
                        'coordinates' => [(float)$s['lng'], (float)$s['lat']],
                    ],
                    'properties' => [
                        'id'          => (int)$s['id'],
                        'name'        => $s['name'],
                        'brand'       => $s['brand'],
                        'address'     => $s['address'],
                        'city'        => $s['city'],
                        'price'       => $price,
                        'fuel'        => $fuelSlug,
                        'tier'        => $tier,
                        'is_sponsored'=> (bool)$s['is_sponsored'],
                    ],
                ];
            }

            echo json_encode([
                'type'     => 'FeatureCollection',
                'features' => $features,
                'meta'     => ['avg_price' => round($avgPrice, 3)],
            ]);
            return;
        }

        // List view: paginated
        $filters = array_filter([
            'city'         => $city,
            'municipality' => $muni,
            'brand'        => $brand,
            'fuel'         => $fuel,
            'price_date'   => $priceDate,
        ]);

        $result = $repo->findAll($filters, $page);

        echo json_encode([
            'data' => $result['data'],
            'meta' => [
                'total'    => $result['total'],
                'page'     => $page,
                'per_page' => 20,
            ],
        ]);
    }
}
