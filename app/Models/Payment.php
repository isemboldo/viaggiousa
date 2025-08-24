<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Payment
{
    private PDO $db;
    private Fx $fx;

    public function __construct(?PDO $pdo = null, ?Fx $fx = null)
    {
        $this->db = $pdo ?: \DB::pdo();
        $this->fx = $fx ?: new Fx($this->db);
    }

    /**
     * Tabella `pagamenti` (dump): id, partecipante, importo, valuta, data_pagamento, note
     * Convertiamo importo -> CHF usando tassi_cambio.
     */
    public function all(): array
    {
        try {
            $st = $this->db->query("SELECT id, partecipante, importo, valuta, data_pagamento, note FROM pagamenti");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $name = \App\Models\Expense::normalizeName((string)$r['partecipante']);
            $amount = (float)$r['importo'];
            $val = \App\Models\Fx::normalizeCode($r['valuta'] ?? 'CHF');
            $rate = $this->fx->rateToChf($val);
            $chf = $rate > 0 ? $amount * $rate : null;

            $out[] = [
                'id'           => (int)$r['id'],
                'partecipante' => $name,
                'importo'      => $amount,
                'valuta'       => $val,
                'importo_chf'  => $chf,
                'data'         => (string)$r['data_pagamento'],
                'note'         => (string)($r['note'] ?? ''),
                'rate'         => $rate,
            ];
        }
        return $out;
    }

    /** Somma contributi (in CHF, scartando righe senza tasso) */
    public function sumByParticipant(): array
    {
        $sum = [];
        foreach ($this->all() as $p) {
            if ($p['importo_chf'] === null) continue;
            $sum[$p['partecipante']] = ($sum[$p['partecipante']] ?? 0.0) + (float)$p['importo_chf'];
        }
        return $sum;
    }
}
