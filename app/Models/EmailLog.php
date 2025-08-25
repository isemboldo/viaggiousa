<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class EmailLog
{
    public function __construct(private PDO $db) {}

    public function log(string $email, string $template, string $subject, bool $ok, ?string $error = null): void
    {
        $st = $this->db->prepare("
            INSERT INTO email_log (email, template, subject, ok, error, sent_at)
            VALUES (:e,:tpl,:s,:ok,:err,:t)
        ");
        $st->execute([
            ':e'=>$email, ':tpl'=>$template, ':s'=>$subject,
            ':ok'=>$ok ? 1 : 0, ':err'=>$error,
            ':t'=>date('Y-m-d H:i:s'),
        ]);
    }
}
