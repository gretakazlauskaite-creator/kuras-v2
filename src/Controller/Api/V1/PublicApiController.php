<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Cache;
use App\Database;
use App\Http\ApiException;
use App\Http\ApiRequest;
use App\Http\JsonResponse;
use App\Http\RateLimiter;
use App\Repository\ImportRunRepository;
use App\Repository\PriceRepository;
use App\Repository\StationRepository;
use App\Service\SourceFreshness;

final class PublicApiController
{
    private const FUEL_SLUGS = ['pb95', 'pb98', 'diesel', 'lpg'];

    private \PDO $db;
    private PriceRepository $prices;
    private StationRepository $stations;
    private ImportRunRepository $imports;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->prices = new PriceRepository($this->db);
        $this->stations = new StationRepository($this->db);
        $this->imports = new ImportRunRepository($this->db);
    }

    public function meta(): void
    {
        $this->respond('meta', function (): array {
            $source = $this->sourceMeta();
            $filters = $this->stations->getFilterOptions($source['source_date']);
            return [
                'data' => [
                    ...$source,
                    'available_fuels' => $filters['fuels'],
                    'update_frequency' => 'working_days_after_10_00',
                    'is_real_time' => false,
                ],
                'meta' => ['api_version' => 'v1'],
            ];
        }, 60);
    }

    public function filters(): void
    {
        $this->respond('filters', function (): array {
            $source = $this->sourceMeta();
            return [
                'data' => $this->stations->getFilterOptions($source['source_date']),
                'meta' => $this->responseMeta($source),
            ];
        }, 300);
    }

    public function stations(): void
    {
        $this->respond('stations', function (): array {
            $source = $this->sourceMeta();
            $request = ApiRequest::fromGlobals();
            $page = $request->integer('page', 1, 1, 10000);
            $perPage = $request->integer('per_page', 20, 1, 100);
            $sort = $request->enum('sort', ['price_asc', 'price_desc', 'name_asc', 'distance_asc'], 'price_asc');
            $lat = $request->number('lat', null, -90, 90);
            $lng = $request->number('lng', null, -180, 180);
            if (($lat === null) !== ($lng === null)) {
                throw new ApiException('coordinates_required', 'Atstumui reikia pateikti ir lat, ir lng.', 422);
            }
            if ($sort === 'distance_asc' && $lat === null) {
                throw new ApiException('coordinates_required', 'Rūšiavimui pagal atstumą reikia lat ir lng.', 422);
            }

            $filters = [
                'price_date' => $request->date('date', $source['source_date']),
                'fuel' => $request->enum('fuel', self::FUEL_SLUGS, 'pb95'),
                'city' => $request->string('city'),
                'municipality' => $request->string('municipality'),
                'brand' => $request->string('brand'),
                'q' => $request->string('q'),
                'min_price' => $request->number('min_price', null, 0.1, 10),
                'max_price' => $request->number('max_price', null, 0.1, 10),
                'lat' => $lat,
                'lng' => $lng,
                'sort' => $sort,
            ];
            if ($filters['min_price'] !== null && $filters['max_price'] !== null && $filters['min_price'] > $filters['max_price']) {
                throw new ApiException('invalid_price_range', 'Mažiausia kaina negali būti didesnė už didžiausią.', 422);
            }

            $result = $this->stations->searchCurrent($filters, $page, $perPage);
            $pages = max(1, (int) ceil($result['total'] / $perPage));
            return [
                'data' => array_map([$this, 'stationPayload'], $result['data']),
                'meta' => [
                    ...$this->responseMeta($source, $filters['price_date']),
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $result['total'],
                        'total_pages' => $pages,
                    ],
                    'sort' => $sort,
                ],
            ];
        }, 60);
    }

    public function station(string $identifier): void
    {
        $this->respond('station', function () use ($identifier): array {
            $this->assertStationIdentifier($identifier);
            $source = $this->sourceMeta();
            $station = $this->stations->findPublic($identifier);
            if ($station === null) {
                throw new ApiException('station_not_found', 'Degalinė nerasta.', 404);
            }

            $internalId = (int) $station['id'];
            $station['id'] = $station['public_id'];
            unset($station['public_id']);
            foreach (['lat', 'lng', 'coordinate_confidence'] as $key) {
                $station[$key] = $station[$key] !== null ? (float) $station[$key] : null;
            }
            foreach (['has_coffee', 'has_carwash', 'has_shop', 'has_loyalty'] as $key) {
                $station[$key] = (bool) $station[$key];
            }
            $station['prices'] = $this->prices->getCurrentPrices($internalId, $source['source_date']);

            return ['data' => $station, 'meta' => $this->responseMeta($source)];
        }, 60);
    }

    public function history(string $identifier): void
    {
        $this->respond('history', function () use ($identifier): array {
            $this->assertStationIdentifier($identifier);
            $source = $this->sourceMeta();
            $station = $this->stations->findPublic($identifier);
            if ($station === null) {
                throw new ApiException('station_not_found', 'Degalinė nerasta.', 404);
            }
            $request = ApiRequest::fromGlobals();
            $days = $request->integer('days', 30, 2, 366);
            $to = $request->date('to', $source['source_date']);
            $from = (new \DateTimeImmutable($to))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
            $fuel = $request->enum('fuel', self::FUEL_SLUGS, 'pb95');

            return [
                'data' => $this->prices->getHistoryRange((int) $station['id'], $fuel, $from, $to),
                'meta' => [
                    ...$this->responseMeta($source),
                    'station_id' => $station['public_id'],
                    'fuel' => $fuel,
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        }, 300);
    }

    public function map(): void
    {
        $this->respond('map', function (): array {
            $source = $this->sourceMeta();
            $request = ApiRequest::fromGlobals();
            $bounds = $request->bounds();
            $limit = $request->integer('limit', 500, 1, 1000);
            $filters = [
                'price_date' => $request->date('date', $source['source_date']),
                'fuel' => $request->enum('fuel', self::FUEL_SLUGS, 'pb95'),
                'city' => $request->string('city'),
                'municipality' => $request->string('municipality'),
                'brand' => $request->string('brand'),
            ];
            $rows = $this->stations->findForMap($filters, $bounds, $limit);
            $statistics = $this->prices->getStatistics($filters['fuel'], $filters['price_date']);
            $average = $statistics['national']['average_price'];

            $features = array_map(function (array $row) use ($average): array {
                $price = (float) $row['price'];
                $tier = 'average';
                if ($average !== null) {
                    $tier = $price <= $average - 0.02 ? 'cheap' : ($price >= $average + 0.02 ? 'expensive' : 'average');
                }
                return [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [(float) $row['lng'], (float) $row['lat']]],
                    'properties' => [
                        'id' => $row['public_id'], 'name' => $row['name'], 'brand' => $row['brand'],
                        'address' => $row['address'], 'city' => $row['city'],
                        'municipality' => $row['municipality'], 'fuel' => $row['fuel'],
                        'price' => $price, 'tier' => $tier,
                    ],
                ];
            }, $rows);

            return [
                'data' => ['type' => 'FeatureCollection', 'features' => $features],
                'meta' => [...$this->responseMeta($source, $filters['price_date']), 'average_price' => $average, 'returned' => count($features)],
            ];
        }, 60, 90);
    }

    public function nearby(): void
    {
        $this->respond('nearby', function (): array {
            $source = $this->sourceMeta();
            $request = ApiRequest::fromGlobals();
            $lat = $request->number('lat', null, -90, 90);
            $lng = $request->number('lng', null, -180, 180);
            if ($lat === null || $lng === null) {
                throw new ApiException('coordinates_required', 'Būtina pateikti lat ir lng.', 422);
            }
            $fuel = $request->enum('fuel', self::FUEL_SLUGS, 'pb95');
            $date = $request->date('date', $source['source_date']);
            $radius = $request->number('radius_km', 10, 0.5, 100) ?? 10;
            $limit = $request->integer('limit', 10, 1, 50);
            $rows = $this->stations->findNearbyCurrent($lat, $lng, $fuel, $date, $radius, $limit);
            return ['data' => array_map([$this, 'stationPayload'], $rows), 'meta' => [...$this->responseMeta($source, $date), 'radius_km' => $radius]];
        }, 30, 60);
    }

    public function rankings(): void
    {
        $this->respond('rankings', function (): array {
            $source = $this->sourceMeta();
            $request = ApiRequest::fromGlobals();
            $fuel = $request->enum('fuel', self::FUEL_SLUGS, 'pb95');
            $date = $request->date('date', $source['source_date']);
            $limit = $request->integer('limit', 10, 1, 100);
            $scope = [
                'city' => $request->string('city'),
                'municipality' => $request->string('municipality'),
                'brand' => $request->string('brand'),
            ];
            return ['data' => $this->prices->getRankings($fuel, $date, $scope, $limit), 'meta' => [...$this->responseMeta($source, $date), 'fuel' => $fuel]];
        }, 120);
    }

    public function statistics(): void
    {
        $this->respond('statistics', function (): array {
            $source = $this->sourceMeta();
            $request = ApiRequest::fromGlobals();
            $fuel = $request->enum('fuel', self::FUEL_SLUGS, 'pb95');
            $date = $request->date('date', $source['source_date']);
            return ['data' => $this->prices->getStatistics($fuel, $date), 'meta' => [...$this->responseMeta($source, $date), 'fuel' => $fuel]];
        }, 300);
    }

    /** @return array<string,mixed> */
    private function sourceMeta(): array
    {
        $published = $this->imports->latestPublished();
        $sourceDate = (string) ($published['source_date'] ?? $this->prices->getLatestPriceDate());
        $freshness = (new SourceFreshness())->evaluate($sourceDate);

        return [
            'source_date' => $sourceDate,
            'last_successful_import_at' => $published['published_at'] ?? $this->prices->getLatestImportTime(),
            'source' => [
                'name' => 'Lietuvos energetikos agentūra (LEA)',
                'page_url' => $published['source_page_url'] ?? 'https://www.ena.lt/degalu-kainos-degalinese/',
                'file_url' => $published['source_url'] ?? null,
            ],
            'freshness' => $freshness,
            'station_count' => $published['station_count'] ?? null,
            'price_count' => $published['price_count'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function responseMeta(array $source, ?string $date = null): array
    {
        return [
            'api_version' => 'v1',
            'source_date' => $date ?? $source['source_date'],
            'last_successful_import_at' => $source['last_successful_import_at'],
            'source' => $source['source'],
            'freshness' => $source['freshness'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function stationPayload(array $row): array
    {
        $row['id'] = $row['public_id'];
        unset($row['public_id']);
        foreach (['price', 'lat', 'lng', 'distance_km'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (float) $row[$key] : null;
            }
        }
        return $row;
    }

    private function assertStationIdentifier(string $identifier): void
    {
        if (!ctype_digit($identifier) && preg_match('/^st_[a-f0-9]{20}$/', $identifier) !== 1) {
            throw new ApiException('invalid_station_id', 'Neteisingas degalinės identifikatorius.', 422);
        }
    }

    private function respond(string $bucket, callable $callback, int $cacheSeconds = 60, int $limitPerMinute = 120): void
    {
        try {
            (new RateLimiter())->enforce('api-v1-' . $bucket, $limitPerMinute);
            $query = $_GET;
            ksort($query);
            $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: $bucket);
            $cacheKey = 'api-v1:' . hash('sha256', $path . '|' . json_encode($query, JSON_THROW_ON_ERROR));
            $hit = false;
            $body = Cache::get($cacheKey, $hit);
            if (!$hit) {
                $body = $callback();
                Cache::set($cacheKey, $body, $cacheSeconds);
            }
            header('X-Kuras-Cache: ' . ($hit ? 'HIT' : 'MISS'));
            JsonResponse::send($body, 200, $cacheSeconds);
        } catch (ApiException $exception) {
            JsonResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        } catch (\Throwable $exception) {
            @file_put_contents(
                sys_get_temp_dir() . '/kuras_error.log',
                date('[Y-m-d H:i:s]') . ' API ' . $exception::class . ': ' . $exception->getMessage() . "\n",
                FILE_APPEND | LOCK_EX,
            );
            JsonResponse::error('internal_error', 'Nepavyko įvykdyti užklausos.', 500);
        }
    }
}
