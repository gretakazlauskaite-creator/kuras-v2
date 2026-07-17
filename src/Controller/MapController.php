<?php

namespace App\Controller;

class MapController
{
    public function index(): void
    {
        $pageTitle = 'Degalinių žemėlapis Lietuvoje — Kuras Pricer';
        $pageDesc  = 'Interaktyvus degalinių žemėlapis su kainomis visoje Lietuvoje.';
        require dirname(__DIR__, 2) . '/templates/map.php';
    }
}
