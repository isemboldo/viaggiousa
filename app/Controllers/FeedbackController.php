<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Feedback;
use App\Security\Csrf;
use PDO;

final class FeedbackController extends BaseController
{
    private function appKey(): string {
        return $_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? 'temp-key-change-me';
    }

    private function readCookie(int $sectionId): array {
        $cookie = $_COOKIE['vu_fb'] ?? '';
        if (!$cookie) return [];
        $parts = explode('.', $cookie, 2);
        if (count($parts) !== 2) return [];
        [$payload, $sig] = $parts;
        $calc = hash_hmac('sha256', $payload, $this->appKey());
        if (!hash_equals($calc, $sig)) return [];
        $data = json_decode(base64_decode($payload) ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    private function writeCookie(array $data): void {
        $payload = base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES));
        $sig = hash_hmac('sha256', $payload, $this->appKey());
        setcookie('vu_fb', $payload . '.' . $sig, [
            'expires'  => time() + 3600*24*365,
            'path'     => $_ENV['APP_URL_BASE'] ?? '/',
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
        ]);
    }

    public function react(string $id): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // accetta SOLO POST
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']); return;
        }

        $sectionId = (int)$id;
        $action = $_POST['action'] ?? '';
        $csrf   = $_POST['csrf']   ?? '';

        if ($sectionId <= 0 || !in_array($action, ['like','dislike','info'], true)) {
            http_response_code(422);
            echo json_encode(['error'=>'Bad request']); return;
        }

        // CSRF: obbligatorio in prod; in locale tolleriamo per test
        $isLocal = (($_ENV['APP_ENV'] ?? '') === 'local');
        if (!Csrf::check($csrf) && !$isLocal) {
            http_response_code(419);
            echo json_encode(['error'=>'CSRF token invalid']); return;
        }

        // anti-doppio click per utente/dispositivo
        $cookie = $this->readCookie($sectionId);
        $key = "s{$sectionId}";
        $done = $cookie[$key] ?? ['like'=>false,'dislike'=>false,'info'=>false];
        if (!empty($done[$action])) {
            http_response_code(409);
            echo json_encode(['error'=>'Already recorded']); return;
        }

        // <<< QUI LA FIX: prendiamo la PDO dal singleton DB::pdo() >>>
        $pdo = \DB::pdo();
        $model  = new Feedback($pdo);
        $counts = $model->increment($sectionId, $action);

        $done[$action] = true;
        $cookie[$key] = $done;
        $this->writeCookie($cookie);

        echo json_encode(['ok'=>true, 'counts'=>$counts, 'done'=>$done]);
    }
}
