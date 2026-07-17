<?php

declare(strict_types=1);

namespace App\Controller;

final class PreviewController
{
    public function index(): void
    {
        $tileUrl = (string) ($_ENV['MAP_TILE_URL'] ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
        $tileAttribution = (string) ($_ENV['MAP_TILE_ATTRIBUTION'] ?? '© OpenStreetMap contributors');
        require dirname(__DIR__, 2) . '/templates/preview.php';
    }
}
