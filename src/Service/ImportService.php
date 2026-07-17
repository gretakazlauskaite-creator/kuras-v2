<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\ImportRunRepository;
use App\Repository\PriceRepository;
use App\Repository\StationRepository;
use App\Repository\ValidationPayload;
use App\Service\Import\ImportResult;
use App\Service\Import\ImportValidator;
use App\Service\Import\LeaWorkbookParser;
use App\Service\Import\ValidationResult;

final class ImportService
{
    private \PDO $db;
    private StationRepository $stationRepository;
    private PriceRepository $priceRepository;
    private ImportRunRepository $runRepository;
    private LeaWorkbookParser $parser;
    private ImportValidator $validator;
    private GeocodingService $geocoder;
    private StationIdentity $stationIdentity;

    public int $importedPrices = 0;
    public int $newStations = 0;

    public function __construct(
        ?\PDO $db = null,
        ?LeaWorkbookParser $parser = null,
        ?ImportValidator $validator = null,
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->stationRepository = new StationRepository($this->db);
        $this->priceRepository = new PriceRepository($this->db);
        $this->runRepository = new ImportRunRepository($this->db);
        $this->parser = $parser ?? new LeaWorkbookParser();
        $this->validator = $validator ?? new ImportValidator(
            minimumStations: $this->envInt('IMPORT_MIN_STATIONS', 100),
            minimumPrices: $this->envInt('IMPORT_MIN_PRICES', 100),
            maximumSourceAgeDays: $this->envInt('IMPORT_MAX_SOURCE_AGE_DAYS', 7),
        );
        $this->geocoder = new GeocodingService();
        $this->stationIdentity = new StationIdentity();
    }

    public function importFromFile(
        string $filePath,
        string $sourceDate,
        string $sourcePageUrl,
        string $sourceUrl,
        string $checksum,
        bool $dryRun = false,
        bool $allowBackfill = false,
    ): ImportResult {
        $runId = null;
        if (!$dryRun) {
            $runId = $this->runRepository->create(
                sourcePageUrl: $sourcePageUrl,
                sourceUrl: $sourceUrl,
                sourceDate: $sourceDate,
                checksum: $checksum,
                filePath: $filePath,
                parserVersion: LeaWorkbookParser::VERSION,
            );

            if ($this->runRepository->wasPublished($checksum)) {
                $this->runRepository->markDuplicate($runId);
                return new ImportResult(
                    status: 'duplicate',
                    sourceDate: $sourceDate,
                    stationCount: 0,
                    priceCount: 0,
                    newStationCount: 0,
                    validation: new ValidationResult([], ['Šis šaltinio failas jau buvo publikuotas.'], [
                        'raw_rows' => 0,
                        'stations' => 0,
                        'prices' => 0,
                        'fuel_columns' => '',
                        'workbook_dates' => '',
                    ]),
                    runId: $runId,
                );
            }
        }

        try {
            $parsed = $this->parser->parse($filePath);
        } catch (\Throwable $exception) {
            if ($runId !== null) {
                $this->runRepository->markFailed($runId, $exception->getMessage());
            }
            throw $exception;
        }

        $latest = $this->runRepository->latestPublishedSummary();
        $validation = $this->validator->validate(
            parsed: $parsed,
            sourceDate: $sourceDate,
            latestPublishedDate: $latest['source_date'],
            previousPriceCount: $allowBackfill ? null : $latest['price_count'],
            allowBackfill: $allowBackfill,
        );

        if ($dryRun) {
            return new ImportResult(
                status: $validation->isValid() ? 'validated' : 'rejected',
                sourceDate: $sourceDate,
                stationCount: count($parsed->stations),
                priceCount: $parsed->priceCount(),
                newStationCount: 0,
                validation: $validation,
            );
        }

        $this->runRepository->recordValidation($runId, new ValidationPayload(
            valid: $validation->isValid(),
            rawRows: $parsed->rawRowCount,
            stations: count($parsed->stations),
            prices: $parsed->priceCount(),
            report: $validation->toArray(),
        ));

        if (!$validation->isValid()) {
            return new ImportResult(
                status: 'rejected',
                sourceDate: $sourceDate,
                stationCount: count($parsed->stations),
                priceCount: $parsed->priceCount(),
                newStationCount: 0,
                validation: $validation,
                runId: $runId,
            );
        }

        try {
            $this->db->beginTransaction();
            $this->publish($parsed->stations, $sourceDate, $runId);
            $this->runRepository->markPublished($runId, $this->newStations);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->runRepository->markFailed($runId, $exception->getMessage());
            throw $exception;
        }

        return new ImportResult(
            status: 'published',
            sourceDate: $sourceDate,
            stationCount: count($parsed->stations),
            priceCount: $this->importedPrices,
            newStationCount: $this->newStations,
            validation: $validation,
            runId: $runId,
        );
    }

    /**
     * @param list<array{brand:string,address:string,city:string,municipality:?string,prices:array<string,float>}> $stations
     */
    private function publish(array $stations, string $sourceDate, int $runId): void
    {
        $fuelTypes = [];
        foreach ($this->priceRepository->getAllFuelTypes() as $fuelType) {
            $fuelTypes[(string) $fuelType['slug']] = (int) $fuelType['id'];
        }

        $this->priceRepository->deleteByDate($sourceDate);
        foreach ($stations as $station) {
            $brandId = $this->upsertBrand($station['brand']);
            $identity = $this->stationIdentity->fromSource($station['brand'], $station['address']);
            $isNew = false;
            $stationId = $this->stationRepository->upsertReturnNew([
                ':brand_id' => $brandId,
                ':public_id' => $identity['public_id'],
                ':source_key' => $identity['source_key'],
                ':name' => $station['brand'] . ' — ' . $station['address'],
                ':address' => $station['address'],
                ':normalized_address' => $identity['normalized_address'],
                ':city' => $station['city'],
                ':municipality' => $station['municipality'],
            ], $isNew);

            $this->stationRepository->recordAlias(
                stationId: $stationId,
                sourceName: 'lea',
                aliasKey: $identity['source_key'],
                sourceBrand: $station['brand'],
                sourceAddress: $station['address'],
            );

            if ($isNew) {
                ++$this->newStations;
            }

            foreach ($station['prices'] as $slug => $price) {
                if (!isset($fuelTypes[$slug])) {
                    throw new \RuntimeException("Duomenų bazėje nėra kuro tipo: {$slug}");
                }

                $this->priceRepository->insertPrice(
                    stationId: $stationId,
                    fuelTypeId: $fuelTypes[$slug],
                    price: $price,
                    date: $sourceDate,
                    importRunId: $runId,
                );
                ++$this->importedPrices;
            }
        }
    }

    private function upsertBrand(string $name): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO brands (name) VALUES (:name)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name)',
        );
        $statement->execute([':name' => $name]);
        return (int) $this->db->lastInsertId();
    }

    public function geocodeNewStations(): int
    {
        $stations = $this->stationRepository->findWithoutCoordinates(100);
        $count = 0;
        foreach ($stations as $station) {
            $query = $station['address'] . ', ' . $station['city'] . ', Lithuania';
            $coordinates = $this->geocoder->geocode($query);
            if ($coordinates !== null) {
                $this->stationRepository->updateCoordinates(
                    (int) $station['id'],
                    $coordinates['lat'],
                    $coordinates['lng'],
                    $coordinates['provider'],
                    $coordinates['confidence'],
                );
                ++$count;
            }
            sleep(1);
        }

        return $count;
    }

    private function envInt(string $name, int $default): int
    {
        $value = $_ENV[$name] ?? getenv($name);
        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
