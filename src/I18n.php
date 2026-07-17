<?php

namespace App;

class I18n
{
    private static string $lang = 'lt';
    private static array  $strings = [];
    private static array  $supported = ['lt', 'en', 'ru'];

    public static function init(): void
    {
        // 1. Determine language: ?lang= param → cookie → Accept-Language → default lt
        $lang = null;

        if (!empty($_GET['lang']) && in_array($_GET['lang'], self::$supported, true)) {
            $lang = $_GET['lang'];
            setcookie('lang', $lang, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'samesite' => 'Lax']);
        } elseif (!empty($_COOKIE['lang']) && in_array($_COOKIE['lang'], self::$supported, true)) {
            $lang = $_COOKIE['lang'];
        } else {
            // Parse Accept-Language header
            $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
            foreach (self::$supported as $candidate) {
                if (stripos($accept, $candidate) !== false) {
                    $lang = $candidate;
                    break;
                }
            }
        }

        self::$lang = $lang ?? 'lt';

        // 2. Load translation file
        $file = dirname(__DIR__) . '/lang/' . self::$lang . '.php';
        if (file_exists($file)) {
            self::$strings = require $file;
        }

        // 3. Fallback: load Lithuanian as base if not lt
        if (self::$lang !== 'lt') {
            $ltFile = dirname(__DIR__) . '/lang/lt.php';
            if (file_exists($ltFile)) {
                self::$strings = array_merge(require $ltFile, self::$strings);
            }
        }
    }

    /** Translate a key. Supports :placeholder substitution. */
    public static function t(string $key, array $params = []): string
    {
        $str = self::$strings[$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace(':' . $k, (string)$v, $str);
        }
        return $str;
    }

    public static function getLang(): string   { return self::$lang; }
    public static function getSupported(): array { return self::$supported; }

    public static function langUrl(string $lang, string $path = ''): string
    {
        $uri = $path ?: ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = strtok($uri, '?');
        $query = $_GET;
        $query['lang'] = $lang;
        return $uri . '?' . http_build_query($query);
    }
}
