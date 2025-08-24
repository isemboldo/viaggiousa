<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Fx;
use App\Services\BudgetService;

final class RendicontoController extends BaseController
{
    public function index(): void
    {
        $service = new BudgetService(
            new Expense(\DB::pdo(), new Fx(\DB::pdo())),
            new Payment(\DB::pdo()),
            new Fx(\DB::pdo())
        );

        $totals = $service->totals();
        $cats   = $service->byCategory();
        $parts  = $service->participants();

        // formattazione CHF per la view
        $fmt = fn(float $v): string => 'CHF ' . number_format($v, 2, ',', '’');

        $totalsFmt = array_map($fmt, $totals['values']);
        $catsFmt   = [];
        foreach ($cats['values'] as $k => $v) { $catsFmt[$k] = $fmt((float)$v); }

        $participants = [];
        foreach ($parts['rows'] as $name => $row) {
            $participants[] = [
                'name'        => $name,
                'dovuto'      => $fmt($row['dovuto']),
                'ha_pagato'   => $fmt($row['ha_pagato']),
                'contributi'  => $fmt($row['contributi']),
                'saldo'       => $fmt($row['saldo']),
                '_saldo_raw'  => $row['saldo'], // per segno positivo/negativo
            ];
        }

        $this->view('rendiconto/index.twig', [
            'title'   => 'Rendiconto spese',
            'meta'    => [
                'title'       => 'Rendiconto spese',
                'description' => 'Totali, categorie e saldi per partecipante.',
                'url'         => ($_ENV['APP_URL_BASE'] ?? '') . '/rendiconto',
            ],
            'totals'  => $totalsFmt,
            'missing_currencies' => $totals['missing'] ?: $cats['missing'] ?? [],
            'by_category' => $catsFmt,
            'participants' => $participants,
            'unassigned_fmt' => $fmt($parts['unassigned']),
        ]);
    }

    // Se vorrai i dettagli:
    public function categoria(string $name): void
    {
        // TODO (fase successiva): elenco voci per categoria
        http_response_code(501);
        echo "Categoria '$name' in arrivo.";
    }

    public function partecipante(string $name): void
    {
        // TODO (fase successiva): estratto conto personale
        http_response_code(501);
        echo "Partecipante '$name' in arrivo.";
    }
}
