<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\ParsedImport;
use App\Service\StaticDataExporter;
use PHPUnit\Framework\TestCase;

final class StaticDataExporterTest extends TestCase
{
    public function testItExportsAStablePublicPayloadWithoutInfrastructureData(): void
    {
        $parsed = new ParsedImport([
            [
                'brand' => 'Testas',
                'address' => 'Vilnius, Testų g. 1',
                'city' => 'Vilnius',
                'municipality' => 'Vilniaus m. sav.',
                'prices' => ['pb95' => 1.499, 'diesel' => 1.399],
            ],
        ], ['pb95', 'diesel'], 2);

        $stationId = substr(hash('sha256', 'testas|vilnius, testų g. 1'), 0, 16);
        $payload = (new StaticDataExporter([
            $stationId => ['latitude' => 54.687157, 'longitude' => 25.279652],
        ]))->export(
            $parsed,
            '2026-07-21',
            '2026-07-21T09:30:00Z',
            'https://www.ena.lt/degalu-kainos-degalinese/',
            str_repeat('a', 64),
        );

        self::assertSame(1, $payload['schema_version']);
        self::assertFalse($payload['demo']);
        self::assertSame('2026-07-21', $payload['source']['source_date']);
        self::assertSame(2, $payload['summary']['price_count']);
        self::assertSame('Testas', $payload['stations'][0]['brand']);
        self::assertSame(16, strlen($payload['stations'][0]['id']));
        self::assertSame(1, $payload['summary']['coordinate_count']);
        self::assertSame(54.687157, $payload['stations'][0]['latitude']);
        self::assertSame(25.279652, $payload['stations'][0]['longitude']);
        self::assertArrayNotHasKey('download_url', $payload['source']);
    }

    public function testItIgnoresCoordinatesOutsideLithuania(): void
    {
        $parsed = new ParsedImport([[
            'brand' => 'Testas',
            'address' => 'Vilnius, Testų g. 1',
            'city' => 'Vilnius',
            'municipality' => 'Vilniaus m. sav.',
            'prices' => ['pb95' => 1.499],
        ]], ['pb95'], 1);
        $stationId = substr(hash('sha256', 'testas|vilnius, testų g. 1'), 0, 16);

        $payload = (new StaticDataExporter([
            $stationId => ['latitude' => 0, 'longitude' => 0],
        ]))->export($parsed, '2026-07-21', '2026-07-21T09:30:00Z', 'https://www.ena.lt/', str_repeat('a', 64));

        self::assertSame(0, $payload['summary']['coordinate_count']);
        self::assertNull($payload['stations'][0]['latitude']);
        self::assertNull($payload['stations'][0]['longitude']);
    }

    public function testItPrefersOfficialApiCoordinatesAndExposesTheApiUpdateTime(): void
    {
        $parsed = new ParsedImport([[
            'source_id' => 'official-station-id',
            'brand' => 'Testas',
            'address' => 'Alytus, Testų g. 1',
            'city' => 'Alytus',
            'municipality' => 'Alytaus m. sav.',
            'latitude' => 54.40333915,
            'longitude' => 24.03722399,
            'prices' => ['pb95' => 1.699],
            'price_updated_at' => ['pb95' => '2026-07-30T09:30:31+03:00'],
            'unavailable_fuels' => ['diesel'],
        ]], ['pb95'], 1);

        $payload = (new StaticDataExporter())->export(
            parsed: $parsed,
            sourceDate: '2026-07-30',
            generatedAt: '2026-07-30T07:31:00Z',
            sourcePageUrl: 'https://degalukainos.ena.lt/',
            checksum: str_repeat('b', 64),
            parserVersion: 'lea-live-api-v1',
            sourceUpdatedAt: '2026-07-30T10:30:03+03:00',
        );

        self::assertSame(1, $payload['summary']['coordinate_count']);
        self::assertSame(54.40333915, $payload['stations'][0]['latitude']);
        self::assertSame(24.03722399, $payload['stations'][0]['longitude']);
        self::assertSame('lea-live-api-v1', $payload['source']['parser_version']);
        self::assertSame('2026-07-30T10:30:03+03:00', $payload['source']['source_updated_at']);
        self::assertSame('2026-07-30T09:30:31+03:00', $payload['stations'][0]['price_updated_at']['pb95']);
        self::assertSame(['diesel'], $payload['stations'][0]['unavailable_fuels']);
    }
}
