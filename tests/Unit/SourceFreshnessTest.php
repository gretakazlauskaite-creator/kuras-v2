<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\SourceFreshness;
use PHPUnit\Framework\TestCase;

final class SourceFreshnessTest extends TestCase
{
    public function testFridayDataIsCurrentOnWeekend(): void
    {
        $now = new \DateTimeImmutable('2026-07-19 15:00:00', new \DateTimeZone('Europe/Vilnius'));
        $result = (new SourceFreshness())->evaluate('2026-07-17', $now);

        self::assertFalse($result['is_stale']);
        self::assertSame('2026-07-17', $result['expected_source_date']);
    }

    public function testPreviousWorkingDayIsExpectedBeforePublication(): void
    {
        $now = new \DateTimeImmutable('2026-07-20 09:30:00', new \DateTimeZone('Europe/Vilnius'));
        $result = (new SourceFreshness())->evaluate('2026-07-17', $now);

        self::assertFalse($result['is_stale']);
    }

    public function testOldWorkingDayIsMarkedStaleAfterPublicationWindow(): void
    {
        $now = new \DateTimeImmutable('2026-07-20 12:00:00', new \DateTimeZone('Europe/Vilnius'));
        $result = (new SourceFreshness())->evaluate('2026-07-17', $now);

        self::assertTrue($result['is_stale']);
        self::assertSame('stale', $result['status']);
    }
}
