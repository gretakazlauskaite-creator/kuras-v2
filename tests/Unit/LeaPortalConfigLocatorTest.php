<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\LeaPortalConfigLocator;
use PHPUnit\Framework\TestCase;

final class LeaPortalConfigLocatorTest extends TestCase
{
    public function testItFindsThePublicApiConfigInLazyLoadedPortalAssets(): void
    {
        $assets = [
            'https://degalukainos.ena.lt/assets/index-test.js' =>
                'const chunks=["./FuelPriceSiteApp-test.js"];',
            'https://degalukainos.ena.lt/assets/FuelPriceSiteApp-test.js' =>
                'const config={apiBase:"https://api-degalukainos.ena.lt/api/v1",token:"1|public-read-token"};',
        ];

        $config = (new LeaPortalConfigLocator())->locate(
            '<script src="/assets/index-test.js"></script>',
            static fn (string $url, ?string $referer = null): string =>
                $assets[$url] ?? throw new \RuntimeException('Unexpected asset.'),
        );

        self::assertSame('https://api-degalukainos.ena.lt/api/v1', $config->apiBase);
        self::assertSame('1|public-read-token', $config->token);
    }

    public function testItRejectsAnApiHostOutsideLea(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neleistiną API adresą');

        (new LeaPortalConfigLocator())->locate(
            '<script src="/assets/index-test.js"></script>',
            static fn (string $url, ?string $referer = null): string =>
                'const config={apiBase:"https://example.com/api/v1",token:"do-not-use"};',
        );
    }
}
