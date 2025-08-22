<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Part;

final class HubController extends BaseController
{
    /**
     * /page/hub?sezione=...
     * Mantengo la compatibilità con l’URL legacy, inoltrando a /hub/{slug}
     */
    public function fromQuery(): void
    {
        $slug = isset($_GET['sezione']) ? (string)$_GET['sezione'] : '';
        $slug = $this->slugify($slug);
        if (!$slug) {
            http_response_code(400);
            echo 'Parametro "sezione" mancante.';
            return;
        }
        $this->show($slug);
    }

    /**
     * /hub/{slug}
     * Mostra la pagina hub della parte con carosello e lista giorni.
     * Sticker “Novità!” per-giorno: se l’utente NON ha ancora “visto”
     * la versione corrente (in base a data_modifica) del giorno.
     */
    public function show(string $slug): void
    {
        $slug = $this->slugify($slug);

        // Carico Parte + Giorni
        $partModel = new Part();
        // Recupero parte e giorni (uso query interne del controller per robustezza)
        $db = \DB::pdo();

        // Parte
        $stPart = $db->prepare("
            SELECT id, slug, titolo, descrizione, etichetta, descrizione_breve
            FROM parti
            WHERE slug = :slug
            LIMIT 1
        ");
        $stPart->execute([':slug' => $slug]);
        $part = $stPart->fetch(\PDO::FETCH_ASSOC);

        if (!$part) {
            http_response_code(404);
            $this->view('errors/404.twig', ['path' => '/hub/' . $slug]);
            return;
        }

        // Giorni della parte
        $stDays = $db->prepare("
            SELECT id, parte_id, giorno_num, data, titolo, immagine_copertina, riassunto, data_modifica
            FROM giorni
            WHERE parte_id = :pid
            ORDER BY giorno_num ASC, id ASC
        ");
        $stDays->execute([':pid' => $part['id']]);
        $giorni = $stDays->fetchAll(\PDO::FETCH_ASSOC);

        // Slides del carosello (una per giorno con cover disponibile)
        $slides = [];
        foreach ($giorni as $g) {
            if (!empty($g['immagine_copertina'])) {
                $slides[] = [
                    'img_url' => $this->normalizeAsset((string)$g['immagine_copertina']),
                ];
            }
        }

        // === Sticker “Novità!” per-giorno (cookie JSON: {"<id_giorno>": <ts_ack>})
        $seen = $this->readSeenDays();
        foreach ($giorni as &$g) {
            $modTs = $this->toTimestamp($g['data_modifica'] ?? null, $g['data'] ?? null);
            $lastAck = (int)($seen[(string)$g['id']] ?? 0);
            $g['is_new'] = ($modTs > 0 && $modTs > $lastAck);
            // Normalizzo anche la cover per uso diretto in Twig se servisse
            if (!empty($g['immagine_copertina'])) {
                $g['immagine_copertina'] = $this->normalizeAsset((string)$g['immagine_copertina']);
            }
        }
        unset($g);

        // Meta
        $base = rtrim($_ENV['APP_URL_BASE'] ?? '/', '/');
        $meta = [
            'title'       => $part['titolo'] ?? 'Sezione',
            'description' => isset($part['descrizione']) ? strip_tags((string)$part['descrizione']) : '',
            'url'         => $base . '/hub/' . $slug,
            'image'       => $slides[0]['img_url'] ?? ($base . '/assets/images/cover-default.jpg'),
        ];

        // Render
        $this->view('hub.twig', [
            'part'   => [
                'id'                => $part['id'],
                'slug'              => $part['slug'],
                'titolo'            => $part['titolo'],
                'descrizione'       => $part['descrizione'],
                'etichetta'         => $part['etichetta'] ?? null,
                'descrizione_breve' => $part['descrizione_breve'] ?? null,
                'giorni'            => $giorni,
            ],
            'slides' => $slides,
            'meta'   => $meta,
        ]);
    }

    /* ===========================================================
       Helpers
       =========================================================== */

    private function slugify(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = str_replace(['  ', '   '], ' ', $s);
        $s = str_replace(' ', '-', $s);
        $s = str_replace(['_'], '-', $s);
        return $s;
    }

    /** Converte data_modifica (o fallback data) in timestamp intero */
    private function toTimestamp(null|string|int $primary, null|string|int $fallback = null): int
    {
        $candidates = [$primary, $fallback];
        foreach ($candidates as $v) {
            if ($v === null || $v === '') continue;
            if (is_numeric($v)) return (int)$v;
            $ts = strtotime((string)$v);
            if ($ts !== false) return $ts;
        }
        return 0;
    }

    /**
     * Normalizza path asset dal dump: sostituisce prefisso "/usa" con base "/viaggiousa"
     * e lascia intatti path già assoluti corretti.
     */
    private function normalizeAsset(string $path): string
    {
        $base = rtrim($_ENV['APP_URL_BASE'] ?? '', '/'); // es. /viaggiousa
        if ($base === '') $base = '';

        // già corretto
        if ($base && str_starts_with($path, $base . '/')) {
            return $path;
        }
        // vecchio prefisso /usa → sostituisco col base
        if (preg_match('#^/usa(/|$)#', $path)) {
            return preg_replace('#^/usa#', $base, $path) ?? $path;
        }
        // altri casi: se è un path assoluto, lo lascio; se è relativo, prefiggo base
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $base . '/' . ltrim($path, '/');
    }

    /** Legge cookie JSON per “giorni visti”: {"12": 1724300000, ...} */
    private function readSeenDays(): array
    {
        $raw = $_COOKIE['vu_seen_days'] ?? '';
        if (!$raw) return [];
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** Scrive cookie JSON “giorni visti” */
    private function writeSeenDays(array $arr): void
    {
        $json = json_encode($arr, JSON_UNESCAPED_SLASHES);
        $path = rtrim(parse_url($_ENV['APP_URL_BASE'] ?? '/', PHP_URL_PATH) ?: '/', '/') . '/';
        setcookie('vu_seen_days', $json, [
            'expires'  => time() + 60 * 60 * 24 * 365, // 1 anno
            'path'     => $path,
            'secure'   => false, // imposta true in prod se in HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
