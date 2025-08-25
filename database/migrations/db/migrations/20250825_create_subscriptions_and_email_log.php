<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSubscriptionsAndEmailLog extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('iscrizioni')) {
            $this->table('iscrizioni')
                ->addColumn('email', 'string', ['limit'=>190, 'null'=>false])
                ->addColumn('status', 'string', ['limit'=>20, 'null'=>false, 'default'=>'pending']) // pending|confirmed|blocked
                ->addColumn('token', 'string', ['limit'=>64, 'null'=>false])
                ->addColumn('created_at', 'datetime', ['null'=>true])
                ->addColumn('confirmed_at', 'datetime', ['null'=>true])
                ->addColumn('unsubscribed_at', 'datetime', ['null'=>true])
                ->addColumn('last_digest_at', 'datetime', ['null'=>true])
                ->addIndex(['email'], ['unique'=>true, 'name'=>'ux_iscrizioni_email'])
                ->addIndex(['token'], ['unique'=>true, 'name'=>'ux_iscrizioni_token'])
                ->create();
        }
        if (!$this->hasTable('email_log')) {
            $this->table('email_log')
                ->addColumn('email', 'string', ['limit'=>190, 'null'=>false])
                ->addColumn('template', 'string', ['limit'=>40, 'null'=>false]) // confirm|welcome|digest|unsub
                ->addColumn('subject', 'string', ['limit'=>190, 'null'=>false])
                ->addColumn('ok', 'boolean', ['default'=>0])
                ->addColumn('error', 'text', ['null'=>true])
                ->addColumn('sent_at', 'datetime', ['null'=>true])
                ->addIndex(['email'], ['name'=>'idx_email_log_email'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('email_log')) $this->table('email_log')->drop()->save();
        if ($this->hasTable('iscrizioni')) $this->table('iscrizioni')->drop()->save();
    }
}
