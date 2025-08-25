<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Support\Csrf;
use PDO;

final class AuthService
{
    private PDO $db;

    public function __construct() { $this->db = \DB::pdo(); Csrf::ensureSession(); }

    public function user(): ?array {
        return $_SESSION['admin_user'] ?? null;
    }

    public function check(): bool {
        return isset($_SESSION['admin_user']);
    }

    public function login(string $email, string $password, string $ip, string $ua): bool
    {
        $email = trim(mb_strtolower($email));
        // rate-limit: max 5 tentativi negli ultimi 15 minuti per IP/email
        if ($this->tooManyAttempts($email, $ip, $ua)) return false;

        $st = $this->db->prepare("SELECT * FROM admin_users WHERE email=:e AND is_active=1 LIMIT 1");
        $st->execute([':e'=>$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            $this->registerFailed($email, $ip, $ua);
            return false;
        }

        // ok
        $_SESSION['admin_user'] = [
            'id'    => (int)$u['id'],
            'email' => $u['email'],
            'role'  => $u['role'],
        ];
        session_regenerate_id(true);

        $this->db->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=:id")
                 ->execute([':id'=>$u['id']]);

        // audit
        $this->audit((int)$u['id'], 'login', null, null, null);
        return true;
    }

    public function logout(): void
    {
        if (!empty($_SESSION['admin_user']['id'])) {
            $this->audit((int)$_SESSION['admin_user']['id'], 'logout', null, null, null);
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        @session_destroy();
    }

    public function requireRole(string|array $roles): void
    {
        $roles = (array)$roles;
        if (!$this->check() || !in_array($_SESSION['admin_user']['role'] ?? '', $roles, true)) {
            header('Location: ' . ($_ENV['APP_URL_BASE'] ?? '') . '/admin/login'); exit;
        }
    }

    private function tooManyAttempts(string $email, string $ip, string $ua): bool
    {
        $hash = fn(string $v) => hash('sha256', $v . '|' . ($_ENV['ADMIN_SALT'] ?? 'salt'));
        $ipH = $hash($ip); $uaH = $hash($ua);

        $st = $this->db->prepare("
            SELECT attempts, last_attempt_at
            FROM admin_failed_logins
            WHERE email=:e AND ip_hash=:ipH AND ua_hash=:uaH
            LIMIT 1
        ");
        $st->execute([':e'=>$email, ':ipH'=>$ipH, ':uaH'=>$uaH]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) return false;
        $minutes = (time() - strtotime($row['last_attempt_at']))/60;
        if ($minutes > 15) return false;
        return ((int)$row['attempts'] >= 5);
    }

    private function registerFailed(string $email, string $ip, string $ua): void
    {
        $hash = fn(string $v) => hash('sha256', $v . '|' . ($_ENV['ADMIN_SALT'] ?? 'salt'));
        $ipH = $hash($ip); $uaH = $hash($ua);

        $st = $this->db->prepare("
            INSERT INTO admin_failed_logins (email, ip_hash, ua_hash, attempts, last_attempt_at)
            VALUES (:e,:ipH,:uaH,1,NOW())
            ON DUPLICATE KEY UPDATE attempts=attempts+1, last_attempt_at=NOW()
        ");
        // Nota: per ON DUPLICATE KEY funzionare, serve unique(email, ip_hash, ua_hash).
        // Se non vuoi unique, sostituisci con SELECT+UPDATE o crea unique:
        // ALTER TABLE admin_failed_logins ADD UNIQUE KEY ux_fail (email, ip_hash, ua_hash);
        $st->execute([':e'=>$email, ':ipH'=>$ipH, ':uaH'=>$uaH]);
    }

    public function changePassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->db->prepare("UPDATE admin_users SET password_hash=:h WHERE id=:id")
                 ->execute([':h'=>$hash, ':id'=>$userId]);
        $this->audit($userId, 'change_password', 'admin_users', $userId, null);
    }

    public function audit(int $adminUserId, string $action, ?string $entity, ?int $entityId, ?array $details): void
    {
        $st = $this->db->prepare("
            INSERT INTO admin_audit (admin_user_id, action, entity, entity_id, details, created_at)
            VALUES (:uid,:act,:ent,:eid,:det,NOW())
        ");
        $st->execute([
            ':uid'=>$adminUserId, ':act'=>$action, ':ent'=>$entity,
            ':eid'=>$entityId, ':det'=>$details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
        ]);
    }
}
