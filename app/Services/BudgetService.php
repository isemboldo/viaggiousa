<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Fx;

final class BudgetService
{
    public function __construct(
        private Expense $expense,
        private Payment $payment,
        private Fx $fx
    ) {}

    public function totals(): array
    {
        $rows = $this->expense->all();
        $missing = [];

        $sum = [
            'stimato'    => 0.0,
            'preventivo' => 0.0,
            'reale'      => 0.0,
        ];

        foreach ($rows as $r) {
            $rate = $r['rate'];
            $val  = $r['valuta'];
            if ($rate <= 0) { $missing[$val] = true; continue; }

            if ($r['stimato_chf']    !== null) $sum['stimato']    += (float)$r['stimato_chf'];
            if ($r['preventivo_chf'] !== null) $sum['preventivo'] += (float)$r['preventivo_chf'];
            if ($r['reale_chf']      !== null) $sum['reale']      += (float)$r['reale_chf'];
        }

        return [
            'values'  => $sum,
            'missing' => array_keys($missing),
        ];
    }

    public function byCategory(): array
    {
        $rows = $this->expense->all();
        $out = [];
        $missing = [];

        foreach ($rows as $r) {
            $rate = $r['rate'];
            $val  = $r['valuta'];
            if ($rate <= 0) { $missing[$val] = true; continue; }

            // usiamo l'importo di RIF (reale > preventivo > stimato)
            $chf = $r['amount_chf'];
            if ($chf === null) continue;

            $cat = $r['categoria'] ?: 'Varie';
            $out[$cat] = ($out[$cat] ?? 0.0) + (float)$chf;
        }

        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return ['values' => $out, 'missing' => array_keys($missing)];
    }

    public function participants(): array
    {
        $rows = $this->expense->all();
        $contrib = $this->payment->sumByParticipant();

        $names = [];
        $due   = [];
        $paid  = [];
        $unassigned = 0.0;

        foreach ($rows as $r) {
            if ($r['amount_chf'] === null) continue;

            $amount = (float)$r['amount_chf'];
            if ($amount <= 0) continue;

            $parts = Expense::splitParticipants($r['diviso_per']);
            $payer = Expense::normalizeName($r['pagato_da'] ?? '');

            if ($payer !== '') { $names[$payer] = true; }
            foreach ($parts as $p) { $names[$p] = true; }

            if ($payer !== '') {
                $paid[$payer] = ($paid[$payer] ?? 0.0) + $amount;
            }

            if (count($parts) > 0) {
                $share = $amount / count($parts);
                foreach ($parts as $p) {
                    $due[$p] = ($due[$p] ?? 0.0) + $share;
                }
            } else {
                $unassigned += $amount;
            }
        }

        foreach ($contrib as $who => $_sum) { $names[$who] = true; }
        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $result = [];
        foreach (array_keys($names) as $n) {
            $d = $due[$n]  ?? 0.0;
            $p = $paid[$n] ?? 0.0;
            $c = $contrib[$n] ?? 0.0;
            $result[$n] = [
                'dovuto'      => $d,
                'ha_pagato'   => $p,
                'contributi'  => $c,
                'saldo'       => $c - $d,
            ];
        }

        return [
            'rows'       => $result,
            'unassigned' => $unassigned,
        ];
    }
}
