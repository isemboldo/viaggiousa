<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

use App\Router\Router;
use App\Controllers\HomeController;
use App\Controllers\DayController;
use App\Controllers\SectionController;
use App\Controllers\HubController;

$router = new Router();

/**
 * Rotte pubbliche
 */
$router->get('/', [HomeController::class, 'index']);
$router->get('/giorno/{id}', [DayController::class, 'show']);
$router->get('/sezione/{id}', [SectionController::class, 'show']);

/**
 * ✅ Hub (unico endpoint “pulito”)
 */
$router->get('/hub/{slug}', [HubController::class, 'show']);

/**
 * 🔁 Redirect di compatibilità dal vecchio URL:
 *     /page/hub?sezione=slug  →  /hub/slug
 * Evita il doppio render e mantiene attivi segnalibri/link storici.
 */
$router->get('/page/hub', function (): void {
    $slug = $_GET['sezione'] ?? '';
    $base = rtrim($_ENV['APP_URL_BASE'] ?? '/', '/');
    header('Location: ' . $base . '/hub/' . rawurlencode((string)$slug), true, 302);
    exit;
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
