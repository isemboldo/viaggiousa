<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Fx
{
    private PDO $db;
    /** @var array<string,float> */
    private array $rates = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->db = $pdo ?: \DB::pdo();
        $this->load();
    }

    private function load(): void
    {
        $this->rates = ['CHF' => 1.0];
        try {
            $st = $this->db->query("SELECT valuta, tasso_a_chf FROM tassi_cambio");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = strtoupper(trim((string)$row['valuta']));
                $rate = (float)$row['tasso_a_chf'];
                if ($code !== '' && $rate > 0) {
                    $this->rates[$code] = $rate;
                }
            }
        } catch (\Throwable $e) {
            // nessuna tabella? fallback a CHF=1
        }
    }

    public function rateToChf(?string $code): float
    {
        $c = strtoupper(trim((string)($code ?: 'CHF')));
        return $this->rates[$c] ?? 0.0; // 0.0 → segnaleremo “tasso mancante”
    }

    /** debug/diagnostica */
    public function knownCodes(): array
    {
        return array_keys($this->rates);
    }
}
