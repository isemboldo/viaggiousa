<?php
declare(strict_types=1);

namespace App\Controllers;

final class MaintenanceController extends BaseController
{
    /** GET /maintenance/cleanup?key=... */
    public function cleanup(): void
    {
        $key = $_GET['key'] ?? '';
        if (!$key || $key !== ($_ENV['MAINTENANCE_KEY'] ?? '')) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $pdo = \DB::pdo();

        $fbDays    = (int)($_ENV['FEEDBACK_RETENTION_DAYS'] ?? 90);  // log feedback: 90gg
        $mailDays  = (int)($_ENV['EMAIL_LOG_RETENTION_DAYS'] ?? 365); // email log: 365gg

        // feedback_log
        $st = $pdo->prepare("DELETE FROM feedback_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)");
        $st->bindValue(':d', $fbDays, \PDO::PARAM_INT);
        $st->execute();
        $deletedFeedback = $st->rowCount();

        // email_log
        $st = $pdo->prepare("DELETE FROM email_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL :d DAY)");
        $st->bindValue(':d', $mailDays, \PDO::PARAM_INT);
        $st->execute();
        $deletedEmail = $st->rowCount();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'deleted' => [
                'feedback_log' => $deletedFeedback,
                'email_log'    => $deletedEmail,
            ],
            'retention_days' => [
                'feedback_log' => $fbDays,
                'email_log'    => $mailDays,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}
