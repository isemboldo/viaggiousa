<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Fx;
use App\Services\BudgetService;

final class RendicontoController extends BaseController
{
    private function service(): BudgetService {
        $pdo = \DB::pdo();
        return new BudgetService(
            new Expense($pdo, new Fx($pdo)),
            new Payment($pdo, new Fx($pdo)),
            new Fx($pdo)
        );
    }

    public function index(): void
{
    $svc    = $this->service();

    // --- Lettura filtri da querystring ---
    $from = $_GET['from'] ?? null;
    $to   = $_GET['to']   ?? null;
    $cur  = strtoupper(trim($_GET['cur'] ?? '')); // '' = tutte
    $catSlug = $_GET['cat'] ?? '';

    // sanifica date (YYYY-MM-DD)
    $isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    $from = $isDate($from) ? $from : null;
    $to   = $isDate($to)   ? $to   : null;

    // mappa categorie
    $catIndex = $svc->categoriesIndex(); // ['slug_to_label'=>..., 'label_to_slug'=>...]
    $catLabel = $catSlug && isset($catIndex['slug_to_label'][$catSlug])
        ? $catIndex['slug_to_label'][$catSlug]
        : null;

    // codici valuta disponibili
    $fxCodes = (new \App\Models\Fx(\DB::pdo()))->knownCodes();

    // filtro che applichiamo a tutti i calcoli
    $filter = [
        'from'       => $from,
        'to'         => $to,
        'currency'   => ($cur && in_array($cur, $fxCodes, true)) ? $cur : null,
        'categories' => $catLabel ? [$catLabel] : [], // opzionale
    ];

    // --- Calcoli ---
    $totals = $svc->totals($filter);
    $cats   = $svc->byCategory($filter);
    $parts  = $svc->participants($filter);

    $fmt = fn(float $v): string => 'CHF ' . number_format($v, 2, ',', '’');

    // categorie: sia formattato che “raw” per sort client
    $catsFmt = [];
    foreach ($cats['values'] as $k => $v) { $catsFmt[$k] = $fmt((float)$v); }

    // totali (3 card)
    $totalsFmt = [
        'stimato'    => $fmt((float)$totals['values']['stimato']),
        'preventivo' => $fmt((float)$totals['values']['preventivo']),
        'reale'      => $fmt((float)$totals['values']['reale']),
    ];

    // partecipanti: includo raw per sort client
    $participants = [];
    foreach ($parts['rows'] as $name => $row) {
        $participants[] = [
            'name'            => $name,
            'name_slug'       => \App\Services\BudgetService::slugify($name),
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

    $this->view('rendiconto/index.twig', [
        'title'   => 'Rendiconto spese',
        'meta'    => [
            'title'       => 'Rendiconto spese',
            'description' => 'Totali, categorie e saldi per partecipante.',
            'url'         => ($_ENV['APP_URL_BASE'] ?? '') . '/rendiconto',
        ],

        // dati
        'totals'            => $totalsFmt,
        'by_category'       => $catsFmt,
        'by_category_raw'   => $cats['values'], // numerici per sort
        'participants'      => $participants,

        // filtri & sorgenti per form
        'fx_codes'          => $fxCodes,
        'cat_index'         => $catIndex['slug_to_label'], // slug => label
        'cat_map'           => $catIndex['label_to_slug'], // label => slug
        'filter'            => [
            'from' => $from, 'to' => $to, 'cur' => $cur, 'cat' => $catSlug
        ],

        // diagnostica/messaggi
        'missing_currencies'=> $totals['missing'] ?: $cats['missing'] ?? [],
        'unassigned_fmt'    => $fmt((float)$parts['unassigned']),
    ]);
}


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
                'amount'      => number_format((float)$r['amount_rif'], 2, ',', '’') . ' ' . $r['valuta'],
                'amount_chf'  => $fmt((float)$r['amount_chf']),
                'giorno_id'   => $r['giorno_id'],
                'pagato_da'   => $r['pagato_da'],
                'diviso_per'  => $r['diviso_per'],
            ];
        }, $data['rows']);

        $this->view('rendiconto/categoria.twig', [
            'title'   => 'Categoria: ' . $data['label'],
            'meta'    => [
                'title'       => 'Rendiconto – ' . $data['label'],
                'description' => 'Spese per categoria.',
                'url'         => ($_ENV['APP_URL_BASE'] ?? '') . '/rendiconto/categoria/' . $slug,
            ],
            'label'   => $data['label'],
            'rows'    => $rows,
            'total'   => $fmt((float)$data['total']),
            'missing' => $data['missing'],
        ]);
    }

    public function partecipante(string $slug): void
    {
        $svc = $this->service();
        $led = $svc->ledgerByParticipantSlug($slug);

        $fmt = fn(float $v): string => 'CHF ' . number_format($v, 2, ',', '’');

        $mapRows = function(array $rows, string $key) use ($fmt){
            return array_map(function($r) use ($fmt, $key){
                $amount = $key === 'dovuti' ? $r['quota_chf'] : $r['importo_chf'];
                return [
                    'data'   => $r['data'],
                    'desc'   => $r['descrizione'] ?? '',
                    'cat'    => $r['categoria'] ?? '',
                    'giorno' => $r['giorno_id'] ? (int)$r['giorno_id'] : null,
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
            'name'    => $led['name'],
            'dovuti'  => $mapRows($led['dovuti'], 'dovuti'),
            'pagati'  => $mapRows($led['ha_pagato'], 'pagati'),
            'contrib' => array_map(function($r) use ($fmt){
                return [
                    'data'    => $r['data'],
                    'importo' => $fmt((float)$r['importo_chf']),
                    'nota'    => $r['note'] ?? '',
                ];
            }, $led['contributi']),
            'tot_dovuti'  => $fmt((float)$led['tot_dovuti']),
            'tot_pagati'  => $fmt((float)$led['tot_pagati']),
            'tot_contrib' => $fmt((float)$led['tot_contrib']),
            'saldo'       => $fmt((float)$led['saldo']),
            '_saldo_raw'  => $led['saldo'],
        ]);
    }

    /** Export JSON dashboard */
    public function exportJson(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $svc = $this->service();
        $data = [
            'totals'      => $svc->totals(),
            'by_category' => $svc->byCategory(),
            'participants'=> $svc->participants(),
        ];
        echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    /** Export CSV semplice (categorie e partecipanti) */
    public function exportCsv(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rendiconto.csv"');

        $svc = $this->service();
        $cats = $svc->byCategory()['values'];
        $parts = $svc->participants()['rows'];

        $out = fopen('php://output', 'w');
        // intestazione
        fputcsv($out, ['Sezione','Chiave','Valore'], ';');

        fputcsv($out, ['Categorie','',''], ';');
        foreach ($cats as $k=>$v) {
            fputcsv($out, ['Categoria', $k, number_format((float)$v, 2, ',', '’')], ';');
        }

        fputcsv($out, ['Partecipanti','',''], ';');
        foreach ($parts as $name => $row) {
            fputcsv($out, ['Partecipante', $name, 'Dovuto: '.number_format($row['dovuto'], 2, ',', '’')], ';');
            fputcsv($out, ['Partecipante', $name, 'Contributi: '.number_format($row['contributi'], 2, ',', '’')], ';');
            fputcsv($out, ['Partecipante', $name, 'Saldo: '.number_format($row['saldo'], 2, ',', '’')], ';');
        }
        fclose($out);
    }
}
