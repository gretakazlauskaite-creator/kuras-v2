<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\LeaLiveApiParser;
use PHPUnit\Framework\TestCase;

final class LeaLiveApiParserTest extends TestCase
{
    public function testItParsesAndGroupsTheOfficialLiveApiPayload(): void
    {
        $json = file_get_contents(dirname(__DIR__) . '/Fixtures/lea_live_api.json');
        self::assertIsString($json);

        $snapshot = (new LeaLiveApiParser())->parse($json);

        self::assertSame('2026-07-30', $snapshot->sourceDate);
        self::assertSame('2026-07-30T10:30:03+03:00', $snapshot->lastUpdated);
        self::assertSame(6, $snapshot->parsed->rawRowCount);
        self::assertCount(3, $snapshot->parsed->stations);
        self::assertSame(4, $snapshot->parsed->priceCount());
        self::assertSame(['pb95', 'diesel', 'lpg'], $snapshot->parsed->detectedFuelSlugs);

        $station = $snapshot->parsed->stations[0];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $station['source_id']);
        self::assertSame('Testas', $station['brand']);
        self::assertSame('Alytus', $station['city']);
        self::assertSame(54.40333915, $station['latitude']);
        self::assertSame(24.03722399, $station['longitude']);
        self::assertSame(1.649, $station['prices']['diesel']);
        self::assertSame('2026-07-30T09:30:31+03:00', $station['price_updated_at']['diesel']);

        $withoutPrice = array_values(array_filter(
            $snapshot->parsed->stations,
            static fn (array $item): bool => $item['brand'] === 'Be kainos',
        ))[0];
        self::assertSame([], $withoutPrice['prices']);
        self::assertSame(['pb95'], $withoutPrice['unavailable_fuels']);
    }

    public function testItRejectsPayloadWithoutAnOfficialUpdateTime(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atnaujinimo laikas');

        (new LeaLiveApiParser())->parse('{"data":[]}');
    }
}
