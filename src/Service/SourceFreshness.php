<?php

declare(strict_types=1);

namespace App\Service;

final class SourceFreshness
{
    /** @return array{status:string,is_stale:bool,expected_source_date:string,age_days:int} */
    public function evaluate(string $sourceDate, ?\DateTimeImmutable $now = null): array
    {
        $timezone = new \DateTimeZone('Europe/Vilnius');
        $now ??= new \DateTimeImmutable('now', $timezone);
        $now = $now->setTimezone($timezone);
        $expected = $this->expectedSourceDate($now);
        $source = \DateTimeImmutable::createFromFormat('!Y-m-d', $sourceDate, $timezone);

        if ($source === false || $source->format('Y-m-d') !== $sourceDate) {
            throw new \InvalidArgumentException('Neteisinga šaltinio data.');
        }

        $isStale = $source < $expected;

        return [
            'status' => $isStale ? 'stale' : 'current',
            'is_stale' => $isStale,
            'expected_source_date' => $expected->format('Y-m-d'),
            'age_days' => (int) $source->diff($now->setTime(0, 0))->format('%r%a'),
        ];
    }

    public function expectedSourceDate(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $expected = $now->setTime(0, 0);

        // Before the normal working-day publication window, yesterday is the
        // newest date that can reasonably be required.
        if ((int) $expected->format('N') <= 5 && (int) $now->format('Hi') < 1100) {
            $expected = $expected->modify('-1 day');
        }

        while ((int) $expected->format('N') > 5) {
            $expected = $expected->modify('-1 day');
        }

        return $expected;
    }
}
