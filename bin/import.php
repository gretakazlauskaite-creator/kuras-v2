#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';

use App\Service\ImportService;
use App\Service\AlertService;
use App\Service\BestPriceService;

// ── Parse CLI arguments ──────────────────────────────────────
$opts       = getopt('', ['date:', 'dry-run', 'geocode-new', 'skip-alerts', 'help']);
$dryRun     = isset($opts['dry-run']);
$date       = $opts['date'] ?? date('Y-m-d');
$geocodeNew = isset($opts['geocode-new']); // opt-in: geocode only if new stations appeared
$skipAlerts = isset($opts['skip-alerts']);

if (isset($opts['help'])) {
    echo <<<HELP
Usage: php bin/import.php [options]

Options:
  --date=YYYY-MM-DD    Import prices for specific date (default: today)
  --dry-run            Parse file but do not write to DB
  --geocode-new        Geocode any newly discovered stations after import
  --skip-alerts        Skip price alert checks
  --help               Show this help

Note: for bulk geocoding of all stations run  php bin/geocode.php

HELP;
    exit(0);
}

// ── LEA Excel URL ────────────────────────────────────────────
// The file is updated daily; we download it fresh each run.
$leaUrl = 'https://www.ena.lt/degalu-kainos-degalinese/';

log_msg("Starting import for date: $date" . ($dryRun ? ' [DRY RUN]' : ''));

// ── 1. Download Excel ────────────────────────────────────────
$tmpFile = sys_get_temp_dir() . '/lea_prices_' . $date . '.xlsx';

if (!file_exists($tmpFile)) {
    log_msg("Fetching Excel from LEA ...");
    $xlsxData = fetch_lea_excel($leaUrl);

    if ($xlsxData === null) {
        log_error("Failed to download Excel file. Aborting.");
        exit(1);
    }

    if (!is_valid_xlsx($xlsxData)) {
        log_error("Downloaded content is not a valid XLSX file (got HTML or unexpected data). Aborting.");
        log_error("First 200 bytes: " . substr(bin2hex($xlsxData), 0, 400));
        exit(1);
    }

    file_put_contents($tmpFile, $xlsxData);
    log_msg("Saved to $tmpFile (" . round(strlen($xlsxData) / 1024) . " KB)");
} else {
    log_msg("Using cached file: $tmpFile");
    // Validate cached file too — it may have been a bad download from a previous run
    $cached = file_get_contents($tmpFile);
    if (!is_valid_xlsx($cached)) {
        log_msg("Cached file is not valid XLSX — deleting and re-downloading ...");
        unlink($tmpFile);
        $xlsxData = fetch_lea_excel($leaUrl);
        if ($xlsxData === null || !is_valid_xlsx($xlsxData)) {
            log_error("Re-download also failed or returned invalid content. Aborting.");
            exit(1);
        }
        file_put_contents($tmpFile, $xlsxData);
        log_msg("Saved fresh file to $tmpFile (" . round(strlen($xlsxData) / 1024) . " KB)");
    }
}

// ── 2. Import ────────────────────────────────────────────────
$importer = new ImportService();

try {
    $importer->importFromFile($tmpFile, $date, $dryRun);
} catch (\Throwable $e) {
    log_error("Import failed: " . $e->getMessage());
    notify_admin_error($e->getMessage());
    exit(1);
}

log_msg("Imported {$importer->importedPrices} prices, {$importer->newStations} new stations.");

// ── 3. Geocode newly added stations (opt-in) ─────────────────
if (!$dryRun && $geocodeNew && $importer->newStations > 0) {
    log_msg("Geocoding {$importer->newStations} new station(s) ...");
    $geocoded = $importer->geocodeNewStations();
    log_msg("Geocoded $geocoded station(s).");
} elseif (!$dryRun && $importer->newStations > 0) {
    log_msg("Note: {$importer->newStations} new station(s) without coordinates — run php bin/geocode.php to geocode.");
}

// ── 4. Invalidate cache ───────────────────────────────────────
if (!$dryRun) {
    (new BestPriceService())->invalidateCache();
    log_msg("APCu cache invalidated.");
}

