<?php
declare(strict_types=1);

use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', APP_PATH . '/Views');
define('PUBLIC_PATH', BASE_PATH . '/public');

require BASE_PATH . '/vendor/autoload.php';

/**
 * Environment
 */
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Zurich');

const PDO_OPTIONS = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Helper per normalizzare path asset dal DB anche lato PHP (controller)
if (!function_exists('asset_url')) {
    function asset_url(?string $path): string {
        $base = $_ENV['APP_URL_BASE'] ?? '/';
        if (!$path) return '';
        if (preg_match('#^https?://#i', $path)) return $path; // già assoluto
        $path = str_replace('\\','/', trim($path));
        if (str_starts_with($path, '/usa/')) {
            $path = substr($path, 5); // rimuove "/usa/"
        }
        $path = ltrim($path, '/'); // niente slash iniziale
        return rtrim($base, '/') . '/' . $path; // es. /viaggiousa/assets/...
    }
}


/**
 * Database singleton (PDO)
 */
final class DB {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = (int)($_ENV['DB_PORT'] ?? 3306);
        $dbname = $_ENV['DB_NAME'] ?? '';
        $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
        $collation = $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, PDO_OPTIONS);

        // Assicura collation a livello sessione
        $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
        $pdo->exec("SET time_zone = '" . date('P') . "'");

        self::$pdo = $pdo;
        return self::$pdo;
    }
}

/**
 * Twig factory
 */
final class View {
    private static ?Twig\Environment $twig = null;

    public static function env(): Twig\Environment {
        if (self::$twig instanceof Twig\Environment) {
            return self::$twig;
        }
        $loader = new Twig\Loader\FilesystemLoader(VIEW_PATH);
        $useCache = filter_var($_ENV['TWIG_CACHE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $cacheDir = $_ENV['TWIG_CACHE_PATH'] ?? BASE_PATH . '/storage/twig-cache';

        $twig = new Twig\Environment($loader, [
            'cache' => $useCache ? $cacheDir : false,
            'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            'autoescape' => 'html',
        ]);

        // Variabili globali utility
        // funzione Twig: normalizza i path delle immagini/asset presi dal DB
$twig->addFunction(new \Twig\TwigFunction('asset_url', function (?string $path): string {
    $base = $_ENV['APP_URL_BASE'] ?? '/';
    if (!$path) return '';

    // 1) URL assoluti: lasciali stare
    if (preg_match('#^https?://#i', $path)) return $path;

    // normalizza
    $path = str_replace('\\', '/', trim($path));

    // 2) se è già prefissato con la base (con o senza slash iniziale), non toccarlo
    $baseTrim = rtrim($base, '/');                // es. '/viaggiousa'
    if (strpos($path, $baseTrim . '/') === 0 ||   // ' /viaggiousa/...'
        strpos($path, ltrim($baseTrim, '/') . '/') === 0 // ' viaggiousa/...'
    ) {
        return $path[0] === '/' ? $path : '/' . $path;
    }

    // 3) rimuovi il vecchio prefisso /usa/ se presente
    if (strpos($path, '/usa/') === 0) {
        $path = substr($path, 5);
    }

    // 4) togli eventuale slash iniziale e prefissa correttamente
    $path = ltrim($path, '/');
    return rtrim($base, '/') . '/' . $path;
}));




        $twig->addFilter(new \Twig\TwigFilter('ita_date', function (?string $val): string {
    if (!$val) return '';
    try {
        $dt = new \DateTime($val);
        return $dt->format('d.m.Y');
    } catch (\Throwable $e) {
        return (string)$val;
    }
}));

        $twig->addGlobal('app_env', $_ENV['APP_ENV'] ?? 'local');
        $twig->addGlobal('base_url', $_ENV['APP_URL_BASE'] ?? '/');

        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $twig->addExtension(new Twig\Extension\DebugExtension());
        }

        self::$twig = $twig;
        return self::$twig;
    }
}
