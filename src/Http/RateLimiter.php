<?php

declare(strict_types=1);

namespace App\Http;

final class RateLimiter
{
    public function enforce(string $bucket, int $limit = 120, int $windowSeconds = 60): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $window = intdiv(time(), $windowSeconds);
        $key = hash('sha256', $bucket . '|' . $ip . '|' . $window);
        $directory = sys_get_temp_dir() . '/kuras_rate_limits';
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $path = $directory . '/' . $key;
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $current = (int) stream_get_contents($handle);
            ++$current;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $current);
            fflush($handle);
            flock($handle, LOCK_UN);

            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: ' . max(0, $limit - $current));
            if ($current > $limit) {
                header('Retry-After: ' . $windowSeconds);
                throw new ApiException('rate_limit_exceeded', 'Per daug užklausų. Bandykite dar kartą netrukus.', 429);
            }
        } finally {
            fclose($handle);
        }
    }
}
