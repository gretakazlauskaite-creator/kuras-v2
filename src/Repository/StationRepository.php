<?php

namespace App\Repository;

use App\Database;

class StationRepository
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
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

    public function updateCoordinates(int $id, float $lat, float $lng): void
    {
        $stmt = $this->db->prepare(
            'UPDATE stations SET lat = :lat, lng = :lng, geocoded_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':lat' => $lat, ':lng' => $lng, ':id' => $id]);
    }

    public function upsert(array $data): int
    {
        $isNew = false;
        return $this->upsertReturnNew($data, $isNew);
    }

    public function upsertReturnNew(array $data, bool &$isNew): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO stations (brand_id, name, address, city, municipality)
             VALUES (:brand_id, :name, :address, :city, :municipality)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                city = VALUES(city),
                municipality = VALUES(municipality)'
        );
        $stmt->execute($data);
        $newId = (int)$this->db->lastInsertId();

        if ($newId > 0) {
            $isNew = true;
            return $newId;
        }

        // Was an UPDATE (ON DUPLICATE KEY) — fetch existing id
        $isNew = false;
        $stmt2 = $this->db->prepare('SELECT id FROM stations WHERE brand_id = :brand_id AND address = :address');
        $stmt2->execute([':brand_id' => $data[':brand_id'], ':address' => $data[':address']]);
        return (int)$stmt2->fetchColumn();
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
