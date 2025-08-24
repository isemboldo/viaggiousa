<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Feedback
{
    public function __construct(private PDO $db) {}

    public function increment(int $sectionId, string $action): array
    {
        $colLikes = ($action === 'like')    ? 1 : 0;
        $colDis   = ($action === 'dislike') ? 1 : 0;
        $colInfo  = ($action === 'info')    ? 1 : 0;

        // Compatibile con MySQL/MariaDB senza usare VALUES()
        $sql = "
            INSERT INTO feedback_sezioni (sezione_id, likes, dislikes, more_info)
            VALUES (:id, :ins_likes, :ins_dislikes, :ins_info)
            ON DUPLICATE KEY UPDATE
                likes     = likes     + :upd_likes,
                dislikes  = dislikes  + :upd_dislikes,
                more_info = more_info + :upd_info
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':id'           => $sectionId,
            ':ins_likes'    => $colLikes,
            ':ins_dislikes' => $colDis,
            ':ins_info'     => $colInfo,
            ':upd_likes'    => $colLikes,
            ':upd_dislikes' => $colDis,
            ':upd_info'     => $colInfo,
        ]);

        return $this->getCounts($sectionId);
    }

    public function getCounts(int $sectionId): array
    {
        $st = $this->db->prepare("
            SELECT likes, dislikes, more_info
            FROM feedback_sezioni
            WHERE sezione_id = :id
        ");
        $st->execute([':id' => $sectionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['likes'=>0,'dislikes'=>0,'more_info'=>0];
        return [
            'likes'     => (int)$row['likes'],
            'dislikes'  => (int)$row['dislikes'],
            'more_info' => (int)$row['more_info'],
        ];
    }
}
