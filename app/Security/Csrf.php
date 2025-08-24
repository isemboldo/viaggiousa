<?php
declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    private const KEY = '_csrf_token';

    private static function ensureSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function token(): string {
        self::ensureSession();
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $token): bool {
        self::ensureSession();
        return is_string($token) && hash_equals($_SESSION[self::KEY] ?? '', $token);
    }
}
