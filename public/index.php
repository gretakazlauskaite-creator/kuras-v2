<?php

declare(strict_types=1);

// Global error capture — writes to /tmp/kuras_error.log so we can diagnose
// 500s even when PHP-FPM log is misconfigured or inaccessible.
set_exception_handler(function (\Throwable $e): void {
    $msg = date('[Y-m-d H:i:s]') . ' ' . get_class($e) . ': ' . $e->getMessage()
         . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n"
         . $e->getTraceAsString() . "\n\n";
    @file_put_contents('/tmp/kuras_error.log', $msg, FILE_APPEND | LOCK_EX);
    if (!headers_sent()) { http_response_code(500); }
    echo '<!doctype html><html><body><h1>500</h1><p>An error occurred. Please try again later.</p></body></html>';
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!($errno & error_reporting())) return false;
    $msg = date('[Y-m-d H:i:s]') . " PHP Error[$errno]: $errstr in $errfile:$errline\n";
    @file_put_contents('/tmp/kuras_error.log', $msg, FILE_APPEND | LOCK_EX);
    return false; // let PHP continue with default handling too
});

require_once dirname(__DIR__) . '/src/Bootstrap.php';

use App\Router;
use App\Controller\HomeController;
use App\Controller\MapController;
use App\Controller\AlertController;
use App\Controller\AdminController;
use App\Controller\Api\StationsApiController;
use App\Controller\Api\PricesApiController;
use App\Controller\Api\AlertsApiController;

$router = new Router();

// ── Public pages ────────────────────────────────────────────
$router->get('/',               fn() => (new HomeController())->index());
$router->get('/stations',       fn() => (new HomeController())->stations());
$router->get('/map',            fn() => (new MapController())->index());
$router->get('/rankings',       fn() => (new HomeController())->rankings());
$router->get('/station/{id}',   fn($p) => (new HomeController())->stationProfile((int)$p['id']));

// ── Alerts ───────────────────────────────────────────────────
$router->get('/alerts/unsubscribe', fn() => (new AlertController())->unsubscribe());

// ── JSON API ─────────────────────────────────────────────────
$router->get('/api/stations',   fn() => (new StationsApiController())->index());
$router->get('/api/prices',     fn() => (new PricesApiController())->index());
$router->post('/api/alerts',    fn() => (new AlertsApiController())->create());

// ── Admin ─────────────────────────────────────────────────────
$router->get('/admin',                  fn() => (new AdminController())->dashboard());
$router->get('/admin/stations',         fn() => (new AdminController())->stations());
$router->get('/admin/station/{id}',     fn($p) => (new AdminController())->editStation((int)$p['id']));
$router->post('/admin/station/{id}',    fn($p) => (new AdminController())->updateStation((int)$p['id']));
$router->post('/admin/import',          fn() => (new AdminController())->triggerImport());
$router->get('/admin/ads',              fn() => (new AdminController())->ads());
$router->post('/admin/ads',             fn() => (new AdminController())->saveAd());
$router->post('/admin/ads/{id}/delete', fn($p) => (new AdminController())->deleteAd((int)$p['id']));

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'];

$router->dispatch($method, $uri);
