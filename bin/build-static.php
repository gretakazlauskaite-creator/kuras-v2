#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Import\ImportValidator;
use App\Service\Import\LeaLiveApiParser;
use App\Service\Import\LeaSource;
use App\Service\Import\LeaPortalConfigLocator;
use App\Service\Import\LeaWorkbookParser;
use App\Service\Import\XlsxFileValidator;
use App\Service\StaticDataExporter;
use App\Service\StaticHistoryBuilder;

$options = getopt('', ['file:', 'source-date:', 'output:', 'previous-data:', 'previous-history:', 'archive-output:', 'coordinates:', 'fixture', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/build-static.php [--file=prices.xlsx --source-date=YYYY-MM-DD --fixture] [--output=dist] [--previous-data=file.json] [--previous-history=history.json] [--archive-output=path] [--coordinates=file.json]\n";
    exit(0);
}

$root = dirname(__DIR__);
$output = isset($options['output']) ? (string) $options['output'] : $root . '/dist';
$previousDataPath = isset($options['previous-data'])
    ? (string) $options['previous-data']
    : $output . '/data/current.json';
$previousHistoryPath = isset($options['previous-history'])
    ? (string) $options['previous-history']
    : dirname($previousDataPath) . '/history.json';
$archiveOutput = isset($options['archive-output']) ? (string) $options['archive-output'] : null;
$coordinatesPath = isset($options['coordinates'])
    ? (string) $options['coordinates']
    : $root . '/resources/station-coordinates.json';
$fixtureMode = isset($options['fixture']);
$sourceUpdatedAt = null;
$parserVersion = LeaWorkbookParser::VERSION;

if (isset($options['file'])) {
        $file = (string) $options['file'];
        $sourceDate = (string) ($options['source-date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceDate)) {
            throw new RuntimeException('Naudojant --file būtina nurodyti --source-date=YYYY-MM-DD.');
        }
        $source = new LeaSource('fixture', 'fixture://' . basename($file), $sourceDate);
        if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
            $sourceBody = file_get_contents($file);
            if (!is_string($sourceBody)) {
                throw new RuntimeException('Nepavyko perskaityti LEA JSON failo.');
            }
            $snapshot = (new LeaLiveApiParser())->parse($sourceBody);
            if ($snapshot->sourceDate !== $sourceDate) {
                throw new RuntimeException(
                    "LEA API data {$snapshot->sourceDate} nesutampa su nurodyta šaltinio data {$sourceDate}.",
                );
            }
            $parsed = $snapshot->parsed;
            $sourceUpdatedAt = $snapshot->lastUpdated;
            $parserVersion = LeaLiveApiParser::VERSION;
            $sourceExtension = 'json';
        } else {
            (new XlsxFileValidator())->assertValid($file);
            $parsed = (new LeaWorkbookParser())->parse($file);
            $sourceBody = file_get_contents($file);
            if (!is_string($sourceBody)) {
                throw new RuntimeException('Nepavyko perskaityti LEA Excel failo.');
            }
            $sourceExtension = 'xlsx';
        }
} else {
        $portalHtml = http_get(LeaPortalConfigLocator::PORTAL_URL);
        $config = (new LeaPortalConfigLocator())->locate(
            $portalHtml,
            static fn (string $url, ?string $referer): string => http_get($url, $referer),
        );
        $apiUrl = rtrim($config->apiBase, '/') . '/read/prices/latest';
        $sourceBody = http_get_lea_api($apiUrl, $config->token);
        $snapshot = (new LeaLiveApiParser())->parse($sourceBody);
        $source = new LeaSource(
            pageUrl: LeaPortalConfigLocator::PORTAL_URL,
            downloadUrl: $apiUrl,
            sourceDate: $snapshot->sourceDate,
        );
        $parsed = $snapshot->parsed;
        $sourceUpdatedAt = $snapshot->lastUpdated;
        $parserVersion = LeaLiveApiParser::VERSION;
        $sourceExtension = 'json';
}

$checksum = hash('sha256', $sourceBody);

    if ($archiveOutput !== null) {
        recreate_directory($archiveOutput);
        $archiveSource = $archiveOutput . '/source-' . $source->sourceDate . '-' . $checksum . '.' . $sourceExtension;
        if (file_put_contents($archiveSource, $sourceBody) === false) {
            throw new RuntimeException('Nepavyko išsaugoti tikrinimo šaltinio kopijos.');
        }
    }

    $previous = read_previous_data($previousDataPath);
    $validator = $fixtureMode
        ? new ImportValidator(minimumStations: 1, minimumPrices: 1, maximumSourceAgeDays: 3650)
        : new ImportValidator();
    $validation = $validator->validate(
        parsed: $parsed,
        sourceDate: $source->sourceDate,
        latestPublishedDate: $fixtureMode ? null : ($previous['source']['source_date'] ?? null),
        previousPriceCount: $fixtureMode ? null : ($previous['summary']['price_count'] ?? null),
        previousStationCount: $fixtureMode ? null : ($previous['summary']['station_count'] ?? null),
        allowBackfill: $fixtureMode,
    );

    foreach ($validation->warnings as $warning) {
        fwrite(STDERR, "ĮSPĖJIMAS: {$warning}\n");
    }
    if (!$validation->isValid()) {
        if ($archiveOutput !== null) {
            write_json($archiveOutput . '/import-report.json', [
                'status' => 'rejected',
                'source_date' => $source->sourceDate,
                'checked_at' => gmdate('c'),
                'checksum_sha256' => $checksum,
                'validation' => $validation->metrics,
                'warnings' => $validation->warnings,
                'errors' => $validation->errors,
            ]);
        }
        throw new RuntimeException("LEA duomenų patikra nepavyko:\n- " . implode("\n- ", $validation->errors));
    }

    recreate_directory($output);
    copy_tree($root . '/static', $output);
    $generatedAt = ($previous['source']['checksum_sha256'] ?? null) === $checksum
        ? (string) ($previous['source']['generated_at'] ?? gmdate('c'))
        : gmdate('c');
    $coordinates = read_coordinate_data($coordinatesPath);
    $payload = (new StaticDataExporter($coordinates))->export(
        parsed: $parsed,
        sourceDate: $source->sourceDate,
        generatedAt: $generatedAt,
        sourcePageUrl: $source->pageUrl,
        checksum: $checksum,
        parserVersion: $parserVersion,
        sourceUpdatedAt: $sourceUpdatedAt,
    );
    write_json($output . '/data/current.json', $payload);
    write_javascript_data($output . '/data/current.js', $payload);
    $history = (new StaticHistoryBuilder())->update(read_previous_data($previousHistoryPath), $payload);
    write_json($output . '/data/history.json', $history);
    write_json($output . '/data/import-report.json', [
        'status' => 'published',
        'source_date' => $source->sourceDate,
        'source_updated_at' => $sourceUpdatedAt,
        'generated_at' => $payload['source']['generated_at'],
        'checksum_sha256' => $checksum,
        'validation' => $validation->metrics,
        'warnings' => $validation->warnings,
    ]);

    if ($archiveOutput !== null) {
        if (!copy($output . '/data/import-report.json', $archiveOutput . '/import-report.json')) {
            throw new RuntimeException('Nepavyko išsaugoti importo ataskaitos kopijos.');
        }
    }

echo sprintf(
    "Statinė svetainė paruošta: %d degalinių, %d kainų, data %s.\n",
    count($parsed->stations),
    $parsed->priceCount(),
    $source->sourceDate,
);

function http_get(string $url, ?string $referer = null): string
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('LEA atsisiuntimui būtinas PHP cURL plėtinys.');
    }

    $parts = parse_url($url);
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        throw new RuntimeException('LEA atsisiuntimui leidžiami tik HTTPS adresai.');
    }

    $lastError = 'nežinoma tinklo klaida';
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Nepavyko inicijuoti cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; KurasPricerBot/1.0; +https://kuras.pricer.lt)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,text/html;q=0.9,*/*;q=0.8',
                'Accept-Language: lt-LT,lt;q=0.9,en;q=0.7',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEFILE => '',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($referer !== null) {
            curl_setopt($handle, CURLOPT_REFERER, $referer);
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
            return $body;
        }

        $lastError = $curlError !== '' ? $curlError : "HTTP {$status}";
        if ($attempt < 3 && ($status === 0 || $status === 408 || $status === 429 || $status >= 500)) {
            usleep(500000 * $attempt);
            continue;
        }
        break;
    }

    throw new RuntimeException("Nepavyko atsisiųsti {$url} ({$lastError}).");
}

