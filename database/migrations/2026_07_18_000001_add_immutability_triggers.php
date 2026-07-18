<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Append-only tables (CLAUDE.md — immutable business history). The
     * Immutable model trait guards the Eloquent paths, but model events do not
     * fire for bulk builder writes or raw SQL; these BEFORE UPDATE / DELETE
     * triggers are the database-level backstop nothing can bypass.
     *
     * Backup/restore is a whole-file copy, so the triggers never interfere
     * with it; DROP TABLE (migrate:fresh) drops them alongside the table.
     *
     * @var list<string>
     */
    private const TABLES = [
        'transactions',
        'transaction_items',
        'inventory_ledger',
        'sale_returns',
        'sale_return_items',
        'login_history',
        'audit_logs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_immutable_update
                BEFORE UPDATE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} rows are immutable');
                END
                SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_immutable_delete
                BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} rows are immutable');
                END
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete");
        }
    }
};
