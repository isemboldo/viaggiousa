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
        /** Slug semplice per URL */
    public static function slugify(string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** Mappa categorie → slug e viceversa */
    public function categoriesIndex(): array {
        $rows = $this->expense->all();
        $set  = [];
        foreach ($rows as $r) {
            $cat = $r['categoria'] ?: 'Varie';
            $set[$cat] = true;
        }
        ksort($set, SORT_NATURAL | SORT_FLAG_CASE);
        $cats = array_keys($set);

        $forward = []; // slug => label
        $reverse = []; // label => slug
        foreach ($cats as $c) {
            $sl = self::slugify($c);
            $forward[$sl] = $c;
            $reverse[$c]  = $sl;
        }
        return ['slug_to_label' => $forward, 'label_to_slug' => $reverse];
    }

    /** Elenco spese per categoria (slug), con totali in CHF e warning valute mancanti */
    public function listByCategorySlug(string $slug): array {
        $idx = $this->categoriesIndex();
        $label = $idx['slug_to_label'][$slug] ?? null;
        if (!$label) { return ['label'=>null, 'rows'=>[], 'total'=>0.0, 'missing'=>[]]; }

        $rows = $this->expense->all();
        $out = [];
        $total = 0.0;
        $missing = [];

        foreach ($rows as $r) {
            $cat = $r['categoria'] ?: 'Varie';
            if (mb_strtolower($cat,'UTF-8') !== mb_strtolower($label,'UTF-8')) continue;

            $rate = $r['rate']; $val = $r['valuta'];
            if ($rate <= 0) { $missing[$val] = true; continue; }

            if ($r['amount_chf'] === null) continue;
            $out[] = [
                'id'          => $r['id'],
                'giorno_id'   => $r['giorno_id'],
                'descrizione' => $r['descrizione'],
                'valuta'      => $r['valuta'],
                'amount_rif'  => $r['amount_rif'],
                'amount_chf'  => $r['amount_chf'],
                'data'        => $r['data_spesa'],
                'pagato_da'   => $r['pagato_da'],
                'diviso_per'  => $r['diviso_per'],
            ];
            $total += (float)$r['amount_chf'];
        }

        usort($out, fn($a,$b)=> strcmp($a['data']??'', $b['data']??''));
        return [
            'label'   => $label,
            'rows'    => $out,
            'total'   => $total,
            'missing' => array_keys($missing)
        ];
    }

    /** Ledger per un partecipante (slug del nome normalizzato) */
    public function ledgerByParticipantSlug(string $slug): array {
        // dallo slug ricaviamo il nome normalizzato (spazi al posto dei '-')
        $nameRaw = str_replace('-', ' ', $slug);
        $name    = \App\Models\Expense::normalizeName($nameRaw);

        $rows = $this->expense->all();
        $pays = $this->payment->all(); // già con importo_chf

        $dovuti = [];     $totDov = 0.0;
        $pagati = [];     $totPag = 0.0;   // “ha pagato” (spese intestate)
        $contrib = [];    $totCon = 0.0;   // “contributi” (pagamenti al fondo)

        foreach ($rows as $r) {
            if ($r['amount_chf'] === null) continue;
            $amount = (float)$r['amount_chf'];

            $parts = \App\Models\Expense::splitParticipants($r['diviso_per']);
            $payer = \App\Models\Expense::normalizeName($r['pagato_da'] ?? '');

            // quota dovuta
            if (in_array($name, $parts, true) && count($parts)>0) {
                $share = $amount / count($parts);
                $dovuti[] = [
                    'descrizione' => $r['descrizione'],
                    'categoria'   => $r['categoria'] ?: 'Varie',
                    'data'        => $r['data_spesa'],
                    'giorno_id'   => $r['giorno_id'],
                    'quota_chf'   => $share,
                    'valuta'      => $r['valuta'],
                ];
                $totDov += $share;
            }

            // “ha pagato” come intestatario
            if ($payer !== '' && $payer === $name) {
                $pagati[] = [
                    'descrizione' => $r['descrizione'],
                    'categoria'   => $r['categoria'] ?: 'Varie',
                    'data'        => $r['data_spesa'],
                    'giorno_id'   => $r['giorno_id'],
                    'importo_chf' => $amount,
                    'valuta'      => $r['valuta'],
                ];
                $totPag += $amount;
            }
        }

        // contributi
        foreach ($pays as $p) {
            if ($p['importo_chf'] === null) continue;
            if ($p['partecipante'] === $name) {
                $contrib[] = [
                    'data'        => $p['data'],
                    'importo_chf' => $p['importo_chf'],
                    'valuta'      => $p['valuta'],
                    'importo'     => $p['importo'],
                    'note'        => $p['note'],
                ];
                $totCon += (float)$p['importo_chf'];
            }
        }

        usort($dovuti,  fn($a,$b)=> strcmp($a['data']??'', $b['data']??''));
        usort($pagati,  fn($a,$b)=> strcmp($a['data']??'', $b['data']??''));
        usort($contrib, fn($a,$b)=> strcmp($a['data']??'', $b['data']??''));

        return [
            'name'         => $name,
            'dovuti'       => $dovuti,
            'ha_pagato'    => $pagati,
            'contributi'   => $contrib,
            'tot_dovuti'   => $totDov,
            'tot_pagati'   => $totPag,
            'tot_contrib'  => $totCon,
            'saldo'        => $totCon - $totDov,  // definizione saldo canon
        ];
    }

}
