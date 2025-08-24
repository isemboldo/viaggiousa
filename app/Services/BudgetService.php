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

    /** Arrotondamento consistente a 2 decimali */
    private static function r2(float $v): float { return round($v, 2); }

    /**
     * Alias partecipanti → nome canonico.
     * Modifica liberamente questa mappa (chiave/lowercase → valore/canonico).
     * Esempi: 'fra' => 'Francesca', 'franzi' => 'Francesca'
     */
    private array $aliases = [
        // 'fra'    => 'Francesca',
        // 'franzi' => 'Francesca',
        // 'ale'    => 'Alessandro',
    ];

    /** Normalizza e applica alias */
    private function canonicalizeName(?string $raw): string
    {
        $n = Expense::normalizeName((string)$raw);
        $k = mb_strtolower($n, 'UTF-8');
        if (isset($this->aliases[$k])) return $this->aliases[$k];
        return $n;
    }

    /** Filtri comuni (date/valuta/categorie) */
    private function applyFilter(array $rows, array $filter): array
    {
        $from = $filter['from'] ?? null;
        $to   = $filter['to']   ?? null;
        $cur  = $filter['currency'] ?? null;
        $cats = $filter['categories'] ?? [];

        return array_values(array_filter($rows, function($r) use($from,$to,$cur,$cats){
            if ($from && (!isset($r['data_spesa']) || $r['data_spesa'] < $from)) return false;
            if ($to   && (!isset($r['data_spesa']) || $r['data_spesa'] > $to))   return false;
            if ($cur && strtoupper($r['valuta'] ?? '') !== $cur) return false;
            if ($cats && !in_array($r['categoria'] ?: 'Varie', $cats, true)) return false;
            return true;
        }));
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

    /** Totali: stimato/preventivo/reale (CHF) con arrotondamenti consistenti */
    public function totals(array $filter = []): array
    {
        $rows = $this->applyFilter($this->expense->all(), $filter);
        $missing = [];

        $sum = ['stimato'=>0.0,'preventivo'=>0.0,'reale'=>0.0];

        foreach ($rows as $r) {
            $rate = $r['rate'];
            $val  = $r['valuta'];
            if ($rate <= 0) { $missing[$val] = true; continue; }

            if ($r['stimato_chf']    !== null) $sum['stimato']    += (float)$r['stimato_chf'];
            if ($r['preventivo_chf'] !== null) $sum['preventivo'] += (float)$r['preventivo_chf'];
            if ($r['reale_chf']      !== null) $sum['reale']      += (float)$r['reale_chf'];
        }

        // arrotonda risultati finali
        foreach ($sum as $k=>$v) { $sum[$k] = self::r2($v); }

        return [
            'values'  => $sum,
            'missing' => array_keys($missing),
        ];
    }

    /** Breakdown per categoria (CHF, importo di riferimento) */
    public function byCategory(array $filter = []): array
    {
        $rows = $this->applyFilter($this->expense->all(), $filter);
        $out = [];
        $missing = [];

        foreach ($rows as $r) {
            $rate = $r['rate'];
            $val  = $r['valuta'];
            if ($rate <= 0) { $missing[$val] = true; continue; }

            $chf = $r['amount_chf'];
            if ($chf === null) continue;

            $cat = $r['categoria'] ?: 'Varie';
            $out[$cat] = ($out[$cat] ?? 0.0) + (float)$chf;
        }

        foreach ($out as $k=>$v) { $out[$k] = self::r2($v); }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return ['values' => $out, 'missing' => array_keys($missing)];
    }

    /**
     * Prospetto per partecipante (con alias e arrotondamenti):
     *  - dovuto: quota personale (split equo su diviso_per)
     *  - ha_pagato: spese intestate (payer)
     *  - contributi: pagamenti (convertiti in CHF)
     *  - saldo: contributi - dovuto
     */
    public function participants(array $filter = []): array
    {
        $rows = $this->applyFilter($this->expense->all(), $filter);
        $contrib = $this->payment->sumByParticipant();

        // applica alias ai contributi
        $contribCanon = [];
        foreach ($contrib as $who=>$amt) {
            $canon = $this->canonicalizeName($who);
            $contribCanon[$canon] = ($contribCanon[$canon] ?? 0.0) + (float)$amt;
        }

        $names = [];
        $due   = [];
        $paid  = [];
        $unassigned = 0.0;

        foreach ($rows as $r) {
            if ($r['amount_chf'] === null) continue;

            $amount = (float)$r['amount_chf'];
            if ($amount <= 0) continue;

            $partsRaw = \App\Models\Expense::splitParticipants($r['diviso_per']);
            $parts = array_map(fn($n)=> $this->canonicalizeName($n), $partsRaw);
            $payer = $this->canonicalizeName($r['pagato_da'] ?? '');

            if ($payer !== '') { $names[$payer] = true; }
            foreach ($parts as $p) { $names[$p] = true; }

            if ($payer !== '') {
                $paid[$payer] = ($paid[$payer] ?? 0.0) + $amount;
            }

            if (count($parts) > 0) {
                // split equo (arrotondiamo solo a fine somma)
                $share = $amount / count($parts);
                foreach ($parts as $p) {
                    $due[$p] = ($due[$p] ?? 0.0) + $share;
                }
            } else {
                $unassigned += $amount;
            }
        }

        // aggiungi nomi che appaiono solo nei contributi
        foreach ($contribCanon as $who=>$_sum) { $names[$who] = true; }

        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $result = [];
        foreach (array_keys($names) as $n) {
            $d = self::r2($due[$n]  ?? 0.0);
            $p = self::r2($paid[$n] ?? 0.0);
            $c = self::r2($contribCanon[$n] ?? 0.0);
            $result[$n] = [
                'dovuto'      => $d,
                'ha_pagato'   => $p,
                'contributi'  => $c,
                'saldo'       => self::r2($c - $d),
            ];
        }

        return [
            'rows'       => $result,
            'unassigned' => self::r2($unassigned),
        ];
    }

    /** Categoria → elenco spese (filtrabile tramite applyFilter) */
    public function listByCategorySlug(string $slug): array {
        $idx = $this->categoriesIndex();
        $label = $idx['slug_to_label'][$slug] ?? null;
        if (!$label) { return ['label'=>null, 'rows'=>[], 'total'=>0.0, 'missing'=>[]]; }

        $rows = $this->expense->all();
        $rows = $this->applyFilter($rows, ['categories'=>[$label]]);

        $out = [];
        $total = 0.0;
        $missing = [];

        foreach ($rows as $r) {
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
            'total'   => self::r2($total),
            'missing' => array_keys($missing)
        ];
    }

    /** Ledger personale (con alias) */
    public function ledgerByParticipantSlug(string $slug): array {
        $nameRaw = str_replace('-', ' ', $slug);
        $name    = $this->canonicalizeName($nameRaw);

        $rows = $this->expense->all();
        $pays = $this->payment->all(); // già importo_chf

        $dovuti = [];     $totDov = 0.0;
        $pagati = [];     $totPag = 0.0;
        $contrib = [];    $totCon = 0.0;

        foreach ($rows as $r) {
            if ($r['amount_chf'] === null) continue;
            $amount = (float)$r['amount_chf'];
            if ($amount <= 0) continue;

            $partsRaw = \App\Models\Expense::splitParticipants($r['diviso_per']);
            $parts = array_map(fn($n)=> $this->canonicalizeName($n), $partsRaw);
            $payer = $this->canonicalizeName($r['pagato_da'] ?? '');

            if (in_array($name, $parts, true) && count($parts)>0) {
                $share = $amount / count($parts);
                $dovuti[] = [
                    'descrizione' => $r['descrizione'],
                    'categoria'   => $r['categoria'] ?: 'Varie',
                    'data'        => $r['data_spesa'],
                    'giorno_id'   => $r['giorno_id'],
                    'quota_chf'   => self::r2($share),
                    'valuta'      => $r['valuta'],
                ];
                $totDov += $share;
            }

            if ($payer !== '' && $payer === $name) {
                $pagati[] = [
                    'descrizione' => $r['descrizione'],
                    'categoria'   => $r['categoria'] ?: 'Varie',
                    'data'        => $r['data_spesa'],
                    'giorno_id'   => $r['giorno_id'],
                    'importo_chf' => self::r2($amount),
                    'valuta'      => $r['valuta'],
                ];
                $totPag += $amount;
            }
        }

        foreach ($pays as $p) {
            if ($p['importo_chf'] === null) continue;
            if ($this->canonicalizeName($p['partecipante']) === $name) {
                $contrib[] = [
                    'data'        => $p['data'],
                    'importo_chf' => self::r2((float)$p['importo_chf']),
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
            'tot_dovuti'   => self::r2($totDov),
            'tot_pagati'   => self::r2($totPag),
            'tot_contrib'  => self::r2($totCon),
            'saldo'        => self::r2($totCon - $totDov),
        ];
    }

    /** Elenco voci con tasso mancante (per pannello "Da verificare") */
    public function missingRateRows(array $filter = []): array
    {
        $rows = $this->applyFilter($this->expense->all(), $filter);
        $out = [];
        foreach ($rows as $r) {
            if (($r['rate'] ?? 0) > 0) continue;
            $out[] = [
                'categoria'   => $r['categoria'] ?: 'Varie',
                'descrizione' => $r['descrizione'],
                'valuta'      => $r['valuta'],
                'amount_rif'  => (float)$r['amount_rif'],
                'data'        => $r['data_spesa'],
                'giorno_id'   => $r['giorno_id'],
            ];
        }
        // ordina per data
        usort($out, fn($a,$b)=> strcmp($a['data']??'', $b['data']??''));
        return $out;
    }
}
