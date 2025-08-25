<?php
declare(strict_types=1);

namespace App\Controllers;

use View;

abstract class BaseController
{
    protected function view(string $template, array $data = []): void {
        $twig = View::env();
        echo $twig->render($template, $data);
    }
    protected function absoluteBaseUrl(): string
{
    // Preferisci valore esplicito da .env
    $abs = rtrim($_ENV['APP_URL_PUBLIC'] ?? '', '/');
    if ($abs !== '') return $abs;

    // Fallback: ricava da richiesta
    $scheme = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim($_ENV['APP_URL_BASE'] ?? '', '/'); // es. /viaggiousa

    return $scheme . '://' . $host . $path;
}

}
