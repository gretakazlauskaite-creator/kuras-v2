<?php
// Load .env file if present
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Initialise i18n (only for web requests, not CLI)
if (PHP_SAPI !== 'cli') {
    \App\I18n::init();
}

/**
 * Translate a key. Shorthand for \App\I18n::t().
 */
function __(string $key, array $params = []): string
{
    return \App\I18n::t($key, $params);
}
