<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Csrf;

final class ProfileController extends BaseAdminController
{
    public function form(): void
    {
        $this->requireLogin(['admin','editor','accountant']);
        $this->view('admin/profile.twig', [
            'title'=>'Profilo',
            'csrf'=>Csrf::token(),
        ]);
    }

    public function save(): void
    {
        $this->requireLogin(['admin','editor','accountant']);
        if (!Csrf::check($_POST['csrf'] ?? null)) { http_response_code(419); echo 'CSRF'; return; }

        $pwd = (string)($_POST['new_password'] ?? '');
        if (strlen($pwd) < 10) {
            $this->view('admin/profile.twig', ['title'=>'Profilo', 'error'=>'Password troppo corta (min 10)', 'csrf'=>Csrf::token()]);
            return;
        }
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $id   = (int)($_SESSION['admin']['id'] ?? 0);
        \DB::pdo()->prepare("UPDATE admin_users SET password_hash=:h WHERE id=:id")->execute([':h'=>$hash, ':id'=>$id]);

        $this->view('admin/profile.twig', ['title'=>'Profilo', 'ok'=>'Password aggiornata', 'csrf'=>Csrf::token()]);
    }
}
