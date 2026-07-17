<?php

namespace App;

/**
 * Two-level cache: APCu (in-process, zero I/O) → file (cross-process).
 *
 * Invalidation strategy: version-based, not file-deletion.
 * Cache::clear() bumps a version timestamp in /tmp/kuras_cache_version.
 * Because CLI (larasail) creates this file, only it can overwrite it — no
 * cross-user permission conflict with web-owned cache files.
 * Cache keys embed the version so old files become unreachable instantly
 * and are cleaned up naturally when their TTL expires.
 */
class Cache
{
    private static ?string $dir = null;
    // NOTE: version is intentionally NOT cached in a static property.
    // PHP-FPM workers are long-lived; static properties persist across requests
    // within the same worker, so a cached version would ignore bumps until restart.

    // ── Public API ────────────────────────────────────────────

    public static function get(string $key, mixed &$hit = false): mixed
    {
        $vkey = self::versioned($key);

        // 1. APCu
        if (function_exists('apcu_fetch')) {
            $value = apcu_fetch($vkey, $ok);
            if ($ok) { $hit = true; return $value; }
        }

        // 2. File
        $file = self::path($vkey);
        if (is_file($file)) {
            $payload = @unserialize(file_get_contents($file));
            if ($payload && $payload['expires'] > time()) {
                $hit = true;
                if (function_exists('apcu_store')) {
                    apcu_store($vkey, $payload['data'], $payload['expires'] - time());
                }
                return $payload['data'];
            }
            @unlink($file); // expired
        }

        $hit = false;
        return null;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $vkey = self::versioned($key);

        if (function_exists('apcu_store')) {
            apcu_store($vkey, $value, $ttl);
        }

        $payload = serialize(['expires' => time() + $ttl, 'data' => $value]);
        $file    = self::path($vkey);
        $written = file_put_contents($file, $payload, LOCK_EX);
        if ($written === false) {
            $msg = date('[Y-m-d H:i:s]') . " Cache write failed for key={$key} file={$file}\n";
            @file_put_contents('/tmp/kuras_error.log', $msg, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Invalidate all cache entries by bumping the shared version file.
     * Old versioned keys become unreachable; their files expire via TTL.
     * Does NOT try to delete files owned by a different OS user.
     */
    public static function clear(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        // Bump the shared version — the single write that invalidates everything.
        $versionFile = sys_get_temp_dir() . '/kuras_cache_version';
        @file_put_contents($versionFile, (string)time(), LOCK_EX);

        // Best-effort cleanup of old files (silently skips permission errors).
        $dir = sys_get_temp_dir() . '/kuras_cache';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.cache') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    // ── Internals ─────────────────────────────────────────────

    private static function versioned(string $key): string
    {
        return $key . ':' . self::version();
    }

    /** Reads the shared version file on every call — no static memoisation. */
    private static function version(): string
    {
        $file = sys_get_temp_dir() . '/kuras_cache_version';
        return is_file($file) ? rtrim((string)@file_get_contents($file)) : '0';
    }

    private static function path(string $key): string
    {
        return self::cacheDir() . '/' . md5($key) . '.cache';
    }

    private static function cacheDir(): string
    {
        if (self::$dir !== null) return self::$dir;
        self::$dir = sys_get_temp_dir() . '/kuras_cache';
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0777, true);
        }
        @chmod(self::$dir, 0777);
        return self::$dir;
    }
}
