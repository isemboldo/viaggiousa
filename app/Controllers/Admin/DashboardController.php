<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

final class DashboardController extends BaseAdminController
{
    public function index(): void
    {
        $this->requireLogin(['admin','editor','accountant']);

        // mini-kpi (opzionali)
        $pdo = \DB::pdo();
        $parts = (int)$pdo->query("SELECT COUNT(*) FROM parti")->fetchColumn();
        $days  = (int)$pdo->query("SELECT COUNT(*) FROM giorni")->fetchColumn();
        $secs  = (int)$pdo->query("SELECT COUNT(*) FROM sezioni")->fetchColumn();

        $this->view('admin/dashboard.twig', [
            'title' => 'Dashboard',
            'kpi'   => ['parti'=>$parts, 'giorni'=>$days, 'sezioni'=>$secs],
        ]);
    }
}
