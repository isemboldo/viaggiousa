<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\BudgetService;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Fx;

final class RendicontoController extends BaseController
{
    /** Costruisce il service con i modelli */
    private function service(): BudgetService
    {
        $pdo = \DB::pdo();
        return new BudgetService(
            new Expense($pdo),
            new Payment($pdo),
            new Fx($pdo)
        );
    }

    /** /rendiconto (dashboard) */
    public function index(): void
    {
        $svc    = $this->service();

        // --- Filtri da querystring ---
        $from = $_GET['from'] ?? null;
        $to   = $_GET['to']   ?? null;
        $cur  = strtoupper(trim($_GET['cur'] ?? ''));
        $catSlug = $_GET['cat'] ?? '';

        $isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $from = $isDate($from) ? $from : null;
        $to   = $isDate($to)   ? $to   : null;

        // mappa categorie
        $catIndex = $svc->categoriesIndex(); // ['slug_to_label'=>..., 'label_to_slug'=>...]
        $catLabel = $catSlug && isset($catIndex['slug_to_label'][$catSlug])
            ? $catIndex['slug_to_label'][$catSlug]
            : null;

        // codici valuta disponibili
        $fxCodes = (new Fx(\DB::pdo()))->knownCodes();

        // filtro condiviso
        $filter = [
            'from'       => $from,
            'to'         => $to,
            'currency'   => ($cur && in_array($cur, $fxCodes, true)) ? $cur : null,
            'categories' => $catLabel ? [$catLabel] : [],
        ];

        // --- Calcoli ---
        $totals = $svc->totals($filter);
        $cats   = $svc->byCategory($filter);
        $parts  = $svc->participants($filter);

        $fmt = fn(float $v): string => 'CHF ' . number_format($v, 2, ',', '’');

        $catsFmt = [];
        foreach ($cats['values'] as $k => $v) { $catsFmt[$k] = $fmt((float)$v); }

        $totalsFmt = [
            'stimato'    => $fmt((float)($totals['values']['stimato'] ?? 0)),
            'preventivo' => $fmt((float)($totals['values']['preventivo'] ?? 0)),
            'reale'      => $fmt((float)($totals['values']['reale'] ?? 0)),
        ];

        $participants = [];
        foreach ($parts['rows'] as $name => $row) {
            $participants[] = [
                'name'            => $name,
                'name_slug'       => BudgetService::slugify($name),
                'dovuto'          => $fmt((float)$row['dovuto']),
                'ha_pagato'       => $fmt((float)$row['ha_pagato']),
                'contributi'      => $fmt((float)$row['contributi']),
                'saldo'           => $fmt((float)$row['saldo']),
                '_saldo_raw'      => (float)$row['saldo'],
                'dovuto_raw'      => (float)$row['dovuto'],
                'ha_pagato_raw'   => (float)$row['ha_pagato'],
                'contributi_raw'  => (float)$row['contributi'],
            ];
        }

        // Pannello "Da verificare" (tassi mancanti)
        $miss = $svc->missingRateRows($filter);
        $missing_rows = array_map(function($r){
            return [
                'data'        => $r['data'] ?: '',
                'descrizione' => $r['descrizione'],
                'categoria'   => $r['categoria'],
                'valuta'      => $r['valuta'],
                'amount'      => number_format((float)$r['amount_rif'], 2, ',', '’') . ' ' . $r['valuta'],
                'giorno_id'   => $r['giorno_id'],
            ];
        }, $miss);

        $this->view('rendiconto/index.twig', [
            'title'   => 'Rendiconto spese',
            'meta'    => [
                'title'       => 'Rendiconto spese',
                'description' => 'Totali, categorie e saldi per partecipante.',
                'url'         => ($_ENV['APP_URL_BASE'] ?? '') . '/rendiconto',
            ],
            'totals'            => $totalsFmt,
            'missing_currencies'=> $totals['missing'] ?: $cats['missing'] ?? [],
            'by_category'       => $catsFmt,
            'by_category_raw'   => $cats['values'],
            'participants'      => $participants,
            'unassigned_fmt'    => $fmt((float)$parts['unassigned']),
            'fx_codes'          => $fxCodes,
            'cat_index'         => $catIndex['slug_to_label'],
            'cat_map'           => $catIndex['label_to_slug'],
            'filter'            => ['from'=>$from,'to'=>$to,'cur'=>$cur,'cat'=>$catSlug],
            'missing_rows'      => $missing_rows,
        ]);
    }

