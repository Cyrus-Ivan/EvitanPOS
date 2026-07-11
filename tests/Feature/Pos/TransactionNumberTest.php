<?php

namespace Tests\Feature\Pos;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionNumberTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => 100]);
        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    private function sell(Variant $variant, User $cashier): void
    {
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_numbers_embed_date_and_time_and_increment_daily()
    {
        $variant = $this->sellable(stock: 5);
        $cashier = User::factory()->cashier()->create();

        $this->sell($variant, $cashier);
        $this->sell($variant, $cashier);

        $numbers = Transaction::orderBy('created_at')->pluck('transaction_number')->all();

        // YYYYMMDD-HHMMSS-000000 with a daily sequence that increments.
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-\d{6}$/', $number);
        }
        $this->assertStringEndsWith('-000001', $numbers[0]);
        $this->assertStringEndsWith('-000002', $numbers[1]);
    }

    public function test_receipt_number_equals_transaction_number()
    {
        $variant = $this->sellable(stock: 5);
        $this->sell($variant, User::factory()->cashier()->create());

        $transaction = Transaction::sole();
        $this->assertSame($transaction->transaction_number, $transaction->receipt_number);
    }
}
