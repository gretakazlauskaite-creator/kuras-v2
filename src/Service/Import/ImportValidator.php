<?php

declare(strict_types=1);

namespace App\Service\Import;

final class ImportValidator
{
    /** @param list<string> $requiredFuelSlugs */
    public function __construct(
        private readonly int $minimumStations = 100,
        private readonly int $minimumPrices = 100,
        private readonly int $maximumSourceAgeDays = 7,
        private readonly array $requiredFuelSlugs = ['pb95', 'diesel'],
        private readonly float $minimumPrice = 0.50,
        private readonly float $maximumPrice = 4.00,
    ) {
    }

    public function validate(
        ParsedImport $parsed,
        string $sourceDate,
        ?string $latestPublishedDate = null,
        ?int $previousPriceCount = null,
        bool $allowBackfill = false,
        ?\DateTimeImmutable $now = null,
    ): ValidationResult {
        $errors = $parsed->issues;
        $warnings = [];
        $now ??= new \DateTimeImmutable('today', new \DateTimeZone('Europe/Vilnius'));
        $sourceDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $sourceDate, new \DateTimeZone('Europe/Vilnius'));

        if ($sourceDay === false || $sourceDay->format('Y-m-d') !== $sourceDate) {
            $errors[] = "Netinkama šaltinio data: {$sourceDate}.";
        } else {
            $today = $now->setTime(0, 0);
            if ($sourceDay > $today) {
                $errors[] = 'Šaltinio data yra ateityje.';
            } else {
                $ageDays = (int) $sourceDay->diff($today)->format('%a');
                if ($ageDays > $this->maximumSourceAgeDays && !$allowBackfill) {
                    $errors[] = "Šaltinio duomenys yra per seni ({$ageDays} d.).";
                } elseif ($ageDays > 3) {
                    $warnings[] = "Šaltinio duomenys yra {$ageDays} dienų senumo.";
                }
            }
        }

        if (!$allowBackfill && $latestPublishedDate !== null && $sourceDate < $latestPublishedDate) {
            $errors[] = "Šaltinio data {$sourceDate} senesnė už paskutinę publikuotą datą {$latestPublishedDate}.";
        }

        if (count($parsed->stations) < $this->minimumStations) {
            $errors[] = sprintf(
                'Per mažai degalinių: %d (reikalaujama bent %d).',
                count($parsed->stations),
                $this->minimumStations,
            );
        }

        if ($parsed->priceCount() < $this->minimumPrices) {
            $errors[] = sprintf(
                'Per mažai kainų: %d (reikalaujama bent %d).',
                $parsed->priceCount(),
                $this->minimumPrices,
            );
        }

        foreach ($this->requiredFuelSlugs as $slug) {
            if (!in_array($slug, $parsed->detectedFuelSlugs, true)) {
                $errors[] = "Nerastas privalomas kuro stulpelis: {$slug}.";
            }
        }

        foreach (['pb98', 'lpg'] as $optionalSlug) {
            if (!in_array($optionalSlug, $parsed->detectedFuelSlugs, true)) {
                $warnings[] = "Nerastas pasirinktinis kuro stulpelis: {$optionalSlug}.";
            }
        }

        $uniquePrices = [];
        foreach ($parsed->stations as $index => $station) {
            $rowNumber = $index + 1;
            if ($station['brand'] === '' || $station['address'] === '') {
                $errors[] = "Duomenų eilutė {$rowNumber}: trūksta įmonės arba adreso.";
            }
            if ($station['city'] === '' && ($station['municipality'] ?? '') === '') {
                $errors[] = "Duomenų eilutė {$rowNumber}: trūksta vietovės ir savivaldybės.";
            }

            $stationKey = $this->key($station['brand']) . '|' . $this->key($station['address']);
            foreach ($station['prices'] as $slug => $price) {
                if ($price < $this->minimumPrice || $price > $this->maximumPrice) {
                    $errors[] = "Duomenų eilutė {$rowNumber}: {$slug} kaina {$price} nepatenka į leistinas ribas.";
                }

                $priceKey = $stationKey . '|' . $slug;
                if (isset($uniquePrices[$priceKey])) {
                    $errors[] = "Pasikartojanti degalinės ir kuro pora: {$station['brand']} / {$station['address']} / {$slug}.";
                }
                $uniquePrices[$priceKey] = true;
            }
        }

        if ($previousPriceCount !== null && $previousPriceCount > 0) {
            $ratio = $parsed->priceCount() / $previousPriceCount;
            if ($ratio < 0.60 || $ratio > 1.60) {
                $errors[] = sprintf(
                    'Kainų skaičius įtartinai pasikeitė: dabar %d, ankstesniame importe %d.',
                    $parsed->priceCount(),
                    $previousPriceCount,
                );
            } elseif ($ratio < 0.80 || $ratio > 1.20) {
                $warnings[] = sprintf('Kainų skaičius pasikeitė %.1f%%.', abs(1 - $ratio) * 100);
            }
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));

        return new ValidationResult($errors, $warnings, [
            'raw_rows' => $parsed->rawRowCount,
            'stations' => count($parsed->stations),
            'prices' => $parsed->priceCount(),
            'fuel_columns' => implode(',', $parsed->detectedFuelSlugs),
        ]);
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/\s+/u', ' ', $value);
    }
}
