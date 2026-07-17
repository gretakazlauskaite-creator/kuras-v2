<?php

namespace App\Controller;

use App\Repository\AlertRepository;
use App\Service\AlertService;

class AlertController
{
    public function unsubscribe(): void
    {
        $token = $_GET['token'] ?? '';
        $repo  = new AlertRepository();

        if (!$token || !$repo->findByToken($token)) {
            http_response_code(400);
            $error = 'Neteisingas arba pasibaigęs atsisakymo nuoroda.';
            require dirname(__DIR__, 2) . '/templates/unsubscribe.php';
            return;
        }

        $repo->deactivate($token);
        $success = true;
        require dirname(__DIR__, 2) . '/templates/unsubscribe.php';
    }
}
