<?php

namespace App\Service;

class GeocodingService
{
    // Photon (Komoot) — OSM-based, free, no API key, very permissive rate limits
    private const PHOTON_URL   = 'https://photon.komoot.io/api/';
    // Nominatim — fallback if Photon fails
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT   = 'kuras.pricer.lt/1.0 (contact: admin@kuras.pricer.lt)';

    public function geocode(string $query): ?array
    {
        $coords = $this->photon($query);
        if ($coords) return $coords;

        sleep(1);
        return $this->nominatim($query);
    }

    // ── Photon ────────────────────────────────────────────────
    private function photon(string $query): ?array
    {
        // Build URL manually — bbox commas must NOT be percent-encoded
        $url = self::PHOTON_URL . '?q=' . urlencode($query) . '&limit=1&bbox=20.6,53.8,26.9,56.5';

        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        $feat = $data['features'][0] ?? null;
        if (!$feat) return null;

        [$lng, $lat] = $feat['geometry']['coordinates'];
        // Verify result is within Lithuania
        if ($lat < 53.8 || $lat > 56.5 || $lng < 20.6 || $lng > 26.9) return null;

        return ['lat' => (float)$lat, 'lng' => (float)$lng];
    }

    // ── Nominatim fallback ─────────────────────────────────────
    private function nominatim(string $query): ?array
    {
        $url = self::NOMINATIM_URL . '?' . http_build_query([
            'q'            => $query,
            'format'       => 'json',
            'limit'        => 1,
            'countrycodes' => 'lt',
        ]);

        $response = $this->httpGet($url);
        if (!$response) return null;

        // Handle 429 — return null gracefully (caller can retry later)
        if (str_contains($response, '429') || str_starts_with(trim($response), '<')) return null;

        $data = json_decode($response, true);
        if (empty($data[0])) return null;

        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }

    // ── HTTP helper ────────────────────────────────────────────
    private function httpGet(string $url): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            return $resp;
        }

        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'User-Agent: ' . self::USER_AGENT,
            'timeout' => 10,
        ]]);
        return @file_get_contents($url, false, $ctx);
    }
}
