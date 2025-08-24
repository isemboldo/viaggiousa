<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Expense
{
    private PDO $db;
    private Fx $fx;

    public function __construct(?PDO $pdo = null, ?Fx $fx = null)
    {
        $this->db = $pdo ?: \DB::pdo();
        $this->fx = $fx ?: new Fx($this->db);
    }

    public function all(): array
    {
        $sql = "SELECT id, giorno_id, descrizione, categoria, luogo_id,
                       importo_stimato, importo_preventivo, importo_reale,
                       valuta, data_spesa, pagato_da, diviso_per, metodo_pagamento, note
                FROM spese";
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $val = \App\Models\Fx::normalizeCode($r['valuta'] ?? 'CHF');
            $rate = $this->fx->rateToChf($val);

            $st = (float)$r['importo_stimato'];
            $pr = (float)$r['importo_preventivo'];
            $re = (float)$r['importo_reale'];

            $st_chf = $rate > 0 ? $st * $rate : null;
            $pr_chf = $rate > 0 ? $pr * $rate : null;
            $re_chf = $rate > 0 ? $re * $rate : null;

            // importo di riferimento (reale > preventivo > stimato)
            $rif = $re > 0 ? $re : ($pr > 0 ? $pr : $st);
            $rif_chf = $rate > 0 ? $rif * $rate : null;

            $result[] = [
                'id'          => (int)$r['id'],
                'giorno_id'   => $r['giorno_id'] ? (int)$r['giorno_id'] : null,
                'descrizione' => (string)$r['descrizione'],
                'categoria'   => (string)$r['categoria'],
                'valuta'      => $val,
                'rate'        => $rate,
                'amount_stimato'    => $st,
                'amount_preventivo' => $pr,
                'amount_reale'      => $re,
                'stimato_chf'       => $st_chf,
                'preventivo_chf'    => $pr_chf,
                'reale_chf'         => $re_chf,
                'amount_rif'        => $rif,
                'amount_chf'        => $rif_chf,
                'pagato_da'         => trim((string)$r['pagato_da']),
                'diviso_per'        => (string)$r['diviso_per'],
                'data_spesa'        => (string)$r['data_spesa'],
                'note'              => (string)($r['note'] ?? ''),
            ];
        }
        return $result;
    }

    public static function normalizeName(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/\s+/u', ' ', $s ?? '') ?? '';
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    /** Estrae e normalizza i partecipanti da "diviso_per" (CSV) */
    public static function splitParticipants(string $divisoPer): array
    {
        if (!trim($divisoPer)) return [];
        $parts = array_map('trim', explode(',', $divisoPer));
        $parts = array_filter($parts, static fn($x) => $x !== '');
        $parts = array_map([self::class, 'normalizeName'], $parts);
        $parts = array_values(array_unique($parts));
        return $parts;
    }
}
