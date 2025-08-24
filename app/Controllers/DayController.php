<?php
declare(strict_types=1);

namespace App\Controllers;
use App\Security\Csrf;

final class DayController extends BaseController
{
    /**
     * /giorno/{id}
     * Mostra il giorno con sezioni + prev/next.
     * Segna come “visto” (ack) la versione corrente del giorno nel cookie vu_seen_days.
     */
    public function show(string $id): void
    {
        $dayId = (int)$id;
        $db = \DB::pdo();

        // Dati del giorno + info parte (slug/titolo)
        $st = $db->prepare("
            SELECT g.id, g.parte_id, g.giorno_num, g.data, g.titolo, g.riassunto, g.immagine_copertina, g.data_modifica,
                   p.slug  AS parte_slug,
                   p.titolo AS parte_titolo
            FROM giorni g
            JOIN parti p ON p.id = g.parte_id
            WHERE g.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $dayId]);
        $day = $st->fetch(\PDO::FETCH_ASSOC);
$fbCookie = $this->readFeedbackCookie(); // array tipo [ "s12" => ["like"=>true,...], ... ]
        if (!$day) {
            http_response_code(404);
            $this->view('errors/404.twig', ['path' => '/giorno/' . $dayId]);
            return;
        }

        // Sezioni del giorno + feedback counters
        $stS = $db->prepare("
            SELECT s.id, s.giorno_id, s.ordine, s.sovratitolo, s.titolo, s.testo, s.immagine,
                   COALESCE(f.likes,0)      AS likes,
                   COALESCE(f.dislikes,0)   AS dislikes,
                   COALESCE(f.more_info,0)  AS more_info
            FROM sezioni s
            LEFT JOIN feedback_sezioni f ON f.sezione_id = s.id
            WHERE s.giorno_id = :id
            ORDER BY s.ordine ASC, s.id ASC
        ");
        $stS->execute([':id' => $dayId]);
        $sections = $stS->fetchAll(\PDO::FETCH_ASSOC);

        // Prev/Next su TUTTI i giorni (continuità globale)
$stPrev = $db->prepare("
    SELECT id FROM giorni
    WHERE (giorno_num < :gn_lt)
       OR (giorno_num = :gn_eq AND id < :cur_id)
    ORDER BY giorno_num DESC, id DESC
    LIMIT 1
");
$stNext = $db->prepare("
    SELECT id FROM giorni
    WHERE (giorno_num > :gn_gt)
       OR (giorno_num = :gn_eq AND id > :cur_id)
    ORDER BY giorno_num ASC, id ASC
    LIMIT 1
");
$paramsPrev = [
    'gn_lt'  => $day['giorno_num'],
    'gn_eq'  => $day['giorno_num'],
    'cur_id' => $day['id'],
];
$paramsNext = [
    'gn_gt'  => $day['giorno_num'],
    'gn_eq'  => $day['giorno_num'],
    'cur_id' => $day['id'],
];
$stPrev->execute($paramsPrev);
$stNext->execute($paramsNext);
$prev = $stPrev->fetchColumn() ?: null;
$next = $stNext->fetchColumn() ?: null;

        // Normalizza cover e immagini sezioni (gestione path legacy /usa → /viaggiousa)
        $day['immagine_copertina'] = !empty($day['immagine_copertina'])
            ? $this->normalizeAsset((string)$day['immagine_copertina'])
            : null;
        foreach ($sections as &$s) {
            if (!empty($s['immagine'])) {
                $s['immagine'] = $this->normalizeAsset((string)$s['immagine']);
            }
        }
        unset($s);

        // Indice "Tutti i giorni" raggruppato per parte
        $indexStmt = $db->query("
            SELECT p.id AS parte_id, p.titolo AS parte_titolo, p.slug AS parte_slug,
                   g.id, g.giorno_num, g.titolo
            FROM parti p
            JOIN giorni g ON g.parte_id = p.id
            ORDER BY p.id ASC, g.giorno_num ASC, g.id ASC
        ");
        $rows = $indexStmt->fetchAll(\PDO::FETCH_ASSOC);
        $indice = [];
        foreach ($rows as $r) {
            $pid = (string)$r['parte_id'];
            if (!isset($indice[$pid])) {
                $indice[$pid] = [
                    'parte_id'     => $r['parte_id'],
                    'parte_titolo' => $r['parte_titolo'],
                    'parte_slug'   => $r['parte_slug'],
                    'giorni'       => [],
                ];
            }
            $indice[$pid]['giorni'][] = [
                'id'         => $r['id'],
                'giorno_num' => $r['giorno_num'],
                'titolo'     => $r['titolo'],
            ];
        }
        $indice = array_values($indice);

        // === ACK “visto” per-giorno (per sticker Novità!)
        $modTs = $this->toTimestamp($day['data_modifica'] ?? null, $day['data'] ?? null);
        $seen = $this->readSeenDays();
        if ($modTs > 0) {
            $seen[(string)$dayId] = $modTs;   // visto almeno fino a questa versione
        } else {
            $seen[(string)$dayId] = time();   // fallback
        }
        $this->writeSeenDays($seen);

        // Meta
        $base = rtrim($_ENV['APP_URL_BASE'] ?? '/', '/');
        $meta = [
            'title'       => 'Giorno ' . $day['giorno_num'] . ' — ' . $day['titolo'],
            'description' => isset($day['riassunto']) ? strip_tags((string)$day['riassunto']) : '',
            'url'         => $base . '/giorno/' . $dayId,
            'image'       => $day['immagine_copertina'] ?? ($base . '/assets/images/cover-default.jpg'),
        ];
$csrf = Csrf::token();
        $this->view('day.twig', [
            'day'      => $day,
            'sections' => $sections,
            'prev'     => $prev ?: null,
            'next'     => $next ?: null,
            'meta'     => $meta,
            'indice'   => $indice,
            'csrf'     => $csrf,
        ]);
    }

    /* ===========================================================
       Helpers (identici a HubController per autonomia)
       =========================================================== */

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
     * Normalizza path asset: sostituisce prefisso "/usa" con base "/viaggiousa"
     * e lascia intatti path già corretti.
     */
    private function normalizeAsset(string $path): string
    {
        $base = rtrim($_ENV['APP_URL_BASE'] ?? '', '/'); // es. /viaggiousa
        if ($base === '') $base = '';

        if ($base && str_starts_with($path, $base . '/')) {
            return $path;
        }
        if (preg_match('#^/usa(/|$)#', $path)) {
            return preg_replace('#^/usa#', $base, $path) ?? $path;
        }
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
    private function readFeedbackCookie(): array {
    $cookie = $_COOKIE['vu_fb'] ?? '';
    if (!$cookie) return [];
    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2) return [];
    [$payload, $sig] = $parts;
    $key = $_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? 'temp-key-change-me';
    $calc = hash_hmac('sha256', $payload, $key);
    if (!hash_equals($calc, $sig)) return [];
    $data = json_decode(base64_decode($payload) ?: '[]', true);
    return is_array($data) ? $data : [];
}

}
