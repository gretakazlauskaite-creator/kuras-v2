<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\StationIdentity;
use PHPUnit\Framework\TestCase;

final class StationIdentityTest extends TestCase
{
    public function testIdentityIsStableAcrossWhitespaceAndCaseDifferences(): void
    {
        $identity = new StationIdentity();

        $first = $identity->fromSource('Circle K', 'Vilnius,  Ukmergės g.  10');
        $second = $identity->fromSource(' circle k ', "Vilnius,\u{00A0}Ukmergės g. 10 ");

        self::assertSame($first['source_key'], $second['source_key']);
        self::assertSame($first['public_id'], $second['public_id']);
        self::assertMatchesRegularExpression('/^st_[a-f0-9]{20}$/', $first['public_id']);
    }

    public function testDifferentAddressesProduceDifferentIdentities(): void
    {
        $identity = new StationIdentity();

        self::assertNotSame(
            $identity->fromSource('Neste', 'Vilnius, A g. 1')['source_key'],
            $identity->fromSource('Neste', 'Vilnius, A g. 2')['source_key'],
        );
    }
}
