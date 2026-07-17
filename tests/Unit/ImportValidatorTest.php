<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Import\ImportValidator;
use App\Service\Import\ParsedImport;
use PHPUnit\Framework\TestCase;

final class ImportValidatorTest extends TestCase
{
    public function testItAcceptsACompleteCurrentBatch(): void
    {
        $result = $this->validator()->validate(
            parsed: $this->validImport(),
            sourceDate: '2026-07-17',
            latestPublishedDate: '2026-07-16',
            previousPriceCount: 4,
            now: new \DateTimeImmutable('2026-07-17', new \DateTimeZone('Europe/Vilnius')),
        );

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors);
    }

    public function testItRejectsAStaleSourceUnlessBackfillIsExplicit(): void
    {
        $result = $this->validator()->validate(
            parsed: $this->validImport('2026-07-01'),
            sourceDate: '2026-07-01',
            latestPublishedDate: '2026-07-16',
            now: new \DateTimeImmutable('2026-07-17', new \DateTimeZone('Europe/Vilnius')),
        );

        self::assertFalse($result->isValid());
        self::assertStringContainsString('per seni', implode(' ', $result->errors));
        self::assertStringContainsString('senesnė', implode(' ', $result->errors));
    }

    public function testItRejectsDuplicateStationFuelPairsAndSuspiciousPrices(): void
    {
        $station = $this->validImport()->stations[0];
        $station['prices']['pb95'] = 9.999;
        $parsed = new ParsedImport([$station, $station], ['pb95', 'pb98', 'diesel', 'lpg'], 2);

        $result = $this->validator()->validate(
            parsed: $parsed,
            sourceDate: '2026-07-17',
            now: new \DateTimeImmutable('2026-07-17', new \DateTimeZone('Europe/Vilnius')),
        );

        self::assertFalse($result->isValid());
        $errors = implode(' ', $result->errors);
        self::assertStringContainsString('nepatenka į leistinas ribas', $errors);
        self::assertStringContainsString('Pasikartojanti', $errors);
    }

    public function testItRejectsASevereRowCountDrop(): void
    {
        $result = $this->validator()->validate(
            parsed: $this->validImport(),
            sourceDate: '2026-07-17',
            previousPriceCount: 100,
            now: new \DateTimeImmutable('2026-07-17', new \DateTimeZone('Europe/Vilnius')),
        );

        self::assertFalse($result->isValid());
        self::assertStringContainsString('įtartinai pasikeitė', implode(' ', $result->errors));
    }

    public function testItRejectsAWorkbookDateThatDisagreesWithTheLeaPage(): void
    {
        $result = $this->validator()->validate(
            parsed: $this->validImport('2026-07-16'),
            sourceDate: '2026-07-17',
            now: new \DateTimeImmutable('2026-07-17', new \DateTimeZone('Europe/Vilnius')),
        );

        self::assertFalse($result->isValid());
        self::assertStringContainsString('nesutampa', implode(' ', $result->errors));
    }

    private function validator(): ImportValidator
    {
        return new ImportValidator(minimumStations: 1, minimumPrices: 1);
    }

    private function validImport(string $sourceDate = '2026-07-17'): ParsedImport
    {
        return new ParsedImport([
            [
                'brand' => 'Testas',
                'address' => 'Vilnius, Testų g. 1',
                'city' => 'Vilnius',
                'municipality' => 'Vilniaus m. sav.',
                'prices' => ['pb95' => 1.499, 'pb98' => 1.599, 'diesel' => 1.399, 'lpg' => 0.699],
            ],
        ], ['pb95', 'pb98', 'diesel', 'lpg'], 1, [], [$sourceDate]);
    }
}
