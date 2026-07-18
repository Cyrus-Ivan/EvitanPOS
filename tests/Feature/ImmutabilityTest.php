<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryLedger;
use App\Models\LoginHistory;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 18 S8 (F-01) — every append-only table refuses updates and deletes at
 * BOTH layers: the Immutable model trait (Eloquent events) and the SQLite
 * triggers (bulk builder / raw SQL, which fire no model events).
 * audit_logs is covered by the pre-existing AuditLoggingTest.
 */
class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, class-string<Model>> */
    private static function models(): array
    {
        return [
            'transactions' => Transaction::class,
            'transaction_items' => TransactionItem::class,
            'inventory_ledger' => InventoryLedger::class,
            'sale_returns' => SaleReturn::class,
            'sale_return_items' => SaleReturnItem::class,
            'login_history' => LoginHistory::class,
        ];
    }

    public function test_model_layer_blocks_updates_on_every_append_only_model(): void
    {
        foreach (self::models() as $table => $class) {
            $row = $class::factory()->create();

            try {
                $row->forceFill(['created_at' => now()->addYear()])->save();
                $this->fail("{$table}: update was not blocked at the model layer");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage(), $table);
            }
        }
    }

    public function test_model_layer_blocks_deletes_on_every_append_only_model(): void
    {
        foreach (self::models() as $table => $class) {
            $row = $class::factory()->create();

            try {
                $row->delete();
                $this->fail("{$table}: delete was not blocked at the model layer");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage(), $table);
            }

            $this->assertDatabaseHas($table, ['id' => $row->id]);
        }
    }

    public function test_database_triggers_block_bulk_writes_that_skip_model_events(): void
    {
        foreach (self::models() as $table => $class) {
            $row = $class::factory()->create();

            try {
                DB::table($table)->where('id', $row->id)->update(['created_at' => now()->addYear()]);
                $this->fail("{$table}: bulk update was not blocked by the trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage(), $table);
            }

            try {
                DB::table($table)->where('id', $row->id)->delete();
                $this->fail("{$table}: bulk delete was not blocked by the trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage(), $table);
            }

            $this->assertDatabaseHas($table, ['id' => $row->id]);
        }
    }

    public function test_audit_logs_trigger_blocks_bulk_writes(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }
}
