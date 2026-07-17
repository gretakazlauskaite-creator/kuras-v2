<?php

namespace App\Controller\Api;

use App\Repository\PriceRepository;

class PricesApiController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        $stationId = (int)($_GET['station_id'] ?? 0);
        $days      = min(90, max(7, (int)($_GET['days'] ?? 30)));

        if (!$stationId) {
            http_response_code(400);
            echo json_encode(['error' => 'station_id required']);
            return;
        }

        $repo    = new PriceRepository();
        $history = $repo->getPriceHistory($stationId, $days);

        // Group by fuel slug for Chart.js datasets
        $datasets = [];
        foreach ($history as $row) {
            $slug = $row['slug'];
            if (!isset($datasets[$slug])) {
                $datasets[$slug] = [
                    'fuel'  => $slug,
                    'label' => $row['fuel_name'],
                    'data'  => [],
                ];
            }
            $datasets[$slug]['data'][] = [
                'date'  => $row['price_date'],
                'price' => (float)$row['price'],
            ];
        }

        echo json_encode([
            'station_id' => $stationId,
            'datasets'   => array_values($datasets),
        ]);
    }
}