function http_get_lea_api(string $url, string $token): string
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('LEA API atsisiuntimui būtinas PHP cURL plėtinys.');
    }

    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Nepavyko inicijuoti LEA API užklausos.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; KurasPricerBot/1.0; +https://kuras.pricer.lt)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Origin: ' . rtrim(LeaPortalConfigLocator::PORTAL_URL, '/'),
            'Referer: ' . LeaPortalConfigLocator::PORTAL_URL,
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
        $error = $curlError !== '' ? $curlError : "HTTP {$status}";
        throw new RuntimeException("Nepavyko atsisiųsti LEA API duomenų ({$error}).");
    }

    return $body;
}

/** @return array<string,mixed> */
function read_previous_data(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) && !($data['demo'] ?? false) ? $data : [];
}

/** @return array<string,array<string,mixed>> */
function read_coordinate_data(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    return is_array($data['stations'] ?? null) ? $data['stations'] : [];
}

function recreate_directory(string $path): void
{
    if (is_dir($path)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Nepavyko sukurti katalogo: {$path}");
    }
}

function copy_tree(string $source, string $destination): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $target = $destination . '/' . $items->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException("Nepavyko sukurti katalogo: {$target}");
            }
        } else {
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException("Nepavyko sukurti katalogo: {$parent}");
            }
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("Nepavyko nukopijuoti: {$target}");
            }
        }
    }
}

/** @param array<string,mixed> $data */
function write_json(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Nepavyko sukurti katalogo: {$directory}");
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException("Nepavyko įrašyti: {$path}");
    }
}

/** @param array<string,mixed> $data */
function write_javascript_data(string $path, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    );
    if (file_put_contents($path, 'window.__KURAS_DATA=' . $json . ";\n") === false) {
        throw new RuntimeException("Nepavyko įrašyti: {$path}");
    }
}
