<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\PriceRepository;
use App\Repository\StationRepository;
use PHPUnit\Framework\TestCase;

final class PublicDataRepositoryTest extends TestCase
{
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        $this->db = new \PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASS'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db?->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testProductionScaleCurrentPriceQueriesStayDeterministicAndBounded(): void
    {
        self::assertNotNull($this->db);
        $this->db->exec("INSERT INTO brands (name) VALUES ('API performance test')");
        $brandId = (int) $this->db->lastInsertId();
        $fuelId = (int) $this->db->query("SELECT id FROM fuel_types WHERE slug = 'pb95'")->fetchColumn();

        $stationInsert = $this->db->prepare(
            'INSERT INTO stations (
                brand_id, public_id, source_key, name, address, normalized_address, city, municipality
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $priceInsert = $this->db->prepare(
            'INSERT INTO prices (station_id, fuel_type_id, price, price_date) VALUES (?, ?, ?, ?)'
        );

        for ($index = 0; $index < 700; ++$index) {
            $hash = hash('sha256', 'integration-station-' . $index);
            $address = "Testų g. {$index}";
            $stationInsert->execute([
                $brandId,
                'st_' . substr($hash, 0, 20),
                $hash,
                "Testo degalinė {$index}",
                $address,
                mb_strtolower($address),
                $index % 2 === 0 ? 'Vilnius' : 'Kaunas',
                $index % 2 === 0 ? 'Vilniaus m. sav.' : 'Kauno m. sav.',
            ]);
            $priceInsert->execute([
                (int) $this->db->lastInsertId(),
                $fuelId,
                1.3 + (($index * 17) % 240) / 1000,
                '2026-07-17',
            ]);
        }

        $started = microtime(true);
        $result = (new StationRepository($this->db))->searchCurrent([
            'price_date' => '2026-07-17',
            'fuel' => 'pb95',
            'city' => '',
            'municipality' => '',
            'brand' => '',
            'q' => '',
            'min_price' => null,
            'max_price' => null,
            'lat' => null,
            'lng' => null,
            'sort' => 'price_asc',
        ], 1, 100);
        $duration = microtime(true) - $started;

        self::assertSame(700, $result['total']);
        self::assertCount(100, $result['data']);
        self::assertLessThanOrEqual((float) $result['data'][1]['price'], (float) $result['data'][0]['price']);
        self::assertLessThan(2.0, $duration, 'Current-price query exceeded the two-second CI budget.');

        $ranking = (new PriceRepository($this->db))->getRankings('pb95', '2026-07-17', [], 10);
        self::assertCount(10, $ranking);
        self::assertSame((float) $result['data'][0]['price'], $ranking[0]['price']);
        self::assertSame('2026-07-17', $ranking[0]['source_date']);
    }
}