// ── 5. Check price alerts ────────────────────────────────────
if (!$dryRun && !$skipAlerts) {
    log_msg("Checking price alerts ...");
    $alertService = new AlertService();
    $sent = $alertService->checkAndSend();
    log_msg("Sent $sent alert emails.");
}

log_msg("Done.");
exit(0);

// ── Helpers ──────────────────────────────────────────────────

function fetch_lea_excel(string $pageUrl): ?string
{
    $ua = 'Mozilla/5.0 (compatible; kuras.pricer.lt/1.0; contact: admin@kuras.pricer.lt)';

    // ── Step 1: Scrape the LEA page to find today's SharePoint link ──
    $html    = curl_get($pageUrl, $ua);
    $xlsxUrl = null;

    if ($html) {
        // The page embeds SharePoint sharing links — grab the last (most recent) one
        preg_match_all(
            '/href=["\'](' . preg_quote('https://ltenergagen.sharepoint.com', '/') . '[^"\']+)["\']/',
            $html,
            $matches
        );
        if (!empty($matches[1])) {
            // Last occurrence = most recent day
            $sharingUrl = end($matches[1]);
            // Append &download=1 to trigger direct file redirect
            $xlsxUrl = $sharingUrl . (str_contains($sharingUrl, '?') ? '&' : '?') . 'download=1';
            log_msg("Found SharePoint link: $xlsxUrl");
        }
    }

    // ── Fallback: hardcoded current link ─────────────────────────────
    if (!$xlsxUrl) {
        log_msg("Could not scrape page, using fallback SharePoint URL.");
        $xlsxUrl = 'https://ltenergagen.sharepoint.com/:x:/s/intra/doc/IQDRHKinqYi1S4QH6WeTSxLUATgT_VjMdvpnTH3cNxePBFA?e=mZhpxb&download=1';
    }

    // ── Step 2: Download with cURL (follows redirects + cookie jar) ──
    log_msg("Downloading from: $xlsxUrl");
    return curl_get($xlsxUrl, $ua, true);
}

/**
 * HTTP GET via cURL.
 * @param bool $binary  If true, returns raw bytes (for xlsx); otherwise string.
 */
function curl_get(string $url, string $ua, bool $binary = false): ?string
{
    if (!function_exists('curl_init')) {
        // Fallback: shell curl
        $cookieFile = sys_get_temp_dir() . '/lea_curl_cookies.txt';
        $tmpOut     = tempnam(sys_get_temp_dir(), 'lea_');
        $cmd = sprintf(
            'curl -sL -o %s -c %s -b %s -A %s %s 2>&1',
            escapeshellarg($tmpOut),
            escapeshellarg($cookieFile),
            escapeshellarg($cookieFile),
            escapeshellarg($ua),
            escapeshellarg($url)
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($tmpOut)) return null;
        $data = file_get_contents($tmpOut);
        unlink($tmpOut);
        return $data ?: null;
    }

    $cookieFile = sys_get_temp_dir() . '/lea_curl_cookies.txt';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_ENCODING       => '',  // accept gzip/deflate
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,*/*',
        ],
    ]);

    $data = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        log_error("cURL error: $err");
        return null;
    }
    if ($code >= 400) {
        log_error("HTTP $code downloading $url");
        return null;
    }

    return $data ?: null;
}

function log_msg(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

function log_error(string $msg): void
{
    fwrite(STDERR, '[ERROR] ' . $msg . "\n");
    error_log('[import.php] ' . $msg);
}

/** XLSX files are ZIP archives — they start with the PK magic bytes. */
function is_valid_xlsx(string $data): bool
{
    return strlen($data) > 4 && substr($data, 0, 2) === 'PK';
}

function notify_admin_error(string $msg): void
{
    $adminEmail = $_ENV['ADMIN_EMAIL'] ?? null;
    if (!$adminEmail) return;
    @mail($adminEmail, '[kuras.pricer.lt] Import ERROR', $msg);
}
