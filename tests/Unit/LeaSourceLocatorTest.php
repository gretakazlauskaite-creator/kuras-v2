<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\LeaSourceLocator;
use PHPUnit\Framework\TestCase;

final class LeaSourceLocatorTest extends TestCase
{
    public function testItFindsTheOfficialWorkbookAndSourceDate(): void
    {
        $html = <<<'HTML'
            <a href="https://ltenergagen.sharepoint.com/:x:/s/intra/doc/old">Naujausios degalų kainos (2026-07-16)</a>
            <a href="https://ltenergagen.sharepoint.com/:x:/s/intra/doc/current?e=abc&amp;foo=bar">
                ♦ Naujausios degalų kainos (2026-07-17)
            </a>
            HTML;

        $source = (new LeaSourceLocator())->locate($html);

        self::assertSame('2026-07-17', $source->sourceDate);
        self::assertSame(
            'https://ltenergagen.sharepoint.com/:x:/s/intra/doc/current?e=abc&foo=bar&download=1',
            $source->downloadUrl,
        );
    }

    public function testItRejectsAnUnexpectedDownloadHost(): void
    {
        $html = '<a href="https://attacker.example/prices.xlsx">Naujausios degalų kainos (2026-07-17)</a>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neleistiną adresą');
        (new LeaSourceLocator())->locate($html);
    }

    public function testItFailsClosedWhenTheExpectedLinkDisappears(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nerasta nuoroda');
        (new LeaSourceLocator())->locate('<html><body>LEA puslapis pasikeitė</body></html>');
    }
}
