<?php

namespace App\Controller;

use App\Database;
use App\Repository\StationRepository;
use App\Repository\AlertRepository;
use App\Service\ImportService;
use App\Service\BestPriceService;

class AdminController
{
    private function requireAuth(): void
    {
        session_start();
        if (!empty($_SESSION['admin_authed'])) return;

        // HTTP Basic Auth
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
        $expectedUser = $_ENV['ADMIN_USER'] ?? 'admin';
        $expectedPass = $_ENV['ADMIN_PASS'] ?? 'admin';

        if ($user === $expectedUser && hash_equals(hash('sha256', $expectedPass), hash('sha256', $pass))) {
            $_SESSION['admin_authed'] = true;
            return;
        }

        header('WWW-Authenticate: Basic realm="Kuras Pricer Admin"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Unauthorized';
        exit;
    }

    public function dashboard(): void
    {
        $this->requireAuth();
        $db          = Database::getInstance();
        $stationCount = (int)$db->query('SELECT COUNT(*) FROM stations')->fetchColumn();
        $priceCount  = (int)$db->query('SELECT COUNT(*) FROM prices WHERE price_date = CURDATE()')->fetchColumn();
        $alertCount  = (new AlertRepository())->countActive();
        $lastImport  = $db->query('SELECT MAX(imported_at) FROM prices')->fetchColumn();

        extract(compact('stationCount', 'priceCount', 'alertCount', 'lastImport'));
        require dirname(__DIR__, 2) . '/templates/admin/dashboard.php';
    }

    public function stations(): void
    {
        $this->requireAuth();
        $repo   = new StationRepository();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $result = $repo->findAll([], $page, 50);

        $stations = $result['data'];
        $total    = $result['total'];
        $brands   = $repo->getBrands();

        require dirname(__DIR__, 2) . '/templates/admin/stations.php';
    }

    public function editStation(int $id): void
    {
        $this->requireAuth();
        $repo    = new StationRepository();
        $station = $repo->findById($id);

        if (!$station) {
            http_response_code(404);
            echo 'Station not found';
            return;
        }

        require dirname(__DIR__, 2) . '/templates/admin/station_edit.php';
    }

    public function updateStation(int $id): void
    {
        $this->requireAuth();
        $repo = new StationRepository();

        $data = [
            'has_coffee'   => isset($_POST['has_coffee'])  ? 1 : 0,
            'has_carwash'  => isset($_POST['has_carwash']) ? 1 : 0,
            'has_shop'     => isset($_POST['has_shop'])    ? 1 : 0,
            'has_loyalty'  => isset($_POST['has_loyalty']) ? 1 : 0,
            'profile_text' => strip_tags($_POST['profile_text'] ?? '', '<p><br><strong><em><ul><li><a>'),
            'is_sponsored' => isset($_POST['is_sponsored']) ? 1 : 0,
        ];

        // Handle banner upload
        if (!empty($_FILES['promo_banner']['tmp_name'])) {
            $ext  = strtolower(pathinfo($_FILES['promo_banner']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'banner_' . $id . '_' . time() . '.' . $ext;
                $dest     = dirname(__DIR__, 2) . '/public/uploads/' . $filename;
                if (move_uploaded_file($_FILES['promo_banner']['tmp_name'], $dest)) {
                    $data['promo_banner'] = '/uploads/' . $filename;
                }
            }
        }

        $repo->update($id, $data);
        (new BestPriceService())->invalidateCache();

        header('Location: /admin/stations?updated=1');
    }

    public function triggerImport(): void
    {
        $this->requireAuth();
        $output = shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/import.php') . ' 2>&1');
        header('Content-Type: text/plain');
        echo $output;
    }

    public function ads(): void
    {
        $this->requireAuth();
        $db   = Database::getInstance();
        $ads  = $db->query('SELECT * FROM ads ORDER BY id DESC')->fetchAll();
        require dirname(__DIR__, 2) . '/templates/admin/ads.php';
    }

    public function saveAd(): void
    {
        $this->requireAuth();
        $db   = Database::getInstance();
        $slot = trim($_POST['slot'] ?? '');
        $html = $_POST['html'] ?? '';
        $startsAt = $_POST['starts_at'] ?: null;
        $endsAt   = $_POST['ends_at']   ?: null;

        if (!$slot || !$html) {
            header('Location: /admin/ads?error=missing_fields');
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO ads (slot, html, is_active, starts_at, ends_at) VALUES (:slot, :html, 1, :starts_at, :ends_at)'
        );
        $stmt->execute([':slot' => $slot, ':html' => $html, ':starts_at' => $startsAt, ':ends_at' => $endsAt]);
        header('Location: /admin/ads?saved=1');
    }

    public function deleteAd(int $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $db->prepare('DELETE FROM ads WHERE id = :id')->execute([':id' => $id]);
        header('Location: /admin/ads?deleted=1');
    }
}
