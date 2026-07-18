<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Printing\ReceiptContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 18 S8 (F-14) — the app runs on Philippine business time, not UTC.
 * Receipts print the local sale time and report day-buckets follow the local
 * business day (a 23:30 sale belongs to that evening's date, an 00:30 sale to
 * the next day).
 */
class BusinessTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_timezone_is_philippine_business_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_receipts_print_the_local_sale_time(): void
    {
        Carbon::setTestNow('2026-03-10 23:30:00'); // Manila local

        $transaction = Transaction::factory()->create();

        $content = app(ReceiptContent::class)->forTransaction($transaction);

        $this->assertSame('2026-03-10 11:30 PM', $content['datetime']);
    }

    public function test_a_late_evening_sale_lands_on_its_local_business_day(): void
    {
        Carbon::setTestNow('2026-03-10 23:30:00');

        $variant = Variant::factory()->create(['selling_price' => 100]);
        app(StockLedger::class)->record(
            $variant, 5, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        $this->actingAs(User::factory()->cashier()->create())->post(route('pos.checkout'), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'payment_type' => 'cash',
            'cash_received' => 100,
        ])->assertSessionHasNoErrors();

        // The sale belongs to March 10 (bucket hour 23), not the next UTC day.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports', false)
                ->where('rows.0.bucket', '2026-03-10 23')
                ->where('rows.0.gross_sales', '100.00'));

        // And March 11's report is empty.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-11']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports', false)
                ->has('rows', 0));
    }
}
