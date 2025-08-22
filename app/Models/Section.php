<?php
declare(strict_types=1);

namespace App\Models;

final class Section extends BaseModel
{
    /** /sezione/{id} + info giorno */
    public function findById(int $id): ?array
    {
        $st = $this->db->prepare("
            SELECT
                s.id, s.giorno_id, s.ordine, s.sovratitolo, s.titolo, s.testo, s.immagine,
                g.giorno_num AS giorno_num, g.titolo AS giorno_titolo, g.data AS giorno_data
            FROM sezioni s
            LEFT JOIN giorni g ON g.id = s.giorno_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function listByDay(int $dayId): array
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

    $gid = (int)$cur['giorno_id'];
    $ord = (int)$cur['ordine'];
    $cid = (int)$cur['id'];

    // PREV: sezione precedente nello stesso giorno
    $sqlPrev = "
      SELECT id, titolo
      FROM sezioni
      WHERE giorno_id = :gid
        AND (ordine < :ord1 OR (ordine = :ord2 AND id < :cid1))
      ORDER BY ordine DESC, id DESC
      LIMIT 1";
    $st = $this->db->prepare($sqlPrev);
    $st->execute([
        ':gid'  => $gid,
        ':ord1' => $ord,
        ':ord2' => $ord,
        ':cid1' => $cid,
    ]);
    $prev = $st->fetch() ?: null;

    // NEXT: sezione successiva nello stesso giorno
    $sqlNext = "
      SELECT id, titolo
      FROM sezioni
      WHERE giorno_id = :gid
        AND (ordine > :ord1 OR (ordine = :ord2 AND id > :cid1))
      ORDER BY ordine ASC, id ASC
      LIMIT 1";
    $st = $this->db->prepare($sqlNext);
    $st->execute([
        ':gid'  => $gid,
        ':ord1' => $ord,
        ':ord2' => $ord,
        ':cid1' => $cid,
    ]);
    $next = $st->fetch() ?: null;

    return ['prev' => $prev, 'next' => $next];
}



}
