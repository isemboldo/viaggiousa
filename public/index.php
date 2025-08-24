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
// opzionali (fase successiva):
$router->get('/rendiconto/categoria/{name}', [\App\Controllers\RendicontoController::class, 'categoria']);
$router->get('/rendiconto/partecipante/{name}', [\App\Controllers\RendicontoController::class, 'partecipante']);

$router->get('/rendiconto/_debug', function(){
    header('Content-Type: application/json; charset=utf-8');
    $exp = new \App\Models\Expense(\DB::pdo(), new \App\Models\Fx(\DB::pdo()));
    $pay = new \App\Models\Payment(\DB::pdo(), new \App\Models\Fx(\DB::pdo()));
    echo json_encode([
        'sample_expenses' => array_slice($exp->all(), 0, 5),
        'payments'        => $pay->all(),
        'fx_known'        => (new \App\Models\Fx(\DB::pdo()))->knownCodes(),
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
});


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
