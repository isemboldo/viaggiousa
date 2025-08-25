<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Updates
{
    public function __construct(private PDO $db) {}

    /**
     * Giorni modificati dopo $since (Y-m-d H:i:s), con dati parte per link hub.
     */
    public function daysSince(?string $since): array
    {
        $sql = "
        SELECT g.id AS giorno_id, g.titolo AS giorno_titolo, g.riassunto, g.data_modifica,
               p.slug AS parte_slug, p.titolo AS parte_titolo
        FROM giorni g
        LEFT JOIN parti p ON p.id = g.parte_id
        WHERE (:since IS NULL) OR (g.data_modifica > :since)
        ORDER BY g.data_modifica DESC, g.id DESC
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':since'=>$since]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
