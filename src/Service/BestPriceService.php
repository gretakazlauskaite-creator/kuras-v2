<?php

namespace App\Service;

use App\Cache;
use App\Repository\PriceRepository;
use App\Repository\StationRepository;

class BestPriceService
{
    private PriceRepository $priceRepo;
    private StationRepository $stationRepo;

    /** Memoised within a single request so we hit the cache layer only once. */
    private ?string $latestPriceDate = null;

    public function __construct()
    {
        $this->priceRepo   = new PriceRepository();
        $this->stationRepo = new StationRepository();
    }

    public function getLatestPriceDate(): string
    {
        if ($this->latestPriceDate !== null) {
            return $this->latestPriceDate;
        }

        $hit    = false;
        $cached = Cache::get('latest_price_date', $hit);
        if ($hit) {
            return $this->latestPriceDate = $cached;
        }

        $date = $this->priceRepo->getLatestPriceDate();
        Cache::set('latest_price_date', $date, 3600);
        return $this->latestPriceDate = $date;
    }

    public function getBestPriceByCities(int $fuelTypeId): array
    {
        $date     = $this->getLatestPriceDate();
        $cacheKey = "best_prices_cities_{$fuelTypeId}_{$date}";

        $hit    = false;
        $cached = Cache::get($cacheKey, $hit);
        if ($hit) return $cached;

        $data = $this->priceRepo->getBestPriceByCities($fuelTypeId, $date);
        Cache::set($cacheKey, $data, 3600);
        return $data;
    }

    public function getBestStation(int $fuelTypeId, string $period = 'day'): ?array
    {
        $date     = $this->getLatestPriceDate();
        $cacheKey = "best_station_{$fuelTypeId}_{$period}_{$date}";

        $hit    = false;
        $cached = Cache::get($cacheKey, $hit);
        if ($hit) return $cached;

        $data = $this->priceRepo->getBestStation($fuelTypeId, $period, $date);
        if ($data !== null) {
            Cache::set($cacheKey, $data, 3600);
        }
        return $data;
    }

    public function invalidateCache(): void
    {
        Cache::clear();
    }
}
