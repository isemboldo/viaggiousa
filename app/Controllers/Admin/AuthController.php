<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Csrf;

final class AuthController extends BaseAdminController
{
    private function hashId(string $v): string {
        $salt = $_ENV['ADMIN_SALT'] ?? ($_ENV['APP_KEY'] ?? 'salt'); return hash('sha256', $v.'|'.$salt);
    }

    public function loginForm(): void
    {
        $this->startAdminSession();
        \View::env()->display('admin/login.twig', [
            'title' => 'Login admin',
            'csrf'  => Csrf::token(),
        ]);
    }

    public function login(): void
    {
        $this->startAdminSession();
        if (!\App\Support\Csrf::check($_POST['csrf'] ?? null)) {
            http_response_code(419); echo 'CSRF'; return;
        }
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');

        $pdo = \DB::pdo();

        // Rate-limit
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ipH = $this->hashId($ip);
        $uaH = $this->hashId($ua);

        $max = (int)($_ENV['ADMIN_LOGIN_MAX_ATTEMPTS'] ?? 5);
        $lockMin = (int)($_ENV['ADMIN_LOGIN_LOCK_MIN'] ?? 15);

        $st = $pdo->prepare("SELECT * FROM admin_failed_logins WHERE email=:e AND ip_hash=:ip ORDER BY id DESC LIMIT 1");
        $st->execute([':e'=>$email, ':ip'=>$ipH]);
        $fl = $st->fetch(\PDO::FETCH_ASSOC);

        if ($fl && $fl['attempts'] >= $max && strtotime($fl['last_attempt_at']) > strtotime("-{$lockMin} minutes")) {
            $this->view('admin/login.twig', [
                'title'=>'Login admin',
                'error'=>"Troppi tentativi. Riprova tra {$lockMin} minuti.",
                'csrf'=>\App\Support\Csrf::token()
            ]);
            return;
        }

        // Cerca utente
        $st = $pdo->prepare("SELECT * FROM admin_users WHERE email=:e AND is_active=1");
        $st->execute([':e'=>$email]);
        $u = $st->fetch(\PDO::FETCH_ASSOC);

        if (!$u || !password_verify($pass, $u['password_hash'])) {
            // aggiorna failed
            if ($fl) {
                $st = $pdo->prepare("UPDATE admin_failed_logins SET attempts=attempts+1, last_attempt_at=NOW() WHERE id=:id");
                $st->execute([':id'=>$fl['id']]);
            } else {
                $st = $pdo->prepare("INSERT INTO admin_failed_logins (email, ip_hash, ua_hash, attempts, first_attempt_at, last_attempt_at)
                                     VALUES (:e,:ip,:ua,1,NOW(),NOW())");
                $st->execute([':e'=>$email, ':ip'=>$ipH, ':ua'=>$uaH]);
            }
            $this->view('admin/login.twig', [
                'title'=>'Login admin',
                'error'=>'Credenziali non valide',
                'csrf'=>\App\Support\Csrf::token()
            ]);
            return;
        }

        // Login OK
        $this->establishLogin($u);
        $pdo->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=:id")->execute([':id'=>$u['id']]);
        header('Location: ' . ($_ENV['APP_URL_BASE'] ?? '') . '/admin');
    }

    public function logout(): void
{
    parent::logout(); // <-- chiama il metodo del BaseAdminController
    header('Location: ' . ($_ENV['APP_URL_BASE'] ?? '') . '/admin/login');
}

}
