<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

use App\Router\Router;
use App\Controllers\HomeController;
use App\Controllers\DayController;
use App\Controllers\SectionController;
use App\Controllers\HubController;
use App\Controllers\FeedbackController;

$router = new Router();

/**
 * Rotte pubbliche
 */
$router->get('/', [HomeController::class, 'index']);
$router->get('/giorno/{id}', [DayController::class, 'show']);
$router->get('/sezione/{id}', [SectionController::class, 'show']);
$router->post('/api/section/{id}/feedback', [\App\Controllers\FeedbackController::class, 'react']);
$router->get('/rendiconto', [\App\Controllers\RendicontoController::class, 'index']);
$router->get('/rendiconto', [\App\Controllers\RendicontoController::class, 'index']);
$router->get('/rendiconto/categoria/{slug}', [\App\Controllers\RendicontoController::class, 'categoria']);
$router->get('/rendiconto/partecipante/{slug}', [\App\Controllers\RendicontoController::class, 'partecipante']);
$router->get('/rendiconto.json', [\App\Controllers\RendicontoController::class, 'exportJson']);
$router->get('/rendiconto.csv',  [\App\Controllers\RendicontoController::class, 'exportCsv']);
$router->get('/rendiconto/categoria/{slug}.csv', [\App\Controllers\RendicontoController::class, 'exportCategoriaCsv']);
$router->get('/rendiconto/partecipante/{slug}.csv', [\App\Controllers\RendicontoController::class, 'exportPartecipanteCsv']);

// Iscrizioni
$router->get('/iscrizione', [\App\Controllers\SubscriptionController::class, 'form']);
$router->post('/iscrizione', [\App\Controllers\SubscriptionController::class, 'subscribe']);
$router->get('/iscrizione/conferma/{token}', [\App\Controllers\SubscriptionController::class, 'confirm']);
$router->get('/iscrizione/unsubscribe/{token}', [\App\Controllers\SubscriptionController::class, 'unsubscribe']);

// Notifiche (cron)
$router->get('/notify/digest', [\App\Controllers\NotifyController::class, 'digest']);



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
