<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\LeaWorkbookParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class LeaWorkbookParserTest extends TestCase
{
    public function testItAggregatesTheOfficialLongFormatWithoutMixingFuelTypes(): void
    {
        $parsed = (new LeaWorkbookParser())->parse(
            dirname(__DIR__) . '/Fixtures/lea_prices_valid.xlsx',
        );

        self::assertSame(12, $parsed->rawRowCount);
        self::assertSame(['pb95', 'diesel', 'lpg'], $parsed->detectedFuelSlugs);
        self::assertCount(4, $parsed->stations);
        self::assertSame([], $parsed->issues);
        self::assertSame(['2026-07-17'], $parsed->sourceDates);

        $first = $parsed->stations[0];
        self::assertSame('Circle K', $first['brand']);
        self::assertSame('Vilnius', $first['city']);
        self::assertSame('Vilniaus m. sav.', $first['municipality']);
        self::assertSame(1.499, $first['prices']['pb95']);
        self::assertSame(1.399, $first['prices']['diesel']);
        self::assertSame(0.699, $first['prices']['lpg']);
    }

    public function testItDeduplicatesIdenticalRowsAndIgnoresAFooterNote(): void
    {
        $file = $this->workbookWithExtraRows([
            ['Circle K', 'Vilniaus m. sav.', 'Vilnius, Testų g. 1', 'Dyzelinas', 1.399, 46220],
            ['Pastaba: oficialaus failo pabaiga', null, null, null, null, null],
        ]);

        try {
            $parsed = (new LeaWorkbookParser())->parse($file);
        } finally {
            unlink($file);
        }

        self::assertSame(13, $parsed->rawRowCount);
        self::assertSame([], $parsed->issues);
        self::assertCount(4, $parsed->stations);
        self::assertSame(12, $parsed->priceCount());
    }

    public function testItRejectsAConflictingDuplicatePrice(): void
    {
        $file = $this->workbookWithExtraRows([
            ['Circle K', 'Vilniaus m. sav.', 'Vilnius, Testų g. 1', 'Dyzelinas', 1.999, 46220],
        ]);

        try {
            $parsed = (new LeaWorkbookParser())->parse($file);
        } finally {
            unlink($file);
        }

        self::assertCount(1, $parsed->issues);
        self::assertStringContainsString('nesutampa', $parsed->issues[0]);
        self::assertStringContainsString('1.399 ir 1.999', $parsed->issues[0]);
    }

    /** @param list<list<mixed>> $rows */
    private function workbookWithExtraRows(array $rows): string
    {
        $spreadsheet = IOFactory::load(dirname(__DIR__) . '/Fixtures/lea_prices_valid.xlsx');
        $sheet = $spreadsheet->getActiveSheet();
        $rowNumber = $sheet->getHighestRow() + 1;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $rowNumber);
            ++$rowNumber;
        }

        $file = tempnam(sys_get_temp_dir(), 'lea-parser-test-');
        if ($file === false) {
            self::fail('Nepavyko sukurti laikino testo failo.');
        }
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();

        return $file;
    }
}
