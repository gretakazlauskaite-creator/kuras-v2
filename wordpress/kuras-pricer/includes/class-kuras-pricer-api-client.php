<?php

declare(strict_types=1);

final class Kuras_Pricer_Api_Client
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $configured = $baseUrl ?? (string) get_option('kuras_pricer_api_base_url', 'https://kuras.pricer.lt/api/v1');
        $this->baseUrl = untrailingslashit(esc_url_raw($configured));
    }

    /** @param array<string,string|int|float> $query @return array<string,mixed>|WP_Error */
    public function get(string $path, array $query = [], int $ttl = 60): array|WP_Error
    {
        if (!$this->isAllowedPath($path)) {
            return new WP_Error('kuras_invalid_path', 'Neleistinas Kuras API kelias.');
        }

        ksort($query);
        $url = add_query_arg($query, $this->baseUrl . '/' . ltrim($path, '/'));
        $hash = hash('sha256', $url);
        $freshKey = 'kuras_f_' . $hash;
        $staleKey = 'kuras_s_' . $hash;
        $fresh = get_transient($freshKey);
        if (is_array($fresh)) {
            return $this->markCache($fresh, false, true);
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 8,
            'redirection' => 2,
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'Kuras-Pricer-WordPress/' . KURAS_PRICER_VERSION,
        ]);

        $error = null;
        if (is_wp_error($response)) {
            $error = $response;
        } else {
            $status = wp_remote_retrieve_response_code($response);
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if ($status >= 200 && $status < 300 && is_array($decoded) && array_key_exists('data', $decoded)) {
                $decoded['_kuras_fetched_at'] = gmdate('c');
                set_transient($freshKey, $decoded, max(15, $ttl));
                set_transient($staleKey, $decoded, 7 * DAY_IN_SECONDS);
                return $this->markCache($decoded, false, false);
            }
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            $error = new WP_Error('kuras_api_error', $message ?: 'Kuro kainų paslauga laikinai nepasiekiama.', ['status' => $status]);
        }

        $stale = get_transient($staleKey);
        if (is_array($stale)) {
            return $this->markCache($stale, true, true);
        }

        return $error ?? new WP_Error('kuras_api_error', 'Kuro kainų paslauga laikinai nepasiekiama.');
    }

    private function isAllowedPath(string $path): bool
    {
        return preg_match('#^(meta|filters|stations|rankings|statistics|map/stations|nearby|stations/(?:[0-9]+|st_[a-f0-9]{20})(?:/history)?)$#', $path) === 1;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function markCache(array $body, bool $stale, bool $cacheHit): array
    {
        $fetchedAt = (string) ($body['_kuras_fetched_at'] ?? '');
        unset($body['_kuras_fetched_at']);
        $body['meta'] = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $body['meta']['wordpress_cache'] = [
            'stale' => $stale,
            'cache_hit' => $cacheHit,
            'fetched_at' => $fetchedAt,
            'served_at' => gmdate('c'),
        ];
        return $body;
    }
}
