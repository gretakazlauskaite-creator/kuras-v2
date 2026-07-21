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

        $payload = (new StaticDataExporter())->export(
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
        self::assertNull($payload['stations'][0]['latitude']);
        self::assertArrayNotHasKey('download_url', $payload['source']);
    }
}
