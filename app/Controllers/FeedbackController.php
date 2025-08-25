<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Feedback;
use App\Models\FeedbackLog;

final class FeedbackController extends BaseController
{
    /** finestra rate-limit (secondi): 86400 = 24h */
    private int $windowSec = 86400;

    private function salt(): string
    {
        return $_ENV['FEEDBACK_SALT'] ?? ($_ENV['APP_KEY'] ?? 'salt');
    }

    private function clientToken(): string
    {
        // cookie persistente lato client
        $name = 'fb_token';
        if (empty($_COOKIE[$name])) {
            $tok = bin2hex(random_bytes(16));
            setcookie($name, $tok, time()+60*60*24*365, '/', '', false, true);
            $_COOKIE[$name] = $tok;
        }
        return (string)$_COOKIE[$name];
    }

    private function hashValue(string $value): string
    {
        return hash('sha256', $value . '|' . $this->salt());
    }

    /** POST /api/section/{id}/feedback  body: action=like|dislike|more */
    public function react(string $id): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $sezioneId = (int)$id;
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if (!in_array($action, ['like','dislike','more'], true)) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Azione non valida']); return;
        }

        // Identità "soft" dell'utente
        $cookie = $this->clientToken();
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Hash privacy-preserving
        $ipHash = $this->hashValue($ip);
        $uaHash = $this->hashValue($ua);

        $pdo = \DB::pdo();
        $fb  = new Feedback($pdo);
        $log = new FeedbackLog($pdo);

        // Blocco giudizio opposto (like vs dislike) sulla stessa sezione
if (in_array($action, ['like','dislike'], true)
    && $log->hasOppositeAction($sezioneId, $action, $ipHash, $uaHash, $cookie)) {

    $counts = $fb->counts($sezioneId);
    $msg = 'Hai già espresso un giudizio per questa sezione.';
    echo json_encode([
        'status'  => 'ok',
        'message' => $msg,
        'counts'  => $counts,
        'already' => true,
        'blocked' => 'opposite'
    ]);
    return;
}


        // Rate-limit server: una reazione (stessa azione) per sezione ogni 24h per attore
        if ($log->recentlyExists($sezioneId, $action, $ipHash, $uaHash, $cookie, $this->windowSec)) {
            // niente errore: UX-friendly (già registrato)
            $counts = $fb->counts($sezioneId);
            echo json_encode(['status'=>'ok','message'=>'Già registrato','counts'=>$counts, 'already'=>true]);
            return;
        }

        // Incremento contatore + log
        $fb->increment($sezioneId, $action);
        $log->insert($sezioneId, $action, $ipHash, $uaHash, $cookie);

        $counts = $fb->counts($sezioneId);
        echo json_encode(['status'=>'ok','message'=>'Grazie!','counts'=>$counts, 'already'=>false]);
    }
}
