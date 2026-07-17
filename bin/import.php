#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';

use App\Service\AlertService;
use App\Service\BestPriceService;
use App\Service\Import\LeaSource;
use App\Service\Import\LeaSourceLocator;
use App\Service\Import\XlsxFileValidator;
use App\Service\ImportService;

$options = getopt('', [
    'file:',
    'source-date:',
    'dry-run',
    'allow-backfill',
    'geocode-new',
    'skip-alerts',
    'help',
]);

if (isset($options['help'])) {
    echo <<<'HELP'
Usage: php bin/import.php [options]

Without --file, the command discovers the latest workbook and its source date
from the official LEA page.

Options:
  --file=/path/file.xlsx       Import a local LEA workbook
  --source-date=YYYY-MM-DD     Required with --file; never inferred from today
  --dry-run                    Parse and validate without writing to the database
  --allow-backfill             Allow an older or archived source date
  --geocode-new                Geocode newly discovered stations after publish
  --skip-alerts                Skip price alert checks
  --help                       Show this help

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$allowBackfill = isset($options['allow-backfill']);
$geocodeNew = isset($options['geocode-new']);
$skipAlerts = isset($options['skip-alerts']);
$manualFile = isset($options['file']) ? (string) $options['file'] : null;
$manualDate = isset($options['source-date']) ? (string) $options['source-date'] : null;

try {
    $importLock = acquire_import_lock();
    if ($manualFile !== null) {
        if ($manualDate === null || !is_valid_date($manualDate)) {
            throw new RuntimeException('Naudojant --file būtina teisinga --source-date=YYYY-MM-DD reikšmė.');
        }
        if (!is_file($manualFile) || !is_readable($manualFile)) {
            throw new RuntimeException("Vietinis failas nerastas arba neperskaitomas: {$manualFile}");
        }

        $source = new LeaSource(
            pageUrl: 'manual-import',
            downloadUrl: 'manual-file://' . basename($manualFile),
            sourceDate: $manualDate,
        );
        $downloadedFile = $manualFile;
    } else {
        log_message('Tikrinamas oficialus LEA puslapis...');
        $pageHtml = http_get(LeaSourceLocator::PAGE_URL, false);
        $source = (new LeaSourceLocator())->locate($pageHtml, LeaSourceLocator::PAGE_URL);
        log_message("Rastas {$source->sourceDate} šaltinis. Atsisiunčiamas Excel failas...");
        $downloadedFile = download_to_temporary_file($source->downloadUrl);
    }

    (new XlsxFileValidator())->assertValid($downloadedFile);
    $checksum = hash_file('sha256', $downloadedFile);
    if ($checksum === false) {
        throw new RuntimeException('Nepavyko apskaičiuoti šaltinio failo kontrolinės sumos.');
    }

    $storedFile = store_immutable_source($downloadedFile, $source->sourceDate, $checksum);
    log_message("Šaltinis išsaugotas: {$storedFile}");
    log_message('SHA-256: ' . $checksum);

    $importer = new ImportService();
    $result = $importer->importFromFile(
        filePath: $storedFile,
        sourceDate: $source->sourceDate,
        sourcePageUrl: $source->pageUrl,
        sourceUrl: $source->downloadUrl,
        checksum: $checksum,
        dryRun: $dryRun,
        allowBackfill: $allowBackfill,
    );

    foreach ($result->validation->warnings as $warning) {
        log_message('ĮSPĖJIMAS: ' . $warning);
    }
    foreach ($result->validation->errors as $error) {
        log_error($error);
    }

    if ($result->status === 'rejected') {
        $message = 'LEA importas atmestas. Vieši duomenys nepakeisti.';
        log_error($message);
        notify_admin_error($message . "\n" . implode("\n", $result->validation->errors));
        exit(2);
    }

    if ($result->status === 'duplicate') {
        log_message('Tas pats šaltinio failas jau publikuotas. Pakeitimų nėra.');
        exit(0);
    }

    if ($dryRun) {
        log_message(
            "Patikra sėkminga: {$result->stationCount} degalinių, {$result->priceCount} kainų. Duomenų bazė nepakeista.",
        );
        exit(0);
    }

    log_message(
        "Atominis publikavimas baigtas: {$result->priceCount} kainų, {$result->newStationCount} naujų degalinių.",
    );

    if ($geocodeNew && $result->newStationCount > 0) {
        log_message("Geokoduojamos {$result->newStationCount} naujos degalinės...");
        log_message('Geokoduota: ' . $importer->geocodeNewStations());
    }

    (new BestPriceService())->invalidateCache();
    if (!$skipAlerts) {
        $sent = (new AlertService())->checkAndSend();
        log_message("Išsiųsta kainų pranešimų: {$sent}");
    }

    log_message('Importas baigtas sėkmingai.');
    exit(0);
} catch (Throwable $exception) {
    log_error($exception->getMessage());
    notify_admin_error($exception->getMessage());
    exit(1);
}

function download_to_temporary_file(string $url): string
{
    $data = http_get($url, true);
    $temporaryFile = tempnam(sys_get_temp_dir(), 'lea_');
    if ($temporaryFile === false || file_put_contents($temporaryFile, $data, LOCK_EX) === false) {
        throw new RuntimeException('Nepavyko saugiai išsaugoti atsisiųsto LEA failo.');
    }

    return $temporaryFile;
}

function http_get(string $url, bool $binary): string
{
    $userAgent = 'kuras.pricer.lt/2.0 (+https://kuras.pricer.lt/)';

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [$binary
                ? 'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream;q=0.9,*/*;q=0.1'
                : 'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'],
        ]);
        $data = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($data === false || $error !== '') {
            throw new RuntimeException('HTTP užklausa nepavyko: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("HTTP užklausa grąžino {$status} būseną.");
        }

        return (string) $data;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$userAgent}\r\n",
            'timeout' => 90,
            'follow_location' => 1,
            'max_redirects' => 8,
            'ignore_errors' => false,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        throw new RuntimeException('HTTP užklausa nepavyko, o PHP cURL plėtinys neįdiegtas.');
    }

    return $data;
}

