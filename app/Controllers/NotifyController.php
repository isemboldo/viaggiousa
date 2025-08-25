<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Subscription;
use App\Models\Updates;
use App\Models\EmailLog;
use App\Services\MailerService;

final class NotifyController extends BaseController
{
    public function digest(): void
    {
        $key = $_GET['key'] ?? '';
        if (!$key || $key !== ($_ENV['NOTIFY_CRON_KEY'] ?? '')) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $pdo   = \DB::pdo();
        $subs  = new Subscription($pdo);
        $upd   = new Updates($pdo);
        $log   = new EmailLog($pdo);
        $mailr = new MailerService();

        $base = rtrim($_ENV['APP_URL_BASE'] ?? '', '/');

        $sent = 0; $skipped = 0;
        foreach ($subs->allConfirmed() as $row) {
            $since = $row['last_digest_at'] ?: date('Y-m-d H:i:s', strtotime('-7 days'));
            $changes = $upd->daysSince($since);
            if (!$changes) { $skipped++; continue; }

            $html = \View::env()->render('emails/digest.twig', [
                'base_url'=>$base,
                'changes'=>$changes
            ]);

            $res = $mailr->send($row['email'], 'Novità sul Viaggio USA', $html);
            $log->log($row['email'], 'digest', 'Novità sul Viaggio USA', $res['ok'], $res['error']);
            if ($res['ok']) {
                $subs->touchDigest($row['email']);
                $sent++;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sent'=>$sent, 'skipped'=>$skipped], JSON_UNESCAPED_UNICODE);
    }
}
