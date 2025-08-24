<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPerfIndexes extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('spese')) {
            $t = $this->table('spese');
            // Nota: su MariaDB/utf8mb4 potresti preferire prefix index se la colonna è lunga.
            if (!$t->hasIndex(['categoria']))   $t->addIndex(['categoria'],   ['name'=>'idx_spese_categoria'])->update();
            if (!$t->hasIndex(['giorno_id']))   $t->addIndex(['giorno_id'],   ['name'=>'idx_spese_giorno'])->update();
            if (!$t->hasIndex(['data_spesa']))  $t->addIndex(['data_spesa'],  ['name'=>'idx_spese_data'])->update();
        }

        if ($this->hasTable('pagamenti')) {
            $t = $this->table('pagamenti');
            if (!$t->hasIndex(['partecipante']))    $t->addIndex(['partecipante'],   ['name'=>'idx_paga_partecipante'])->update();
            if (!$t->hasIndex(['data_pagamento']))  $t->addIndex(['data_pagamento'], ['name'=>'idx_paga_data'])->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('spese')) {
            $t = $this->table('spese');
            if ($t->hasIndexByName('idx_spese_categoria')) $t->removeIndexByName('idx_spese_categoria')->update();
            if ($t->hasIndexByName('idx_spese_giorno'))    $t->removeIndexByName('idx_spese_giorno')->update();
            if ($t->hasIndexByName('idx_spese_data'))      $t->removeIndexByName('idx_spese_data')->update();
        }
        if ($this->hasTable('pagamenti')) {
            $t = $this->table('pagamenti');
            if ($t->hasIndexByName('idx_paga_partecipante')) $t->removeIndexByName('idx_paga_partecipante')->update();
            if ($t->hasIndexByName('idx_paga_data'))         $t->removeIndexByName('idx_paga_data')->update();
        }
    }
}
