<?php
declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public static function ensure(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    public static function token(): string {
        self::ensure(); return $_SESSION['csrf'];
    }
    public static function check(?string $t): bool {
        self::ensure(); return $t && hash_equals($_SESSION['csrf'], $t);
    }
}
