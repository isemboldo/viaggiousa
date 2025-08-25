<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

final class MailerService
{
    public function send(string $toEmail, string $subject, string $html, ?string $textAlt = null): array
    {
        $driver = strtolower((string)($_ENV['MAIL_DRIVER'] ?? 'smtp')); // smtp | file | sendmail
        if ($driver === 'file') {
            return $this->writeToFile($toEmail, $subject, $html, $textAlt);
        }

        $mail = new PHPMailer(true);
        try {
            $host = $_ENV['MAIL_HOST'] ?? '';
            $user = $_ENV['MAIL_USER'] ?? '';
            $pass = $_ENV['MAIL_PASS'] ?? '';
            $port = (int)($_ENV['MAIL_PORT'] ?? 587);
            $from = $_ENV['MAIL_FROM'] ?? 'no-reply@example.com';
            $name = $_ENV['MAIL_NAME'] ?? 'Viaggio USA';

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, $name);
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;

            // Optional debug (solo locale): 0, 1, 2
            $debug = (int)($_ENV['MAIL_DEBUG'] ?? 0);
            if ($debug > 0) {
                $mail->SMTPDebug = $debug;   // 2 = verbose
                $mail->Debugoutput = function($str) {
                    $log = dirname(__DIR__, 2) . '/storage/logs/mail_debug.log';
                    @mkdir(dirname($log), 0775, true);
                    @file_put_contents($log, '['.date('c')."] ".$str.PHP_EOL, FILE_APPEND);
                };
            }

            // Trasporto
            if ($driver === 'sendmail') {
                $mail->isSendmail();
            } else {
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $port;

                // Timeout e TLS “permissivo” in locale
                $mail->Timeout = (int)($_ENV['MAIL_TIMEOUT'] ?? 10); // secondi
                $tlsVerify = ($_ENV['MAIL_TLS_VERIFY'] ?? '0') === '1';
                if (!$tlsVerify) {
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ];
                }
            }

            // Header List-Unsubscribe (token sarà sostituito nel template o ignorato dai client)
            $base = rtrim($_ENV['APP_URL_BASE'] ?? '/', '/');
            $mail->addCustomHeader('List-Unsubscribe', '<' . $base . '/iscrizione/unsubscribe/{token}>');

            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $textAlt ?: strip_tags($html);

            $ok = $mail->send();
            return ['ok'=>$ok, 'error'=>null];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    private function writeToFile(string $toEmail, string $subject, string $html, ?string $textAlt = null): array
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/mails';
        @mkdir($dir, 0775, true);
        $ts   = date('Ymd_His');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $toEmail);
        $base = "{$dir}/{$ts}_{$slug}";

        // salva HTML e TXT
        @file_put_contents($base . '.html', $html);
        @file_put_contents($base . '.txt', $textAlt ?: strip_tags($html));

        // salva un .meta minimale
        $meta = "To: {$toEmail}\nSubject: {$subject}\n";
        @file_put_contents($base . '.meta', $meta);

        return ['ok'=>true, 'error'=>null];
    }
}
