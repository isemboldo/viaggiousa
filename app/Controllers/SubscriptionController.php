<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Subscription;
use App\Models\EmailLog;
use App\Services\MailerService;

final class SubscriptionController extends BaseController
{
    private function repo(): Subscription { return new Subscription(\DB::pdo()); }
    private function mailer(): MailerService { return new MailerService(); }
    private function logger(): EmailLog { return new EmailLog(\DB::pdo()); }

    /** GET /iscrizione */
    public function form(): void
    {
        $this->view('subscription/form.twig', [
            'title' => 'Iscrizione aggiornamenti',
            'meta'  => [
                'title' => 'Iscrizione aggiornamenti',
                'description' => 'Ricevi una mail quando aggiorniamo il diario.',
                'url'  => ($_ENV['APP_URL_BASE'] ?? '') . '/iscrizione',
            ],
            't' => time(), // time-trap
        ]);
    }

    /** POST /iscrizione */
    public function subscribe(): void
    {
        // Honeypot + time-trap + rate limit minimo
        $hp = $_POST['website'] ?? '';
        if ($hp !== '') { http_response_code(200); echo 'OK'; return; }

        $t0 = (int)($_POST['t'] ?? 0);
        if (time() - $t0 < 2) { $this->renderError('Invio troppo rapido, riprova.'); return; }

        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->renderError('Email non valida.'); return; }

        // rate-limit by email (cooldown 120s)
        $lock = $this->lockPath('subscribe_' . md5($email));
        if (is_file($lock) && (time() - filemtime($lock) < 120)) {
            $this->renderError('Richieste troppo ravvicinate, riprova tra poco.');
            return;
        }
        @touch($lock);

        $sub = $this->repo()->byEmail($email);
        if ($sub && $sub['status'] === 'confirmed') {
            $this->view('subscription/already.twig', [
                'title' => 'Sei già iscrittə',
                'email' => $email,
            ]);
            return;
        }

        $sub = $this->repo()->createOrRefreshPending($email);
        $token = $sub['token'];

        $twig = \View::env();
        $base = $this->absoluteBaseUrl();
        $confirmUrl = $base . '/iscrizione/conferma/' . $token;

        $html = $twig->render('emails/confirm.twig', [
            'confirm_url' => $confirmUrl,
            'base_url'    => $base,
        ]);

        $mail = $this->mailer()->send($email, 'Conferma iscrizione – Viaggio USA', $html);
        $this->logger()->log($email, 'confirm', 'Conferma iscrizione – Viaggio USA', $mail['ok'], $mail['error']);

        $this->view('subscription/confirm.twig', [
            'title' =>'Controlla la posta',
            'email' => $email,
        ]);
    }

    /** GET /iscrizione/conferma/{token} */
    public function confirm(string $token): void
    {
        $ok = $this->repo()->confirmByToken($token);
        if (!$ok) { $this->renderError('Token non valido o già usato.'); return; }

        // welcome email (opzionale)
        $sub = $this->repo()->byToken($token);
        if ($sub && !empty($sub['email'])) {
            $twig = \View::env();
            $base = $this->absoluteBaseUrl();
            $html = $twig->render('emails/welcome.twig', ['base_url'=>$base]);
            $mail = $this->mailer()->send($sub['email'], 'Benvenutə – Viaggio USA', $html);
            $this->logger()->log($sub['email'], 'welcome', 'Benvenutə – Viaggio USA', $mail['ok'], $mail['error']);
        }

        $this->view('subscription/confirmed.twig', [
            'title'=>'Iscrizione confermata',
        ]);
    }

    /** GET /iscrizione/unsubscribe/{token} */
    public function unsubscribe(string $token): void
    {
        $ok = $this->repo()->unsubscribeByToken($token);
        $this->view('subscription/unsubscribed.twig', [
            'title' => $ok ? 'Disiscrizione completata' : 'Link non valido',
            'ok'    => $ok,
        ]);
    }

    private function lockPath(string $name): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . $name . '.lock';
    }

    private function renderError(string $msg): void
    {
        http_response_code(400);
        $this->view('subscription/error.twig', [
            'title'=>'Errore',
            'message'=>$msg,
        ]);
    }
}
