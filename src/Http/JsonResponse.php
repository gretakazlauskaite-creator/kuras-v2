<?php

declare(strict_types=1);

namespace App\Http;

final class JsonResponse
{
    /** @param array<string,mixed> $body */
    public static function send(array $body, int $status = 200, int $maxAge = 0): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Vary: Accept-Encoding');
        header($maxAge > 0 ? "Cache-Control: public, max-age={$maxAge}, stale-while-revalidate=60" : 'Cache-Control: no-store');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $details */
    public static function error(string $code, string $message, int $status, array $details = []): void
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::send(['error' => $error], $status);
    }
}
