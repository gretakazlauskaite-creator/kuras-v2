#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Import\ImportValidator;
use App\Service\Import\LeaSource;
use App\Service\Import\LeaSourceLocator;
use App\Service\Import\LeaWorkbookParser;
use App\Service\Import\XlsxFileValidator;
use App\Service\StaticDataExporter;

$options = getopt('', ['file:', 'source-date:', 'output:', 'previous-data:', 'archive-output:', 'fixture', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/build-static.php [--file=prices.xlsx --source-date=YYYY-MM-DD --fixture] [--output=dist] [--previous-data=file.json] [--archive-output=path]\n";
    exit(0);
}

$root = dirname(__DIR__);
$output = isset($options['output']) ? (string) $options['output'] : $root . '/dist';
$previousDataPath = isset($options['previous-data'])
    ? (string) $options['previous-data']
    : $output . '/data/current.json';
$archiveOutput = isset($options['archive-output']) ? (string) $options['archive-output'] : null;
$fixtureMode = isset($options['fixture']);
$temporaryFile = null;

try {
    if (isset($options['file'])) {
        $file = (string) $options['file'];
        $sourceDate = (string) ($options['source-date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceDate)) {
            throw new RuntimeException('Naudojant --file būtina nurodyti --source-date=YYYY-MM-DD.');
        }
        $source = new LeaSource('fixture', 'fixture://' . basename($file), $sourceDate);
    } else {
        $pageHtml = http_get(LeaSourceLocator::PAGE_URL);
        $source = (new LeaSourceLocator())->locate($pageHtml);
        $temporaryFile = download_file($source->downloadUrl);
        $file = $temporaryFile;
    }

    (new XlsxFileValidator())->assertValid($file);
    $checksum = hash_file('sha256', $file);
    if ($checksum === false) {
        throw new RuntimeException('Nepavyko apskaičiuoti šaltinio failo kontrolinės sumos.');
    }

    $parsed = (new LeaWorkbookParser())->parse($file);
    $previous = read_previous_data($previousDataPath);
    $validator = $fixtureMode
        ? new ImportValidator(minimumStations: 1, minimumPrices: 1, maximumSourceAgeDays: 3650)
        : new ImportValidator();
    $validation = $validator->validate(
        parsed: $parsed,
        sourceDate: $source->sourceDate,
        latestPublishedDate: $fixtureMode ? null : ($previous['source']['source_date'] ?? null),
        previousPriceCount: $fixtureMode ? null : ($previous['summary']['price_count'] ?? null),
        allowBackfill: $fixtureMode,
    );

    foreach ($validation->warnings as $warning) {
        fwrite(STDERR, "ĮSPĖJIMAS: {$warning}\n");
    }
    if (!$validation->isValid()) {
        throw new RuntimeException("LEA duomenų patikra nepavyko:\n- " . implode("\n- ", $validation->errors));
    }

    recreate_directory($output);
    copy_tree($root . '/static', $output);
    $generatedAt = ($previous['source']['checksum_sha256'] ?? null) === $checksum
        ? (string) ($previous['source']['generated_at'] ?? gmdate('c'))
        : gmdate('c');
    $payload = (new StaticDataExporter())->export(
        parsed: $parsed,
        sourceDate: $source->sourceDate,
        generatedAt: $generatedAt,
        sourcePageUrl: $source->pageUrl,
        checksum: $checksum,
    );
    write_json($output . '/data/current.json', $payload);
    write_javascript_data($output . '/data/current.js', $payload);
    write_json($output . '/data/import-report.json', [
        'status' => 'published',
        'source_date' => $source->sourceDate,
        'generated_at' => $payload['source']['generated_at'],
        'checksum_sha256' => $checksum,
        'validation' => $validation->metrics,
        'warnings' => $validation->warnings,
    ]);

    if ($archiveOutput !== null) {
        recreate_directory($archiveOutput);
        if (!copy($file, $archiveOutput . '/source-' . $source->sourceDate . '-' . $checksum . '.xlsx')) {
            throw new RuntimeException('Nepavyko išsaugoti tikrinimo šaltinio kopijos.');
        }
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
} finally {
    if ($temporaryFile !== null && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
}

function http_get(string $url): string
{
    $context = stream_context_create(['http' => [
        'timeout' => 45,
        'user_agent' => 'KurasPricerBot/1.0 (+https://kuras.pricer.lt)',
        'follow_location' => 1,
        'max_redirects' => 5,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || trim($body) === '') {
        throw new RuntimeException("Nepavyko atsisiųsti: {$url}");
    }
    return $body;
}

function download_file(string $url): string
{
    $body = http_get($url);
    $path = tempnam(sys_get_temp_dir(), 'lea-static-');
    if ($path === false || file_put_contents($path, $body) === false) {
        throw new RuntimeException('Nepavyko išsaugoti laikino LEA failo.');
    }
    return $path;
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