    /** /rendiconto/categoria/{slug} */
    public function categoria(string $slug): void
    {
        $svc = $this->service();
        $data = $svc->listByCategorySlug($slug);
        if (!$data['label']) { http_response_code(404); echo "Categoria non trovata."; return; }

        $fmt = fn(float $v): string => 'CHF ' . number_format($v, 2, ',', '’');

        $rows = array_map(function($r) use ($fmt){
            return [
                'data'        => $r['data'],
                'descrizione' => $r['descrizione'],
                'valuta'      => $r['valuta'],
                'amount'      => number_format((float)$r['amount_rif'], 2, ',', '’'),
                'amount_chf'  => $fmt((float)$r['amount_chf']),
                'giorno_id'   => $r['giorno_id'],
                'pagato_da'   => $r['pagato_da'],
                'diviso_per'  => $r['diviso_per'],
            ];
        }, $data['rows']);

        // Pannello "da verificare" per questa categoria
        $missing_rows = $svc->missingRateRows(['categories'=>[$data['label']]]);
        $missing_rows_fmt = array_map(function($r){
            return [
                'data'        => $r['data'] ?: '',
                'descrizione' => $r['descrizione'],
                'categoria'   => $r['categoria'],
                'valuta'      => $r['valuta'],
                'amount'      => number_format((float)$r['amount_rif'], 2, ',', '’') . ' ' . $r['valuta'],
                'giorno_id'   => $r['giorno_id'],
            ];
        }, $missing_rows);

        $this->view('rendiconto/categoria.twig', [
            'title'   => 'Categoria: ' . $data['label'],
            'meta'    => [
                'title'       => 'Rendiconto – ' . $data['label'],
                'description' => 'Spese per categoria.',
                'url'         => ($_ENV['APP_URL_BASE'] ?? '') . '/rendiconto/categoria/' . $slug,
            ],
            'label'        => $data['label'],
            'rows'         => $rows,
            'total'        => $fmt((float)$data['total']),
            'missing'      => $data['missing'],
            'missing_rows' => $missing_rows_fmt,
        ]);
    }

    /** /rendiconto/partecipante/{slug} */
    public function partecipante(string $slug): void
    {
        $svc = $this->service();
        $led = $svc->ledgerByParticipantSlug($slug);

        $fmt = fn(float $v): string => 'CHF ' . number_format($v, 2, ',', '’');

        $mapRows = function(array $rows, string $key) use ($fmt){
            return array_map(function($r) use ($fmt, $key){
                $amount = $key === 'dovuti' ? ($r['quota_chf'] ?? 0) : ($r['importo_chf'] ?? 0);
                return [
                    'data'   => $r['data'] ?? '',
                    'desc'   => $r['descrizione'] ?? '',
                    'cat'    => $r['categoria'] ?? '',
                    'giorno' => !empty($r['giorno_id']) ? (int)$r['giorno_id'] : null,
                    'importo'=> $fmt((float)$amount),
                ];
            }, $rows);
        };

        $this->view('rendiconto/partecipante.twig', [
            'title'   => 'Partecipante: ' . $led['name'],
            'meta'    => [
                'title'       => 'Rendiconto – ' . $led['name'],
                'description' => 'Estratto conto personale.',
                'url'         => ($_ENV['APP_URL_BASE'] ?? '') . '/rendiconto/partecipante/' . $slug,
            ],
            'name'        => $led['name'],
            'dovuti'      => $mapRows($led['dovuti'], 'dovuti'),
            'pagati'      => $mapRows($led['ha_pagato'], 'pagati'),
            'contrib'     => array_map(function($r){
                return [
                    'data'    => $r['data'] ?? '',
                    'importo' => 'CHF ' . number_format((float)($r['importo_chf'] ?? 0), 2, ',', '’'),
                    'nota'    => $r['note'] ?? '',
                ];
            }, $led['contributi']),
            'tot_dovuti'  => $fmt((float)$led['tot_dovuti']),
            'tot_pagati'  => $fmt((float)$led['tot_pagati']),
            'tot_contrib' => $fmt((float)$led['tot_contrib']),
            'saldo'       => $fmt((float)$led['saldo']),
            '_saldo_raw'  => (float)$led['saldo'],
        ]);
    }

