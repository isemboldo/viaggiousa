<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

abstract class BaseAdminController extends BaseController
{
    protected function startAdminSession(): void
    {
        $name = $_ENV['ADMIN_SESSION_NAME'] ?? 'viaggiousa_admin';
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO']==='https')
                    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_name($name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $_ENV['APP_URL_BASE'] ?? '/',
                'domain' => $_ENV['COOKIE_DOMAIN'] ?? '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    protected function requireLogin(?array $roles = null): void
    {
        $this->startAdminSession();
        $u = $_SESSION['admin'] ?? null;
        if (!$u) {
            header('Location: ' . ($_ENV['APP_URL_BASE'] ?? '') . '/admin/login');
            exit;
        }
        if ($roles && !in_array($u['role'] ?? '', $roles, true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }


protected function establishLogin(array $user): void
{
    $this->startAdminSession();
    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id'    => (int)$user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
}


    protected function logout(): void
    {
        $this->startAdminSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }
}
