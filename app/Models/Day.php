<?php
declare(strict_types=1);

namespace App\Models;

final class Day extends BaseModel
{
    /** Home: giorni + conteggio sezioni + metadati parte */
    public function listAllWithMeta(): array
    {
        $sql = "
            SELECT g.*, p.slug AS parte_slug, p.titolo AS parte_titolo,
                   COALESCE(sx.tot, 0) AS sezioni_count
            FROM giorni g
            LEFT JOIN parti p ON p.id = g.parte_id
            LEFT JOIN (
                SELECT giorno_id, COUNT(*) AS tot
                FROM sezioni
                GROUP BY giorno_id
            ) sx ON sx.giorno_id = g.id
            ORDER BY g.parte_id ASC, g.giorno_num ASC, g.id ASC
        ";
        return $this->db->query($sql)->fetchAll();
    }

    /** /giorno/{id} */
    public function findById(int $id): ?array
    {
        $st = $this->db->prepare("
            SELECT g.*, p.slug AS parte_slug, p.titolo AS parte_titolo
            FROM giorni g
            LEFT JOIN parti p ON p.id = g.parte_id
            WHERE g.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Sezioni del giorno ordinate */
    public function listSections(int $dayId): array
    {
        $st = $this->db->prepare("
            SELECT id, giorno_id, ordine, sovratitolo, titolo, testo, immagine
            FROM sezioni
            WHERE giorno_id = :gid
            ORDER BY ordine ASC, id ASC
        ");
        $st->execute([':gid' => $dayId]);
        return $st->fetchAll();
    }

    public function getPrevNext(int $id): array
{
    $cur = $this->findById($id);
    if (!$cur) return ['prev' => null, 'next' => null];

    // PREV: giorno precedente
    $sqlPrev = "
      SELECT g.*
      FROM giorni g
      WHERE (g.parte_id < :p1)
         OR (g.parte_id = :p2 AND (g.giorno_num < :n1 OR (g.giorno_num = :n2 AND g.id < :id1)))
      ORDER BY g.parte_id DESC, g.giorno_num DESC, g.id DESC
      LIMIT 1";
    $st = $this->db->prepare($sqlPrev);
    $st->execute([
        ':p1'  => $cur['parte_id'],
        ':p2'  => $cur['parte_id'],
        ':n1'  => $cur['giorno_num'],
        ':n2'  => $cur['giorno_num'],
        ':id1' => $cur['id'],
    ]);
    $prev = $st->fetch() ?: null;

    // NEXT: giorno successivo
    $sqlNext = "
      SELECT g.*
      FROM giorni g
      WHERE (g.parte_id > :p1)
         OR (g.parte_id = :p2 AND (g.giorno_num > :n1 OR (g.giorno_num = :n2 AND g.id > :id1)))
      ORDER BY g.parte_id ASC, g.giorno_num ASC, g.id ASC
      LIMIT 1";
    $st = $this->db->prepare($sqlNext);
    $st->execute([
        ':p1'  => $cur['parte_id'],
        ':p2'  => $cur['parte_id'],
        ':n1'  => $cur['giorno_num'],
        ':n2'  => $cur['giorno_num'],
        ':id1' => $cur['id'],
    ]);
    $next = $st->fetch() ?: null;

    return ['prev' => $prev, 'next' => $next];
}
public function getCoverById(int $id): ?string
{
    $st = $this->db->prepare("SELECT immagine_copertina FROM giorni WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) return null;
    $img = trim((string)($row['immagine_copertina'] ?? ''));
    return $img !== '' ? $img : null;
}
public function getFirstCover(): ?string
{
    $row = $this->db->query("
        SELECT immagine_copertina
        FROM giorni
        WHERE immagine_copertina IS NOT NULL AND immagine_copertina <> ''
        ORDER BY id ASC
        LIMIT 1
    ")->fetch();
    if (!$row) return null;
    $img = trim((string)$row['immagine_copertina']);
    return $img !== '' ? $img : null;
}





}
