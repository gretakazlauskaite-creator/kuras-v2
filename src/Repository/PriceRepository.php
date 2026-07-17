<?php

namespace App\Repository;

use App\Database;

class PriceRepository
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Returns the most recent date that has price data, or today if the DB is empty.
     */
    public function getLatestPriceDate(): string
    {
        $date = $this->db->query('SELECT MAX(price_date) FROM prices')->fetchColumn();
        return $date ?: date('Y-m-d');
    }

    public function getLatestImportTime(): ?string
    {
        $value = $this->db->query('SELECT MAX(imported_at) FROM prices')->fetchColumn();
        return $value !== false && $value !== null ? (string) $value : null;
    }

    /** @return list<array<string,mixed>> */
    public function getCurrentPrices(int $stationId, string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT ft.slug AS fuel, ft.name AS fuel_name, p.price, p.price_date AS source_date
             FROM prices p
             JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE p.station_id = :station_id AND p.price_date = :date
             ORDER BY ft.id'
        );
        $statement->execute([':station_id' => $stationId, ':date' => $date]);
        return array_map(static function (array $row): array {
            $row['price'] = (float) $row['price'];
            return $row;
        }, $statement->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function getHistoryRange(int $stationId, string $fuel, string $from, string $to, int $limit = 366): array
    {
        $statement = $this->db->prepare(
            'SELECT p.price_date AS date, p.price, ft.slug AS fuel
             FROM prices p
             JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE p.station_id = :station_id AND ft.slug = :fuel
               AND p.price_date BETWEEN :date_from AND :date_to
             ORDER BY p.price_date ASC
             LIMIT :limit'
        );
        $statement->bindValue(':station_id', $stationId, \PDO::PARAM_INT);
        $statement->bindValue(':fuel', $fuel);
        $statement->bindValue(':date_from', $from);
        $statement->bindValue(':date_to', $to);
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();
        return array_map(static function (array $row): array {
            $row['price'] = (float) $row['price'];
            return $row;
        }, $statement->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function getRankings(string $fuel, string $date, array $scope, int $limit): array
    {
        $where = ['ft.slug = :fuel', 'p.price_date = :date'];
        $params = [':fuel' => $fuel, ':date' => $date];
        foreach (['city' => 's.city', 'municipality' => 's.municipality', 'brand' => 'b.name'] as $key => $column) {
            if (($scope[$key] ?? '') !== '') {
                $where[] = "{$column} = :{$key}";
                $params[":{$key}"] = (string) $scope[$key];
            }
        }

        $statement = $this->db->prepare(
            'SELECT s.id, s.public_id, s.name, s.address, s.city, s.municipality,
                    s.lat, s.lng, b.name AS brand, ft.slug AS fuel,
                    p.price, p.price_date AS source_date
             FROM prices p
             JOIN stations s ON s.id = p.station_id
             JOIN brands b ON b.id = s.brand_id
             JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.price ASC, s.public_id ASC LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();
        return array_map(static function (array $row): array {
            $row['id'] = $row['public_id'];
            unset($row['public_id']);
            $row['price'] = (float) $row['price'];
            $row['lat'] = $row['lat'] !== null ? (float) $row['lat'] : null;
            $row['lng'] = $row['lng'] !== null ? (float) $row['lng'] : null;
            return $row;
        }, $statement->fetchAll());
    }

    /** @return array{national:array<string,mixed>,regions:list<array<string,mixed>>} */
    public function getStatistics(string $fuel, string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS station_count, MIN(p.price) AS min_price,
                    MAX(p.price) AS max_price, AVG(p.price) AS average_price
             FROM prices p JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE ft.slug = :fuel AND p.price_date = :date'
        );
        $statement->execute([':fuel' => $fuel, ':date' => $date]);
        $national = $statement->fetch() ?: [];

        $regionsStatement = $this->db->prepare(
            'SELECT s.municipality, COUNT(*) AS station_count, MIN(p.price) AS min_price,
                    MAX(p.price) AS max_price, AVG(p.price) AS average_price
             FROM prices p
             JOIN fuel_types ft ON ft.id = p.fuel_type_id
             JOIN stations s ON s.id = p.station_id
             WHERE ft.slug = :fuel AND p.price_date = :date AND s.municipality IS NOT NULL
             GROUP BY s.municipality
             ORDER BY average_price ASC, s.municipality ASC'
        );
        $regionsStatement->execute([':fuel' => $fuel, ':date' => $date]);

        $cast = static function (array $row): array {
            $row['station_count'] = (int) ($row['station_count'] ?? 0);
            foreach (['min_price', 'max_price', 'average_price'] as $key) {
                $row[$key] = $row[$key] !== null ? round((float) $row[$key], 3) : null;
            }
            return $row;
        };

        return [
            'national' => $cast($national),
            'regions' => array_map($cast, $regionsStatement->fetchAll()),
        ];
    }

    /**
     * Best price per major city for a given fuel and date.
     * Returns associative array keyed by city name.
     *
     * Uses a CTE to compute the per-city minimum once rather than as a
     * correlated subquery for every candidate row.
     */
    public function getBestPriceByCities(int $fuelTypeId, string $date = ''): array
    {
        $date   = $date ?: $this->getLatestPriceDate();
        $cities = ['Vilnius','Kaunas','Klaipėda','Šiauliai','Panevėžys','Alytus','Marijampolė'];
        $ph     = implode(',', array_fill(0, count($cities), '?'));

        // city_min derived table computes MIN(price) per city once;
        // the outer query joins back to pick the cheapest station per city.
        // Using derived table (not CTE) for MySQL 5.7 compatibility.
        $stmt = $this->db->prepare(
            "SELECT s.city, s.id AS station_id, s.name AS station_name,
                    b.name AS brand, b.logo, p.price
             FROM (
                 SELECT s2.city, MIN(p2.price) AS min_price
                 FROM prices p2
                 JOIN stations s2 ON s2.id = p2.station_id
                 WHERE p2.price_date = ? AND p2.fuel_type_id = ?
                   AND s2.city IN ($ph)
                 GROUP BY s2.city
             ) AS city_min
             JOIN stations s ON s.city = city_min.city
             JOIN prices   p ON p.station_id   = s.id
                             AND p.price_date   = ?
                             AND p.fuel_type_id = ?
                             AND p.price        = city_min.min_price
             JOIN brands   b ON b.id = s.brand_id
             ORDER BY s.city, s.is_sponsored DESC, s.id"
        );

        // date and fuelTypeId appear in both the derived table and the outer query
        $params = array_merge([$date, $fuelTypeId], $cities, [$date, $fuelTypeId]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            if (!isset($result[$row['city']])) {
                $result[$row['city']] = $row;
            }
        }
        return $result;
    }

    /**
     * All prices for one station over last N days (for chart).
     */
    public function getPriceHistory(int $stationId, int $days = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT ft.slug, ft.name AS fuel_name, p.price, p.price_date
             FROM prices p
             JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE p.station_id = :station_id
               AND p.price_date >= CURDATE() - INTERVAL :days DAY
             ORDER BY ft.slug, p.price_date'
        );
        $stmt->bindValue(':station_id', $stationId, \PDO::PARAM_INT);
        $stmt->bindValue(':days',       $days,      \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Today's (or latest available date's) prices for a single station (all fuels).
     */
    public function getTodayPrices(int $stationId, string $date = ''): array
    {
        $date = $date ?: $this->getLatestPriceDate();
        $stmt = $this->db->prepare(
            'SELECT ft.slug, ft.name, p.price
             FROM prices p
             JOIN fuel_types ft ON ft.id = p.fuel_type_id
             WHERE p.station_id = :station_id AND p.price_date = :date
             ORDER BY ft.id'
        );
        $stmt->execute([':station_id' => $stationId, ':date' => $date]);
        return $stmt->fetchAll();
    }

    /**
     * City average price for a fuel type on a given date.
     */
    public function getCityAverage(string $city, int $fuelTypeId, string $date = ''): float
    {
        $date = $date ?: $this->getLatestPriceDate();
        $stmt = $this->db->prepare(
            'SELECT AVG(p.price)
             FROM prices p
             JOIN stations s ON s.id = p.station_id
             WHERE s.city = :city
               AND p.fuel_type_id = :fuel
               AND p.price_date = :date'
        );
        $stmt->execute([':city' => $city, ':fuel' => $fuelTypeId, ':date' => $date]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * National average for a fuel type on a given date.
     */
    public function getNationalAverage(int $fuelTypeId, string $date = ''): float
    {
        $date = $date ?: $this->getLatestPriceDate();
        $stmt = $this->db->prepare(
            'SELECT AVG(price) FROM prices WHERE fuel_type_id = :fuel AND price_date = :date'
        );
        $stmt->execute([':fuel' => $fuelTypeId, ':date' => $date]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Best station of day / week / month for a fuel type.
     * Period: 'day', 'week', 'month'
     */
    public function getBestStation(int $fuelTypeId, string $period = 'day', string $date = ''): ?array
    {
        $date = $date ?: $this->getLatestPriceDate();

        if ($period === 'day') {
            $stmt = $this->db->prepare(
                'SELECT s.id, s.name, s.address, s.city, b.name AS brand, b.logo, p.price
                 FROM prices p
                 JOIN stations s ON s.id = p.station_id
                 JOIN brands b   ON b.id = s.brand_id
                 WHERE p.price_date = :date AND p.fuel_type_id = :fuel
                 ORDER BY p.price ASC, s.is_sponsored DESC
                 LIMIT 1'
            );
            $stmt->execute([':fuel' => $fuelTypeId, ':date' => $date]);
            return $stmt->fetch() ?: null;
        }

        $interval = $period === 'week' ? '7 DAY' : '30 DAY';

        // Precompute national daily minimum once via derived table (MySQL 5.7-compatible).
        // Then count how many days in the window each station was cheapest.
        $stmt = $this->db->prepare(
            "SELECT f.station_id, COUNT(*) AS days_cheapest
             FROM prices f
             JOIN (
                 SELECT price_date, MIN(price) AS min_price
                 FROM prices
                 WHERE fuel_type_id = ?
                   AND price_date >= DATE_SUB(?, INTERVAL $interval)
                 GROUP BY price_date
             ) AS daily_min ON daily_min.price_date = f.price_date
                            AND daily_min.min_price  = f.price
             WHERE f.fuel_type_id = ?
               AND f.price_date >= DATE_SUB(?, INTERVAL $interval)
             GROUP BY f.station_id
             ORDER BY days_cheapest DESC, f.station_id
             LIMIT 1"
        );
        // fuel and date each appear twice (in subquery + outer query)
        $stmt->execute([$fuelTypeId, $date, $fuelTypeId, $date]);
        $best = $stmt->fetch();
        if (!$best) return null;

        $stmt2 = $this->db->prepare(
            'SELECT s.id, s.name, s.address, s.city, b.name AS brand, b.logo,
                    p.price, :days AS days_cheapest
             FROM stations s
             JOIN brands b ON b.id = s.brand_id
             LEFT JOIN prices p ON p.station_id = s.id AND p.price_date = :date AND p.fuel_type_id = :fuel
             WHERE s.id = :id'
        );
        $stmt2->execute([':id' => $best['station_id'], ':fuel' => $fuelTypeId, ':days' => $best['days_cheapest'], ':date' => $date]);
        return $stmt2->fetch() ?: null;
    }

    public function insertPrice(
        int $stationId,
        int $fuelTypeId,
        float $price,
        string $date,
        ?int $importRunId = null,
    ): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO prices (station_id, fuel_type_id, price, price_date, import_run_id)
             VALUES (:station_id, :fuel_type_id, :price, :price_date, :import_run_id)
             ON DUPLICATE KEY UPDATE
                price = VALUES(price),
                import_run_id = VALUES(import_run_id),
                imported_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':station_id'  => $stationId,
            ':fuel_type_id' => $fuelTypeId,
            ':price'        => $price,
            ':price_date'   => $date,
            ':import_run_id' => $importRunId,
        ]);
    }

    public function deleteByDate(string $date): void
    {
        $statement = $this->db->prepare('DELETE FROM prices WHERE price_date = :price_date');
        $statement->execute([':price_date' => $date]);
    }

    public function getFuelTypes(): array
    {
        return $this->db->query("SELECT * FROM fuel_types WHERE slug != 'pb98' ORDER BY id")->fetchAll();
    }

    /** All known types for ingestion, including types absent from the current public source. */
    public function getAllFuelTypes(): array
    {
        return $this->db->query('SELECT * FROM fuel_types ORDER BY id')->fetchAll();
    }

    public function getFuelTypeBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM fuel_types WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }
}