    /** /rendiconto.csv — export generale (supporta ?locale=en) */
    public function exportCsv(): void
    {
        $svc = $this->service();

        // --- filtri come index() ---
        $from = $_GET['from'] ?? null;
        $to   = $_GET['to']   ?? null;
        $cur  = strtoupper(trim($_GET['cur'] ?? ''));
        $catSlug = $_GET['cat'] ?? '';

        $isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $from = $isDate($from) ? $from : null;
        $to   = $isDate($to)   ? $to   : null;

        $catIndex = $svc->categoriesIndex();
        $catLabel = $catSlug && isset($catIndex['slug_to_label'][$catSlug])
            ? $catIndex['slug_to_label'][$catSlug]
            : null;

        $fxCodes = (new Fx(\DB::pdo()))->knownCodes();
        $filter = [
            'from'       => $from,
            'to'         => $to,
            'currency'   => ($cur && in_array($cur, $fxCodes, true)) ? $cur : null,
            'categories' => $catLabel ? [$catLabel] : [],
        ];

        // dati
        $totals = $svc->totals($filter);
        $cats   = $svc->byCategory($filter);
        $parts  = $svc->participants($filter);

        $fn = 'rendiconto.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8

        $out = fopen('php://output', 'w');

        // Profilo CSV: locale=en => decimale ".", separatore ","
        // default (IT) => decimale ",", separatore ";"
        $useEn = (($_GET['locale'] ?? '') === 'en');
        $sep   = $useEn ? ',' : ';';
        $N     = fn($v) => number_format((float)$v, 2, $useEn ? '.' : ',', '');

        // Filtri
        fputcsv($out, ['Rendiconto (filtri)'], $sep);
        fputcsv($out, ['Da', $from ?: ''], $sep);
        fputcsv($out, ['A',  $to   ?: ''], $sep);
        fputcsv($out, ['Valuta', $filter['currency'] ?: 'tutte'], $sep);
        fputcsv($out, ['Categoria', $catLabel ?: 'tutte'], $sep);
        fputcsv($out, [''], $sep);

        // Totali
        fputcsv($out, ['SEZIONE','Totali'], $sep);
        fputcsv($out, ['Voce','CHF'], $sep);
        fputcsv($out, ['Stimato',    $N($totals['values']['stimato'] ?? 0)], $sep);
        fputcsv($out, ['Preventivo', $N($totals['values']['preventivo'] ?? 0)], $sep);
        fputcsv($out, ['Reale',      $N($totals['values']['reale'] ?? 0)], $sep);
        fputcsv($out, [''], $sep);

        // Per categoria
        fputcsv($out, ['SEZIONE','Per categoria'], $sep);
        fputcsv($out, ['Categoria','CHF'], $sep);
        foreach ($cats['values'] as $label => $val) {
            fputcsv($out, [$label, $N($val)], $sep);
        }
        fputcsv($out, [''], $sep);

        // Per partecipante
        fputcsv($out, ['SEZIONE','Per partecipante'], $sep);
        fputcsv($out, ['Partecipante','Dovuto (CHF)','Ha pagato (CHF)','Contributi (CHF)','Saldo (CHF)'], $sep);
        foreach ($parts['rows'] as $name => $row) {
            fputcsv($out, [
                $name,
                $N($row['dovuto']      ?? 0),
                $N($row['ha_pagato']   ?? 0),
                $N($row['contributi']  ?? 0),
                $N($row['saldo']       ?? 0),
            ], $sep);
        }
        fputcsv($out, [''], $sep);
        fputcsv($out, ['Totale non ripartito (CHF)', $N($parts['unassigned'] ?? 0)], $sep);

        fclose($out);
    }

