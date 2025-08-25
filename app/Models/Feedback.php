<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Feedback
{
    public function __construct(private PDO $db) {}

    public function ensureRow(int $sezioneId): void
    {
        $st = $this->db->prepare("INSERT IGNORE INTO feedback_sezioni (sezione_id, likes, dislikes, more_info) VALUES (:id,0,0,0)");
        $st->execute([':id'=>$sezioneId]);
    }

    public function increment(int $sezioneId, string $action): void
    {
        $this->ensureRow($sezioneId);

        $col = match ($action) {
            'like'    => 'likes',
            'dislike' => 'dislikes',
            'more'    => 'more_info',
            default   => null
        };
        if (!$col) throw new \InvalidArgumentException('Invalid action');

        $sql = "UPDATE feedback_sezioni SET {$col} = {$col} + 1 WHERE sezione_id = :id";
        $st  = $this->db->prepare($sql);
        $st->execute([':id'=>$sezioneId]);
    }

    public function counts(int $sezioneId): array
    {
        $st = $this->db->prepare("SELECT likes, dislikes, more_info FROM feedback_sezioni WHERE sezione_id=:id");
        $st->execute([':id'=>$sezioneId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: ['likes'=>0,'dislikes'=>0,'more_info'=>0];
    }
}
