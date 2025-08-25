<?php
declare(strict_types=1);

namespace App\Support;

final class Flash
{
    public static function add(string $type, string $msg): void {
        Csrf::ensureSession();
        $_SESSION['flash'][] = ['type'=>$type, 'msg'=>$msg];
    }
    public static function consume(): array {
        Csrf::ensureSession();
        $all = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $all;
    }
}