    /** /rendiconto/categoria/{slug}.csv — export dettaglio categoria (supporta ?locale=en) */
    public function exportCategoriaCsv(string $slug): void
    {
        $svc  = $this->service();
        $data = $svc->listByCategorySlug($slug);
        if (!$data['label']) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Categoria non trovata";
            return;
        }

        $fn = 'categoria_' . BudgetService::slugify($data['label']) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8

        $out = fopen('php://output', 'w');

        $useEn = (($_GET['locale'] ?? '') === 'en');
        $sep   = $useEn ? ',' : ';';
        $N     = fn($v) => number_format((float)$v, 2, $useEn ? '.' : ',', '');

        // intestazioni "meta"
        fputcsv($out, ['Categoria', $data['label']], $sep);
        fputcsv($out, ['Totale CHF', $N($data['total'])], $sep);
        fputcsv($out, [''], $sep);

        // header tabella
        fputcsv($out, ['Data','Descrizione','Valuta','Importo (orig.)','CHF','Giorno','Pagato da','Diviso per'], $sep);

        foreach ($data['rows'] as $r) {
            fputcsv($out, [
                $r['data'] ?: '',
                $r['descrizione'],
                $r['valuta'],
                $N($r['amount_rif']),
                $N($r['amount_chf']),
                $r['giorno_id'] ?: '',
                $r['pagato_da'] ?: '',
                $r['diviso_per'] ?: '',
            ], $sep);
        }
        fclose($out);
    }

    /** /rendiconto/partecipante/{slug}.csv — export estratto personale (supporta ?locale=en) */
    public function exportPartecipanteCsv(string $slug): void
    {
        $svc = $this->service();
        $led = $svc->ledgerByParticipantSlug($slug);

        $fn = 'partecipante_' . BudgetService::slugify($led['name'] ?: $slug) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8

        $out = fopen('php://output', 'w');

        $useEn = (($_GET['locale'] ?? '') === 'en');
        $sep   = $useEn ? ',' : ';';
        $N     = fn($v) => number_format((float)$v, 2, $useEn ? '.' : ',', '');

        // intestazioni "meta"
        fputcsv($out, ['Partecipante', $led['name']], $sep);
        fputcsv($out, ['Totale dovuto (CHF)',      $N($led['tot_dovuti'])],  $sep);
        fputcsv($out, ['Totale contributi (CHF)',  $N($led['tot_contrib'])], $sep);
        fputcsv($out, ['Totale spese intestate',   $N($led['tot_pagati'])],  $sep);
        fputcsv($out, ['Saldo (CHF)',              $N($led['saldo'])],       $sep);
        fputcsv($out, [''], $sep);

        // Sezione 1: Quote dovute
        fputcsv($out, ['SEZIONE', 'Quote dovute'], $sep);
        fputcsv($out, ['Data','Descrizione','Categoria','Giorno','Quota (CHF)'], $sep);
        foreach ($led['dovuti'] as $r) {
            fputcsv($out, [
                $r['data'] ?: '',
                $r['descrizione'] ?? '',
                $r['categoria'] ?? '',
                $r['giorno_id'] ?: '',
                $N($r['quota_chf'] ?? 0),
            ], $sep);
        }
        fputcsv($out, [''], $sep);

        // Sezione 2: Contributi
        fputcsv($out, ['SEZIONE', 'Contributi'], $sep);
        fputcsv($out, ['Data','Importo (CHF)','Note'], $sep);
        foreach ($led['contributi'] as $r) {
            fputcsv($out, [
                $r['data'] ?: '',
                $N($r['importo_chf'] ?? 0),
                $r['note'] ?? '',
            ], $sep);
        }
        fputcsv($out, [''], $sep);

        // Sezione 3: Spese intestate
        fputcsv($out, ['SEZIONE', 'Spese intestate'], $sep);
        fputcsv($out, ['Data','Descrizione','Categoria','Giorno','Importo (CHF)'], $sep);
        foreach ($led['ha_pagato'] as $r) {
            fputcsv($out, [
                $r['data'] ?: '',
                $r['descrizione'] ?? '',
                $r['categoria'] ?? '',
                $r['giorno_id'] ?: '',
                $N($r['importo_chf'] ?? 0),
            ], $sep);
        }

        fclose($out);
    }
}
