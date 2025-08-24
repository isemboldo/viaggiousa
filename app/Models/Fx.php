<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Fx
{
    private PDO $db;

    /** Cache in-request (per tutte le istanze) */
    private static ?array $ratesMemory = null;
    private static ?int   $memoryLoadedAt = null;

    /** File cache */
    private const CACHE_FILE = 'storage/cache/fx_rates.json';
    private const DEFAULT_TTL = 21600; // 6h

    public function __construct(?PDO $pdo = null)
    {
        $this->db = $pdo ?: \DB::pdo();
    }

    /** Normalizza codici valuta “strani” in ISO 3 (USD/EUR/CHF) */
    public static function normalizeCode(?string $raw): string
    {
        $c = strtoupper(trim((string)$raw));

        // rimpiazzi simboli e testi comuni
        $c = str_replace(['US$', '$'], 'USD', $c);
        $c = str_replace(['€', 'EURO'], 'EUR', $c);
        // varianti franco svizzero
        $c = str_replace(['SFR', 'SFR.', 'FR.', 'FRANCO', 'FRANCI'], 'CHF', $c);

        // lascia solo lettere (evita "USD " o "USD/CHF")
        $c = preg_replace('/[^A-Z]/', '', $c) ?? $c;

        if ($c === '') $c = 'CHF';
        return $c;
    }

    /** Codici noti (incluso CHF) */
    public function knownCodes(): array
    {
        $rates = $this->getRates();
        $codes = array_keys($rates);
        sort($codes, SORT_NATURAL);
        if (!in_array('CHF', $codes, true)) array_unshift($codes, 'CHF');
        return array_values(array_unique($codes));
    }

    /** Ritorna tasso verso CHF (CHF=1.0). 0.0 se sconosciuto */
    public function rateToChf(string $code): float
    {
        $c = self::normalizeCode($code);
        if ($c === 'CHF') return 1.0;
        $rates = $this->getRates();
        return isset($rates[$c]) ? (float)$rates[$c] : 0.0;
    }

    /** Carica tassi (memoria -> file -> DB) */
    private function getRates(): array
    {
        // 1) Cache in-request
        if (is_array(self::$ratesMemory)) {
            return self::$ratesMemory;
        }

        // 2) Cache file (se abilitata e valida)
        $useFile = ($_ENV['FX_CACHE_ENABLED'] ?? 'true') !== 'false';
        $ttl     = (int)($_ENV['FX_CACHE_TTL'] ?? self::DEFAULT_TTL);
        $cachePath = $this->cachePath();

        if ($useFile && $ttl > 0 && is_file($cachePath)) {
            $json = @file_get_contents($cachePath);
            if ($json !== false) {
                $obj = json_decode($json, true);
                if (is_array($obj) && isset($obj['ts'], $obj['rates']) && (time() - (int)$obj['ts'] <= $ttl)) {
                    $rates = (array)$obj['rates'];
                    // normalizza chiavi della cache
                    $norm = ['CHF'=>1.0];
                    foreach ($rates as $k=>$v) {
                        $norm[self::normalizeCode($k)] = (float)$v;
                    }
                    self::$ratesMemory = $norm;
                    self::$memoryLoadedAt = time();
                    return self::$ratesMemory;
                }
            }
        }

        // 3) DB
        $rates = $this->loadRatesFromDb();

        // scrivi file cache se possibile
        if ($useFile) {
            $this->ensureCacheDir();
            @file_put_contents($cachePath, json_encode(['ts'=>time(), 'rates'=>$rates], JSON_UNESCAPED_UNICODE));
        }

        self::$ratesMemory = $rates;
        self::$memoryLoadedAt = time();
        return self::$ratesMemory;
    }

    /** Tenta di leggere i tassi da DB con autodetect colonne e normalizzazione */
    private function loadRatesFromDb(): array
    {
        $rates = ['CHF' => 1.0];

        try {
            $st = $this->db->query("SELECT * FROM tassi_cambio");
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            if (!$rows) return $rates;

            // autodetect colonne possibili
            $first = $rows[0];
            $codeKey = $this->firstExistingKey($first, ['valuta','codice','code','currency','sigla','iso']);
            $rateKey = $this->firstExistingKey(
    $first,
    ['tasso_a_chf','tasso_chf','tassochf','tasso','rate','valore','coeff','ratio','to_chf','exchange_to_chf']
);


            // fallback rigido se non trovate
            if (!$codeKey || !$rateKey) {
                // prova SELECT esplicita sulle colonne più comuni
                $try = [
    "SELECT valuta AS code, tasso_a_chf AS rate FROM tassi_cambio",
    "SELECT valuta AS code, tasso_chf AS rate FROM tassi_cambio",
    "SELECT codice AS code, tasso_chf AS rate FROM tassi_cambio",
    "SELECT valuta AS code, tasso AS rate FROM tassi_cambio",
    "SELECT code AS code, rate AS rate FROM tassi_cambio",
];

                foreach ($try as $sql) {
                    try {
                        $st2 = $this->db->query($sql);
                        $rows2 = $st2 ? $st2->fetchAll(PDO::FETCH_ASSOC) : [];
                        if ($rows2) {
                            foreach ($rows2 as $r2) {
                                $code = self::normalizeCode($r2['code'] ?? '');
                                $rate = (float)($r2['rate'] ?? 0);
                                if ($code && $rate > 0) {
                                    if ($code === 'CHF') { $rates['CHF'] = 1.0; }
                                    else { $rates[$code] = $rate; }
                                }
                            }
                            return $rates;
                        }
                    } catch (\Throwable $e) { /* continua */ }
                }
                // se ancora nulla, ritorna solo CHF
                return $rates;
            }

            // uso autodetect
            foreach ($rows as $r) {
                $code = self::normalizeCode($r[$codeKey] ?? '');
                $rate = (float)($r[$rateKey] ?? 0);
                if ($code === '' || $rate <= 0) continue;
                if ($code === 'CHF') { $rates['CHF'] = 1.0; continue; }
                $rates[$code] = $rate;
            }
        } catch (\Throwable $e) {
            // ignora: restituisci solo CHF
        }
        return $rates;
    }

    private function firstExistingKey(array $row, array $candidates): ?string
    {
        foreach ($candidates as $k) {
            if (array_key_exists($k, $row)) return $k;
        }
        return null;
    }

    private function cachePath(): string
    {
        $root = dirname(__DIR__, 2);
        return $root . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    private function ensureCacheDir(): void
    {
        $path = $this->cachePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
}
