<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIndexesCore extends AbstractMigration
{
    public function change(): void
    {
        $sezioni = $this->table('sezioni');
        if (!$sezioni->hasIndex(['giorno_id', 'ordine', 'id'], ['name' => 'idx_sezioni_giorno_ordine'])) {
            $sezioni->addIndex(['giorno_id', 'ordine', 'id'], ['name' => 'idx_sezioni_giorno_ordine'])->update();
        }

        $giorni = $this->table('giorni');
        if (!$giorni->hasIndex(['giorno_num'], ['name' => 'idx_giorni_num'])) {
            $giorni->addIndex(['giorno_num'], ['name' => 'idx_giorni_num'])->update();
        }
    }
}
