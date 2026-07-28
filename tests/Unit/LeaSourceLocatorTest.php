<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\LeaSourceLocator;
use PHPUnit\Framework\TestCase;

final class LeaSourceLocatorTest extends TestCase
{
    public function testItFindsTheLatestWorkbookInTheCurrentArchiveFormat(): void
    {
        $html = file_get_contents(dirname(__DIR__) . '/Fixtures/lea_archive_page.html');
        self::assertIsString($html);

        $source = (new LeaSourceLocator())->locate($html, LeaSourceLocator::ARCHIVE_URL);

        self::assertSame(LeaSourceLocator::ARCHIVE_URL, $source->pageUrl);
        self::assertSame('2026-07-27', $source->sourceDate);
        self::assertSame(
            'https://ltenergagen.sharepoint.com/:x:/s/intra/doc/latest?e=latest&source=archive&download=1',
            $source->downloadUrl,
        );
    }

    public function testItKeepsSupportingTheLegacyMainPageFormat(): void
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

    public function testItChoosesTheNewestDateRegardlessOfLinkOrder(): void
    {
        $html = <<<'HTML'
            <a title="Degalų kainos 2026-07-27" href="https://ltenergagen.sharepoint.com/:x:/s/intra/doc/current">Kainos</a>
            <a href="https://ltenergagen.sharepoint.com/:x:/s/intra/doc/old" title="Degalų kainos 2026-07-10">Kainos</a>
            HTML;

        $source = (new LeaSourceLocator())->locate($html, LeaSourceLocator::ARCHIVE_URL);

        self::assertSame('2026-07-27', $source->sourceDate);
        self::assertStringContainsString('/current?download=1', $source->downloadUrl);
    }

    public function testItRejectsAnUnexpectedDownloadHost(): void
    {
        $html = '<a title="Degalų kainos 2026-07-17" href="https://attacker.example/prices.xlsx">Kainos</a>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neleistiną adresą');
        (new LeaSourceLocator())->locate($html, LeaSourceLocator::ARCHIVE_URL);
    }

    public function testItFailsClosedWhenTheExpectedLinkDisappears(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nerasta datuota degalų kainų failo nuoroda');
        (new LeaSourceLocator())->locate(
            '<html><body>LEA puslapis pasikeitė</body></html>',
            LeaSourceLocator::ARCHIVE_URL,
        );
    }
}
