<?php

namespace App\Controller\Api;

use App\Repository\PriceRepository;
use App\Service\AlertService;

class AlertsApiController
{
    public function create(): void
    {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $email       = trim($input['email']        ?? '');
        $fuelSlug    = trim($input['fuel']          ?? '');
        $city        = trim($input['city']          ?? '') ?: null;
        $targetPrice = (float)($input['target_price'] ?? 0);

        // Validate
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Neteisingas el. pašto adresas.';
        }
        if ($targetPrice <= 0 || $targetPrice > 5) {
            $errors[] = 'Tikslinė kaina turi būti tarp 0.01 ir 5.00 €/L.';
        }

        $priceRepo = new PriceRepository();
        $fuelType  = $priceRepo->getFuelTypeBySlug($fuelSlug);
        if (!$fuelType) {
            $errors[] = 'Nežinomas kuro tipas.';
        }

        if ($errors) {
            http_response_code(422);
            echo json_encode(['errors' => $errors]);
            return;
        }

        $alertService = new AlertService();
        $token = $alertService->createAlert($email, (int)$fuelType['id'], $city, $targetPrice);

        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Signalas sukurtas. Patikrinkite el. paštą.']);
    }
}
