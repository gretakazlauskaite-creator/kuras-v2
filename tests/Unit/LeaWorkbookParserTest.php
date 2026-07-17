<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\LeaWorkbookParser;
use PHPUnit\Framework\TestCase;

final class LeaWorkbookParserTest extends TestCase
{
    public function testItMapsFuelColumnsWithoutMixingPb95AndPb98(): void
    {
        $parsed = (new LeaWorkbookParser())->parse(
            dirname(__DIR__) . '/Fixtures/lea_prices_valid.xlsx',
        );

        self::assertSame(4, $parsed->rawRowCount);
        self::assertSame(['pb95', 'pb98', 'diesel', 'lpg'], $parsed->detectedFuelSlugs);
        self::assertCount(4, $parsed->stations);
        self::assertSame([], $parsed->issues);

        $first = $parsed->stations[0];
        self::assertSame('Circle K', $first['brand']);
        self::assertSame('Vilnius', $first['city']);
        self::assertSame('Vilniaus m. sav.', $first['municipality']);
        self::assertSame(1.499, $first['prices']['pb95']);
        self::assertSame(1.599, $first['prices']['pb98']);
        self::assertSame(1.399, $first['prices']['diesel']);
        self::assertSame(0.699, $first['prices']['lpg']);
    }
}
