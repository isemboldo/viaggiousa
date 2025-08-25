<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Subscription
{
    public function __construct(private PDO $db) {}

    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function byEmail(string $email): ?array
    {
        $st = $this->db->prepare("SELECT * FROM iscrizioni WHERE email = :e");
        $st->execute([':e'=>$email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function byToken(string $token): ?array
    {
        $st = $this->db->prepare("SELECT * FROM iscrizioni WHERE token = :t");
        $st->execute([':t'=>$token]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function createOrRefreshPending(string $email, ?string $token = null): array
    {
        $token = $token ?: self::token();
        $now = date('Y-m-d H:i:s');

        $existing = $this->byEmail($email);
        if ($existing) {
            // se blocked o confirmed, non sovrascrivo status; rigenero token solo se manca
            if (empty($existing['token'])) {
                $st = $this->db->prepare("UPDATE iscrizioni SET token=:t WHERE email=:e");
                $st->execute([':t'=>$token, ':e'=>$email]);
                $existing['token'] = $token;
            }
            return $existing;
        }

        $st = $this->db->prepare("
            INSERT INTO iscrizioni (email, status, token, created_at)
            VALUES (:e, 'pending', :t, :c)
        ");
        $st->execute([':e'=>$email, ':t'=>$token, ':c'=>$now]);

        return $this->byEmail($email) ?? [];
    }

    public function confirmByToken(string $token): bool
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare("
            UPDATE iscrizioni
            SET status='confirmed', confirmed_at=:now
            WHERE token=:t
        ");
        $st->execute([':t'=>$token, ':now'=>$now]);
        return $st->rowCount() > 0;
    }

    public function unsubscribeByToken(string $token): bool
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare("
            UPDATE iscrizioni
            SET status='blocked', unsubscribed_at=:now
            WHERE token=:t
        ");
        $st->execute([':t'=>$token, ':now'=>$now]);
        return $st->rowCount() > 0;
    }

    public function allConfirmed(): array
    {
        $st = $this->db->query("SELECT * FROM iscrizioni WHERE status='confirmed'");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function touchDigest(string $email): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare("UPDATE iscrizioni SET last_digest_at=:now WHERE email=:e");
        $st->execute([':now'=>$now, ':e'=>$email]);
    }
}
