<?php
declare(strict_types=1);

namespace App\Models;

final class Part extends BaseModel
{
    /** Parti con i relativi giorni (ordinati) */
    public function listWithDays(): array
    {
        $parts = $this->db->query("
            SELECT p.id, p.slug, p.titolo, p.descrizione
            FROM parti p
            ORDER BY p.id ASC
        ")->fetchAll();

        $stDays = $this->db->prepare("
            SELECT id, parte_id, giorno_num, data, titolo, immagine_copertina, riassunto
            FROM giorni
            WHERE parte_id = :pid
            ORDER BY giorno_num ASC, id ASC
        ");

        foreach ($parts as &$p) {
            $stDays->execute([':pid' => $p['id']]);
            $p['giorni'] = $stDays->fetchAll();
        }
        unset($p);

        return $parts;
    }

    /** Statistiche sintetiche per l’intro */
    public function stats(): array
    {
        $days = (int)$this->db->query("SELECT COUNT(*) FROM giorni")->fetchColumn();
        $secs = (int)$this->db->query("SELECT COUNT(*) FROM sezioni")->fetchColumn();
        return ['days' => $days, 'sections' => $secs];
    }
    public function listWithFirstDay(): array
{
    $sql = "
        SELECT p.id, p.slug, p.titolo, p.descrizione,
               (SELECT g.id FROM giorni g WHERE g.parte_id = p.id ORDER BY g.giorno_num ASC, g.id ASC LIMIT 1) AS first_day_id
        FROM parti p
        ORDER BY p.id ASC
    ";
    return $this->db->query($sql)->fetchAll();
}
public function findBySlugWithDays(string $slug): ?array
{
    $st = $this->db->prepare("SELECT id, slug, titolo, descrizione FROM parti WHERE slug = :slug LIMIT 1");
    $st->execute([':slug' => $slug]);
    $p = $st->fetch();
    if (!$p) return null;

    $stDays = $this->db->prepare("
        SELECT id, parte_id, giorno_num, data, titolo, immagine_copertina, riassunto, data_modifica
        FROM giorni
        WHERE parte_id = :pid
        ORDER BY giorno_num ASC, id ASC
    ");
    $stDays->execute([':pid' => $p['id']]);
    $p['giorni'] = $stDays->fetchAll();

    // hero_img verrà riempito in controller con la prima cover disponibile
    $p['hero_img'] = null;

    return $p;
}

public function listForHome(): array
{
    // Prendo i campi che servono alla Home (inclusi i nuovi)
    $parts = $this->db->query("
        SELECT p.id, p.slug, p.titolo, p.etichetta, p.descrizione_breve
        FROM parti p
        ORDER BY p.id ASC
    ")->fetchAll();

    // Primo giorno della parte (id + cover) per comporre bottone e hero
    $stFirst = $this->db->prepare("
        SELECT id, immagine_copertina
        FROM giorni
        WHERE parte_id = :pid
        ORDER BY giorno_num ASC, id ASC
        LIMIT 1
    ");

    foreach ($parts as &$p) {
        $stFirst->execute([':pid' => $p['id']]);
        $first = $stFirst->fetch();
        $p['first_day_id']    = $first['id'] ?? null;
        $p['first_day_cover'] = $first['immagine_copertina'] ?? null; // path così com’è nel DB
    }
    unset($p);

    return $parts;
}


}