function store_immutable_source(string $inputFile, string $sourceDate, string $checksum): string
{
    $basePath = (string) ($_ENV['IMPORT_STORAGE_PATH'] ?? dirname(__DIR__) . '/var/imports');
    $directory = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sourceDate;
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException("Nepavyko sukurti šaltinių saugyklos: {$directory}");
    }

    $target = $directory . DIRECTORY_SEPARATOR . $checksum . '.xlsx';
    if (realpath($inputFile) === realpath($target)) {
        return $target;
    }
    if (is_file($target)) {
        $existingChecksum = hash_file('sha256', $target);
        if (!hash_equals($checksum, (string) $existingChecksum)) {
            throw new RuntimeException('Šaltinių saugykloje aptiktas failo kontrolinės sumos neatitikimas.');
        }
        return $target;
    }

    if (!copy($inputFile, $target)) {
        throw new RuntimeException('Nepavyko nukopijuoti LEA failo į nekintamą saugyklą.');
    }
    chmod($target, 0440);
    return $target;
}

/** @return resource */
function acquire_import_lock()
{
    $basePath = (string) ($_ENV['IMPORT_STORAGE_PATH'] ?? dirname(__DIR__) . '/var/imports');
    if (!is_dir($basePath) && !mkdir($basePath, 0770, true) && !is_dir($basePath)) {
        throw new RuntimeException("Nepavyko sukurti importo saugyklos: {$basePath}");
    }

    $handle = fopen(rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.import.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Kitas LEA importas jau vyksta. Šis paleidimas sustabdytas.');
    }

    return $handle;
}

function is_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function log_message(string $message): void
{
    echo '[' . date('H:i:s') . '] ' . $message . "\n";
}

function log_error(string $message): void
{
    fwrite(STDERR, '[KLAIDA] ' . $message . "\n");
    error_log('[import.php] ' . $message);
}

function notify_admin_error(string $message): void
{
    $adminEmail = $_ENV['ADMIN_EMAIL'] ?? null;
    if (!is_string($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    @mail($adminEmail, '[kuras.pricer.lt] LEA importo klaida', $message);
}
