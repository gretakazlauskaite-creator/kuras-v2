<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wordpress/kuras-pricer/includes/class-kuras-pricer-query.php';

final class WordPressProxyQueryTest extends TestCase
{
    public function testItOnlyForwardsAllowedStationParameters(): void
    {
        $query = Kuras_Pricer_Query::sanitize('stations', [
            'fuel' => 'diesel',
            'city' => 'Vilnius',
            'per_page' => '25',
            'unknown' => 'must-not-pass',
        ]);

        self::assertSame(['fuel' => 'diesel', 'city' => 'Vilnius', 'per_page' => 25], $query);
    }

    public function testItRejectsInvalidFuel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Kuras_Pricer_Query::sanitize('stations', ['fuel' => 'rocket']);
    }

    public function testItRejectsIncompleteCoordinates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Kuras_Pricer_Query::sanitize('nearby', ['lat' => '54.68']);
    }

    public function testItValidatesMapBounds(): void
    {
        $query = Kuras_Pricer_Query::sanitize('map/stations', ['bounds' => '20.6,53.8,26.9,56.5']);
        self::assertSame('20.6,53.8,26.9,56.5', $query['bounds']);

        $this->expectException(InvalidArgumentException::class);
        Kuras_Pricer_Query::sanitize('map/stations', ['bounds' => '26.9,56.5,20.6,53.8']);
    }

    public function testItKeepsEndpointSpecificLimitsBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Kuras_Pricer_Query::sanitize('nearby', ['limit' => '51']);
    }
}
