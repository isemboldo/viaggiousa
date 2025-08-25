<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class FeedbackLog
{
    public function __construct(private PDO $db) {}

    public function insert(int $sezioneId, string $action, string $ipHash, string $uaHash, string $cookieToken): void
    {
        $st = $this->db->prepare("
            INSERT INTO feedback_log (sezione_id, action, ip_hash, ua_hash, cookie_token, created_at)
            VALUES (:sid, :act, :ip, :ua, :ck, :ts)
        ");
        $st->execute([
            ':sid'=>$sezioneId, ':act'=>$action,
            ':ip'=>$ipHash, ':ua'=>$uaHash, ':ck'=>$cookieToken,
            ':ts'=>date('Y-m-d H:i:s'),
        ]);
    }

    /** true se stessa (sezione+azione) è già stata registrata nelle ultime $seconds per lo stesso attore */
    public function recentlyExists(int $sezioneId, string $action, string $ipHash, string $uaHash, string $cookieToken, int $seconds): bool
    {
        $st = $this->db->prepare("
            SELECT 1
            FROM feedback_log
            WHERE sezione_id=:sid AND action=:act
              AND (cookie_token=:ck OR ip_hash=:ip OR ua_hash=:ua)
              AND created_at >= DATE_SUB(NOW(), INTERVAL :sec SECOND)
            LIMIT 1
        ");
        $st->bindValue(':sid', $sezioneId, PDO::PARAM_INT);
        $st->bindValue(':act', $action, PDO::PARAM_STR);
        $st->bindValue(':ck',  $cookieToken, PDO::PARAM_STR);
        $st->bindValue(':ip',  $ipHash, PDO::PARAM_STR);
        $st->bindValue(':ua',  $uaHash, PDO::PARAM_STR);
        $st->bindValue(':sec', $seconds, PDO::PARAM_INT);
        $st->execute();
        return (bool)$st->fetchColumn();
    }
}
