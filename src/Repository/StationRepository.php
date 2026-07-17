<?php

namespace App\Repository;

use App\Database;

class StationRepository
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, b.name AS brand_name, b.logo AS brand_logo
             FROM stations s
             JOIN brands b ON b.id = s.brand_id
             WHERE s.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findPublic(string $identifier): ?array
    {
        $where = ctype_digit($identifier) ? 's.id = :id' : 's.public_id = :id';
        $stmt = $this->db->prepare(
            "SELECT s.id, s.public_id, s.name, s.address, s.city, s.municipality,
                    s.lat, s.lng, s.coordinate_source, s.coordinate_confidence,
                    s.coordinates_updated_at, s.has_coffee, s.has_carwash,
                    s.has_shop, s.has_loyalty, s.profile_text,
                    b.name AS brand, b.logo AS brand_logo
             FROM stations s
             JOIN brands b ON b.id = s.brand_id
             WHERE {$where}"
        );
        $stmt->execute([':id' => $identifier]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Deterministic current-price search used by API v1.
     *
     * @param array<string,mixed> $filters
     * @return array{data:list<array<string,mixed>>,total:int}
     */
    public function searchCurrent(array $filters, int $page, int $perPage): array
    {
        $where = ['p.price_date = :price_date', 'ft.slug = :fuel'];
        $params = [
            ':price_date' => (string) $filters['price_date'],
            ':fuel' => (string) $filters['fuel'],
        ];

        foreach (['city' => 's.city', 'municipality' => 's.municipality', 'brand' => 'b.name'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $where[] = "{$column} = :{$filter}";
                $params[":{$filter}"] = (string) $filters[$filter];
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(s.name LIKE :search_name OR s.address LIKE :search_address OR s.city LIKE :search_city OR b.name LIKE :search_brand)';
            foreach ([':search_name', ':search_address', ':search_city', ':search_brand'] as $placeholder) {
                $params[$placeholder] = '%' . $filters['q'] . '%';
            }
        }
        if (($filters['min_price'] ?? null) !== null) {
            $where[] = 'p.price >= :min_price';
            $params[':min_price'] = (float) $filters['min_price'];
        }
        if (($filters['max_price'] ?? null) !== null) {
            $where[] = 'p.price <= :max_price';
            $params[':max_price'] = (float) $filters['max_price'];
        }

        $distance = 'NULL';
        if (($filters['lat'] ?? null) !== null && ($filters['lng'] ?? null) !== null) {
            $distance = '(6371 * ACOS(LEAST(1, GREATEST(-1,
                COS(RADIANS(:distance_lat)) * COS(RADIANS(s.lat))
                * COS(RADIANS(s.lng) - RADIANS(:distance_lng))
                + SIN(RADIANS(:distance_lat_2)) * SIN(RADIANS(s.lat))
            ))))';
            $params[':distance_lat'] = (float) $filters['lat'];
            $params[':distance_lat_2'] = (float) $filters['lat'];
            $params[':distance_lng'] = (float) $filters['lng'];
            $where[] = 's.lat IS NOT NULL AND s.lng IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);
        $joins = 'FROM stations s
                  JOIN brands b ON b.id = s.brand_id
                  JOIN prices p ON p.station_id = s.id
                  JOIN fuel_types ft ON ft.id = p.fuel_type_id';

        $countParams = array_filter(
            $params,
            static fn (string $key): bool => !str_starts_with($key, ':distance_'),
            ARRAY_FILTER_USE_KEY,
        );
        $count = $this->db->prepare("SELECT COUNT(*) {$joins} WHERE {$whereSql}");
        $count->execute($countParams);
        $total = (int) $count->fetchColumn();

        $sort = match ((string) ($filters['sort'] ?? 'price_asc')) {
            'price_desc' => 'p.price DESC, s.public_id ASC',
            'name_asc' => 'b.name ASC, s.name ASC, s.public_id ASC',
            'distance_asc' => 'distance_km ASC, p.price ASC, s.public_id ASC',
            default => 'p.price ASC, s.public_id ASC',
        };

        $sql = "SELECT s.id, s.public_id, s.name, s.address, s.city, s.municipality,
                       s.lat, s.lng, b.name AS brand, b.logo AS brand_logo,
                       p.price, p.price_date AS source_date, ft.slug AS fuel,
                       {$distance} AS distance_km
                {$joins}
                WHERE {$whereSql}
                ORDER BY {$sort}
                LIMIT :limit OFFSET :offset";
        $statement = $this->db->prepare($sql);
        foreach ($params + [':limit' => $perPage, ':offset' => ($page - 1) * $perPage] as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();

        return ['data' => $statement->fetchAll(), 'total' => $total];
    }

    /** @return array{fuels:list<array<string,mixed>>,municipalities:list<array<string,mixed>>,cities:list<array<string,mixed>>,brands:list<array<string,mixed>>} */
    public function getFilterOptions(string $date): array
    {
        $queries = [
            'fuels' => 'SELECT ft.slug AS value, ft.name AS label, COUNT(DISTINCT p.station_id) AS station_count
                        FROM prices p JOIN fuel_types ft ON ft.id = p.fuel_type_id
                        WHERE p.price_date = :date GROUP BY ft.id, ft.slug, ft.name ORDER BY ft.id',
            'municipalities' => 'SELECT s.municipality AS value, s.municipality AS label, COUNT(DISTINCT s.id) AS station_count
                                 FROM stations s JOIN prices p ON p.station_id = s.id
                                 WHERE p.price_date = :date AND s.municipality IS NOT NULL AND s.municipality != \'\'
                                 GROUP BY s.municipality ORDER BY s.municipality',
            'cities' => 'SELECT s.city AS value, s.city AS label, COUNT(DISTINCT s.id) AS station_count
                         FROM stations s JOIN prices p ON p.station_id = s.id
                         WHERE p.price_date = :date AND s.city != \'\'
                         GROUP BY s.city ORDER BY s.city',
            'brands' => 'SELECT b.name AS value, b.name AS label, COUNT(DISTINCT s.id) AS station_count
                         FROM brands b JOIN stations s ON s.brand_id = b.id JOIN prices p ON p.station_id = s.id
                         WHERE p.price_date = :date GROUP BY b.id, b.name ORDER BY b.name',
        ];

        $result = [];
        foreach ($queries as $key => $sql) {
            $statement = $this->db->prepare($sql);
            $statement->execute([':date' => $date]);
            $result[$key] = array_map(static function (array $row): array {
                $row['station_count'] = (int) $row['station_count'];
                return $row;
            }, $statement->fetchAll());
        }

        return $result;
    }

    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function findForMap(array $filters, array $bounds, int $limit): array
    {
        $where = [
            'p.price_date = :date', 'ft.slug = :fuel',
            's.lat BETWEEN :min_lat AND :max_lat',
            's.lng BETWEEN :min_lng AND :max_lng',
        ];
        $params = [
            ':date' => $filters['price_date'], ':fuel' => $filters['fuel'],
            ':min_lat' => $bounds['min_lat'], ':max_lat' => $bounds['max_lat'],
            ':min_lng' => $bounds['min_lng'], ':max_lng' => $bounds['max_lng'],
        ];
        foreach (['city' => 's.city', 'municipality' => 's.municipality', 'brand' => 'b.name'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $where[] = "{$column} = :{$filter}";
                $params[":{$filter}"] = $filters[$filter];
            }
        }

        $sql = 'SELECT s.id, s.public_id, s.name, s.address, s.city, s.municipality,
                       s.lat, s.lng, b.name AS brand, p.price, ft.slug AS fuel
                FROM stations s
                JOIN brands b ON b.id = s.brand_id
                JOIN prices p ON p.station_id = s.id
                JOIN fuel_types ft ON ft.id = p.fuel_type_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.price ASC, s.public_id ASC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function findNearbyCurrent(float $lat, float $lng, string $fuel, string $date, float $radiusKm, int $limit): array
    {
        $distance = '(6371 * ACOS(LEAST(1, GREATEST(-1,
            COS(RADIANS(:lat)) * COS(RADIANS(s.lat))
            * COS(RADIANS(s.lng) - RADIANS(:lng))
            + SIN(RADIANS(:lat_2)) * SIN(RADIANS(s.lat))
        ))))';
        $statement = $this->db->prepare(
            "SELECT s.id, s.public_id, s.name, s.address, s.city, s.municipality,
                    s.lat, s.lng, b.name AS brand, p.price, ft.slug AS fuel,
                    p.price_date AS source_date, {$distance} AS distance_km
             FROM stations s
             JOIN brands b ON b.id = s.brand_id
             JOIN prices p ON p.station_id = s.id AND p.price_date = :date
             JOIN fuel_types ft ON ft.id = p.fuel_type_id AND ft.slug = :fuel
             WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL
             HAVING distance_km <= :radius
             ORDER BY distance_km ASC, p.price ASC, s.public_id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':lat', $lat);
        $statement->bindValue(':lat_2', $lat);
        $statement->bindValue(':lng', $lng);
        $statement->bindValue(':date', $date);
        $statement->bindValue(':fuel', $fuel);
        $statement->bindValue(':radius', $radiusKm);
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['city'])) {
            $where[]           = 's.city = :city';
            $params[':city']   = $filters['city'];
        }
        if (!empty($filters['municipality'])) {
            $where[]                  = 's.municipality = :municipality';
            $params[':municipality']  = $filters['municipality'];
        }
        if (!empty($filters['brand'])) {
            $where[]           = 'b.name = :brand';
            $params[':brand']  = $filters['brand'];
        }

        $whereStr = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        // Total count
        $countSql = "SELECT COUNT(*) FROM stations s JOIN brands b ON b.id = s.brand_id WHERE $whereStr";
        if (!empty($filters['fuel'])) {
            // Only count stations that actually have a price for this fuel on this date
            $countSql = "SELECT COUNT(DISTINCT s.id)
                         FROM stations s
                         JOIN brands b      ON b.id  = s.brand_id
                         JOIN fuel_types ft ON ft.slug = :fuel_count
                         JOIN prices p      ON p.station_id = s.id
                                           AND p.fuel_type_id = ft.id
                                           AND p.price_date = :price_date_count
                         WHERE $whereStr";
        }
        $countParams = $params;
        if (!empty($filters['fuel'])) {
            $countParams[':fuel_count']       = $filters['fuel'];
            $countParams[':price_date_count'] = $filters['price_date'] ?? date('Y-m-d');
        }
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        // Data
        $sort  = 'price ASC';
        if (!empty($filters['fuel'])) {
            $params[':fuel']       = $filters['fuel'];
            $params[':price_date'] = $filters['price_date'] ?? date('Y-m-d');
            $sql = "SELECT s.id, s.name, s.address, s.city, s.municipality,
                           s.lat, s.lng, s.is_sponsored,
                           b.name AS brand, b.logo AS brand_logo,
                           p.price,
                           ft.slug AS fuel
                    FROM stations s
                    JOIN brands b      ON b.id  = s.brand_id
                    JOIN fuel_types ft ON ft.slug = :fuel
                    JOIN prices p      ON p.station_id = s.id
                        AND p.fuel_type_id = ft.id
                        AND p.price_date = :price_date
                    WHERE $whereStr
                    ORDER BY p.price ASC, s.name ASC
                    LIMIT :limit OFFSET :offset";
        } else {
            $sql = "SELECT s.id, s.name, s.address, s.city, s.municipality,
                           s.lat, s.lng, s.is_sponsored,
                           b.name AS brand, b.logo AS brand_logo,
                           NULL AS price, NULL AS fuel
                    FROM stations s
                    JOIN brands b ON b.id = s.brand_id
                    WHERE $whereStr
                    ORDER BY s.name ASC
                    LIMIT :limit OFFSET :offset";
        }

        $params[':limit']  = $perPage;
        $params[':offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return ['data' => $rows, 'total' => $total];
    }

    public function findInBounds(float $minLat, float $minLng, float $maxLat, float $maxLng, string $fuelSlug, int $limit = 500, string $date = ''): array
    {
        $date = $date ?: date('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT s.id, s.name, s.address, s.city, s.lat, s.lng, s.is_sponsored,
                    b.name AS brand, b.logo AS brand_logo,
                    p.price, ft.slug AS fuel
             FROM stations s
             JOIN  brands b      ON b.id  = s.brand_id
             LEFT JOIN fuel_types ft ON ft.slug = :fuel
             LEFT JOIN prices p  ON p.station_id = s.id
                                AND p.fuel_type_id = ft.id
                                AND p.price_date = :price_date
             WHERE s.lat BETWEEN :min_lat AND :max_lat
               AND s.lng BETWEEN :min_lng AND :max_lng
               AND s.lat IS NOT NULL
             ORDER BY p.price ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':fuel',       $fuelSlug);
        $stmt->bindValue(':price_date', $date);
        $stmt->bindValue(':min_lat',    $minLat);
        $stmt->bindValue(':max_lat',    $maxLat);
        $stmt->bindValue(':min_lng',    $minLng);
        $stmt->bindValue(':max_lng',    $maxLng);
        $stmt->bindValue(':limit',      $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findNearby(float $lat, float $lng, string $fuelSlug = 'pb95', float $radiusKm = 5, int $limit = 3, string $date = ''): array
    {
        $date = $date ?: date('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT s.id, s.name, s.address, s.city,
                    b.name AS brand,
                    p.price, ft.slug AS fuel,
                    (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(s.lat))
                        * COS(RADIANS(s.lng) - RADIANS(:lng))
                        + SIN(RADIANS(:lat2)) * SIN(RADIANS(s.lat))
                    )) AS distance_km
             FROM stations s
             JOIN brands b ON b.id = s.brand_id
             LEFT JOIN fuel_types ft ON ft.slug = :fuel
             LEFT JOIN prices p ON p.station_id = s.id
                 AND p.fuel_type_id = ft.id
                 AND p.price_date = :price_date
             WHERE s.lat IS NOT NULL
             HAVING distance_km <= :radius
             ORDER BY distance_km ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':lat',        $lat);
        $stmt->bindValue(':lat2',       $lat);
        $stmt->bindValue(':lng',        $lng);
        $stmt->bindValue(':fuel',       $fuelSlug);
        $stmt->bindValue(':price_date', $date);
        $stmt->bindValue(':radius',     $radiusKm);
        $stmt->bindValue(':limit',      $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findNearbyAllFuels(float $lat, float $lng, int $excludeId = 0, float $radiusKm = 5, int $limit = 5, string $date = ''): array
    {
        $date = $date ?: date('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT s.id, s.name, s.address, s.city,
                    b.name AS brand,
                    (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(s.lat))
                        * COS(RADIANS(s.lng) - RADIANS(:lng))
                        + SIN(RADIANS(:lat2)) * SIN(RADIANS(s.lat))
                    )) AS distance_km,
                    MAX(CASE WHEN ft.slug = \'pb95\'   THEN p.price END) AS price_pb95,
                    MAX(CASE WHEN ft.slug = \'diesel\' THEN p.price END) AS price_diesel,
                    MAX(CASE WHEN ft.slug = \'lpg\'    THEN p.price END) AS price_lpg
             FROM stations s
             JOIN brands b ON b.id = s.brand_id
             LEFT JOIN prices p ON p.station_id = s.id AND p.price_date = :price_date
             LEFT JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE s.lat IS NOT NULL AND s.id != :exclude
             GROUP BY s.id, s.name, s.address, s.city, b.name
             HAVING distance_km <= :radius
             ORDER BY distance_km ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':lat',        $lat);
        $stmt->bindValue(':lat2',       $lat);
        $stmt->bindValue(':lng',        $lng);
        $stmt->bindValue(':price_date', $date);
        $stmt->bindValue(':exclude',    $excludeId, \PDO::PARAM_INT);
        $stmt->bindValue(':radius',     $radiusKm);
        $stmt->bindValue(':limit',      $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findWithoutCoordinates(int $limit = 50, array $excludeIds = []): array
    {
        $excl = $excludeIds ? 'AND s.id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')' : '';
        $stmt = $this->db->prepare(
            "SELECT s.id, s.name, s.address, s.city, s.municipality
             FROM stations s
             WHERE s.lat IS NULL $excl
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateCoordinates(int $id, float $lat, float $lng, string $source = 'geocoder', ?float $confidence = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE stations
             SET lat = :lat, lng = :lng, geocoded_at = UTC_TIMESTAMP(),
                 coordinate_source = :source, coordinate_confidence = :confidence,
                 coordinates_updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            ':lat' => $lat, ':lng' => $lng, ':source' => $source,
            ':confidence' => $confidence, ':id' => $id,
        ]);
    }

    public function overrideCoordinates(int $id, float $lat, float $lng, string $reason, string $changedBy): void
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || trim($reason) === '' || trim($changedBy) === '') {
            throw new \InvalidArgumentException('Neteisingi koordinačių koregavimo duomenys.');
        }

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT lat, lng FROM stations WHERE id = :id FOR UPDATE');
            $statement->execute([':id' => $id]);
            $previous = $statement->fetch();
            if ($previous === false) {
                throw new \RuntimeException('Degalinė nerasta.');
            }

            $audit = $this->db->prepare(
                'INSERT INTO station_coordinate_overrides (
                    station_id, previous_lat, previous_lng, new_lat, new_lng, reason, changed_by
                 ) VALUES (:station_id, :previous_lat, :previous_lng, :new_lat, :new_lng, :reason, :changed_by)'
            );
            $audit->execute([
                ':station_id' => $id, ':previous_lat' => $previous['lat'], ':previous_lng' => $previous['lng'],
                ':new_lat' => $lat, ':new_lng' => $lng, ':reason' => trim($reason), ':changed_by' => trim($changedBy),
            ]);
            $this->updateCoordinates($id, $lat, $lng, 'manual', 1.0);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function upsert(array $data): int
    {
        $isNew = false;
        return $this->upsertReturnNew($data, $isNew);
    }

    public function upsertReturnNew(array $data, bool &$isNew): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO stations (
                brand_id, public_id, source_key, name, address, normalized_address, city, municipality
             ) VALUES (
                :brand_id, :public_id, :source_key, :name, :address, :normalized_address, :city, :municipality
             )
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                public_id = VALUES(public_id),
                source_key = VALUES(source_key),
                name = VALUES(name),
                normalized_address = VALUES(normalized_address),
                city = VALUES(city),
                municipality = VALUES(municipality)'
        );
        $stmt->execute($data);
        $stationId = (int)$this->db->lastInsertId();

        $created = $this->db->query('SELECT ROW_COUNT()')->fetchColumn();
        $isNew = (int) $created === 1;
        return $stationId;
    }

    public function recordAlias(int $stationId, string $sourceName, string $aliasKey, string $sourceBrand, string $sourceAddress): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO station_aliases (
                station_id, source_name, alias_key, source_brand, source_address
             ) VALUES (
                :station_id, :source_name, :alias_key, :source_brand, :source_address
             )
             ON DUPLICATE KEY UPDATE
                station_id = VALUES(station_id), source_brand = VALUES(source_brand),
                source_address = VALUES(source_address), last_seen_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            ':station_id' => $stationId,
            ':source_name' => $sourceName,
            ':alias_key' => $aliasKey,
            ':source_brand' => $sourceBrand,
            ':source_address' => $sourceAddress,
        ]);
    }

    public function update(int $id, array $data): void
    {
        $fields = array_map(fn($k) => "$k = :$k", array_keys($data));
        $sql    = 'UPDATE stations SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $data['id'] = $id;
        $this->db->prepare($sql)->execute($data);
    }

    public function getAvailableBrands(string $municipality = '', string $fuelSlug = '', string $date = ''): array
    {
        $date   = $date ?: date('Y-m-d');
        $where  = ['1=1'];
        $params = [];

        if ($municipality !== '') {
            $where[]         = 's.municipality = :muni';
            $params[':muni'] = $municipality;
        }

        if ($fuelSlug !== '') {
            // Filter to stations that actually have a price for this fuel today.
            // The JOIN ON clause already restricts to the right fuel — no need to
            // repeat :fuel in WHERE (PDO named params cannot appear twice).
            $params[':fuel'] = $fuelSlug;
            $params[':date'] = $date;
            $priceJoin = 'JOIN fuel_types ft ON ft.slug = :fuel
                          JOIN prices p      ON p.station_id    = s.id
                                           AND p.fuel_type_id   = ft.id
                                           AND p.price_date     = :date';
        } else {
            $priceJoin = '';
        }

        $whereStr = implode(' AND ', $where);
        $sql = "SELECT DISTINCT b.name
                FROM stations s
                JOIN brands b ON b.id = s.brand_id
                {$priceJoin}
                WHERE {$whereStr}
                ORDER BY b.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getMunicipalities(): array
    {
        return $this->db
            ->query('SELECT DISTINCT municipality FROM stations WHERE municipality IS NOT NULL ORDER BY municipality')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getCitiesByMunicipality(string $municipality): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT city FROM stations WHERE municipality = :muni ORDER BY city'
        );
        $stmt->execute([':muni' => $municipality]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getCities(): array
    {
        return $this->db->query('SELECT DISTINCT city FROM stations ORDER BY city')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getMunicipalitiesByCity(string $city): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT municipality FROM stations WHERE city = :city AND municipality IS NOT NULL ORDER BY municipality');
        $stmt->execute([':city' => $city]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getBrands(): array
    {
        return $this->db->query('SELECT id, name, logo FROM brands ORDER BY name')->fetchAll();
    }
}
