<?php

namespace App\Controller;

use App\Repository\PriceRepository;
use App\Repository\StationRepository;
use App\Service\BestPriceService;

class HomeController
{
    private PriceRepository $priceRepo;
    private StationRepository $stationRepo;
    private BestPriceService $bestPriceService;

    public function __construct()
    {
        $this->priceRepo        = new PriceRepository();
        $this->stationRepo      = new StationRepository();
        $this->bestPriceService = new BestPriceService();
    }

    public function index(): void
    {
        $fuelTypes   = $this->priceRepo->getFuelTypes();
        $activeFuel  = $fuelTypes[0] ?? ['id' => 1, 'slug' => 'pb95', 'name' => 'Pb 95'];
        $fuelSlug    = $_GET['fuel'] ?? $activeFuel['slug'];

        // Find requested fuel
        foreach ($fuelTypes as $ft) {
            if ($ft['slug'] === $fuelSlug) {
                $activeFuel = $ft;
                break;
            }
        }

        $bestByCity  = $this->bestPriceService->getBestPriceByCities((int)$activeFuel['id']);
        $bestDay     = $this->bestPriceService->getBestStation((int)$activeFuel['id'], 'day');
        $bestWeek    = $this->bestPriceService->getBestStation((int)$activeFuel['id'], 'week');
        $bestMonth   = $this->bestPriceService->getBestStation((int)$activeFuel['id'], 'month');
        $priceDate   = $this->bestPriceService->getLatestPriceDate();
        $isStale     = $priceDate !== date('Y-m-d')
                       && ((int)date('N') >= 6 || $this->daysBehind($priceDate) > 1);
        $ads         = $this->getActiveAds(['header', 'sidebar', 'realestate']);

        $pageTitle   = 'Geriausiausios kuro kainos Lietuvoje — Kuras Pricer';
        $pageDesc    = 'Palyginkite kuro kainas Lietuvos degalinėse. Raskite pigiausią kurą šiandien.';

        extract(compact(
            'fuelTypes', 'activeFuel', 'bestByCity',
            'bestDay', 'bestWeek', 'bestMonth', 'priceDate', 'isStale', 'ads',
            'pageTitle', 'pageDesc'
        ));

        require dirname(__DIR__, 2) . '/templates/home.php';
    }

    public function stations(): void
    {
        $fuelTypes     = $this->priceRepo->getFuelTypes();
        $municipalities = $this->stationRepo->getMunicipalities();
        $brands        = $this->stationRepo->getBrands();

        $pageTitle = 'Degalinės Lietuvoje — Kuras Pricer';
        $pageDesc  = 'Visos Lietuvos degalinės su kainomis ir filtrais pagal miestą, rajoną, tinklą.';

        extract(compact('fuelTypes', 'municipalities', 'brands', 'pageTitle', 'pageDesc'));
        require dirname(__DIR__, 2) . '/templates/stations.php';
    }

    public function stationProfile(int $id): void
    {
        $station = $this->stationRepo->findById($id);
        if (!$station) {
            http_response_code(404);
            require dirname(__DIR__, 2) . '/templates/404.php';
            return;
        }

        $fuelSlug     = $_GET['fuel'] ?? 'pb95';
        $priceDate = $this->priceRepo->getLatestPriceDate();
        $isStale   = $priceDate !== date('Y-m-d')
                     && ((int)date('N') >= 6 || $this->daysBehind($priceDate) > 1);
        $todayPrices  = $this->priceRepo->getTodayPrices($id, $priceDate);
        $priceHistory = $this->priceRepo->getPriceHistory($id, 30);
        $nearby       = ($station['lat'] && $station['lng'])
            ? $this->stationRepo->findNearbyAllFuels((float)$station['lat'], (float)$station['lng'], $id, 5, 5, $priceDate)
            : [];

        $pageTitle = htmlspecialchars($station['brand_name'] . ' — ' . $station['address']) . ' — Kuras Pricer';
        $pageDesc  = 'Kainų istorija ir paslaugos: ' . htmlspecialchars($station['name']);

        extract(compact('station', 'priceDate', 'isStale', 'todayPrices', 'priceHistory', 'nearby', 'pageTitle', 'pageDesc'));
        require dirname(__DIR__, 2) . '/templates/station.php';
    }

    public function rankings(): void
    {
        $fuelTypes  = $this->priceRepo->getFuelTypes();
        $fuelSlug   = $_GET['fuel'] ?? 'pb95';
        $activeFuel = $fuelTypes[0] ?? ['id' => 1, 'slug' => 'pb95', 'name' => 'Pb 95'];

        foreach ($fuelTypes as $ft) {
            if ($ft['slug'] === $fuelSlug) { $activeFuel = $ft; break; }
        }

        $bestDay   = $this->bestPriceService->getBestStation((int)$activeFuel['id'], 'day');
        $bestWeek  = $this->bestPriceService->getBestStation((int)$activeFuel['id'], 'week');
        $bestMonth = $this->bestPriceService->getBestStation((int)$activeFuel['id'], 'month');
        $priceDate = $this->bestPriceService->getLatestPriceDate();
        $isStale   = $priceDate !== date('Y-m-d')
                     && ((int)date('N') >= 6 || $this->daysBehind($priceDate) > 1);

        $pageTitle = 'Geriausios degalinės — Kuras Pricer';
        $pageDesc  = 'Degalinė, turėjusi pigiausias kainas šiandien, savaitę ir mėnesį.';

        extract(compact('fuelTypes', 'activeFuel', 'bestDay', 'bestWeek', 'bestMonth', 'priceDate', 'isStale', 'pageTitle', 'pageDesc'));
        require dirname(__DIR__, 2) . '/templates/rankings.php';
    }

    private function daysBehind(string $date): int
    {
        return (int)(new \DateTime('today'))->diff(new \DateTime($date))->days;
    }

    private function getActiveAds(array $slots): array
    {
        $db       = \App\Database::getInstance();
        $ph       = implode(',', array_fill(0, count($slots), '?'));
        $stmt     = $db->prepare(
            "SELECT slot, html FROM ads
             WHERE is_active = 1 AND slot IN ($ph)
               AND (starts_at IS NULL OR starts_at <= CURDATE())
               AND (ends_at   IS NULL OR ends_at   >= CURDATE())"
        );
        $stmt->execute($slots);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['slot']] = $row['html'];
        }
        return $result;
    }
}
