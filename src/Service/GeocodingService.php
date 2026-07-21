<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Replaceable geocoding adapter. Recurring bulk jobs require a contracted or
 * self-hosted Photon-compatible endpoint configured through the environment.
 */
final class GeocodingService
{
    private const USER_AGENT = 'kuras.pricer.lt/2.0 (+https://kuras.pricer.lt/)';

    public function __construct(
        private readonly ?string $endpoint = null,
        private readonly ?string $provider = null,
    ) {
    }

    /** @return array{lat:float,lng:float,provider:string,confidence:?float}|null */
    public function geocode(string $query): ?array
    {
        $endpoint = $this->endpoint ?? $this->environment('GEOCODING_ENDPOINT');
        $provider = $this->provider ?? $this->environment('GEOCODING_PROVIDER') ?? 'photon';
        if ($endpoint === null || trim($endpoint) === '') {
            return null;
        }
        if ($provider !== 'photon') {
            throw new \RuntimeException("Nepalaikomas geokodavimo adapteris: {$provider}");
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $url = rtrim($endpoint) . $separator . http_build_query([
            'q' => $query,
            'limit' => 1,
            'bbox' => '20.6,53.8,26.9,56.5',
        ]);
        $response = $this->httpGet($url);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        $feature = $data['features'][0] ?? null;
        if (!is_array($feature) || !isset($feature['geometry']['coordinates'][0], $feature['geometry']['coordinates'][1])) {
            return null;
        }
        $lng = (float) $feature['geometry']['coordinates'][0];
        $lat = (float) $feature['geometry']['coordinates'][1];
        if ($lat < 53.8 || $lat > 56.5 || $lng < 20.6 || $lng > 26.9) {
            return null;
        }

        $confidence = isset($feature['properties']['confidence']) && is_numeric($feature['properties']['confidence'])
            ? min(1.0, max(0.0, (float) $feature['properties']['confidence']))
            : null;

        return ['lat' => $lat, 'lng' => $lng, 'provider' => $provider, 'confidence' => $confidence];
    }

    private function environment(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function httpGet(string $url): string|false
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            return $response !== false && $status >= 200 && $status < 300 ? (string) $response : false;
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . self::USER_AGENT,
            'timeout' => 15,
        ]]);
        return @file_get_contents($url, false, $context);
    }
}
