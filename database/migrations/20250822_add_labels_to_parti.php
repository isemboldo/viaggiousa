<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddLabelsToParti extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('parti');

        if (!$table->hasColumn('etichetta')) {
            $table->addColumn('etichetta', 'string', ['limit' => 100, 'null' => true, 'after' => 'titolo']);
        }
        if (!$table->hasColumn('descrizione_breve')) {
            $table->addColumn('descrizione_breve', 'string', ['limit' => 255, 'null' => true, 'after' => 'etichetta']);
        }

        $table->update();
    }
}
