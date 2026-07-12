<?php

namespace Tests\Feature\Printing;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptContent;
use App\Support\Settings\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Don't let the checkout helper's auto-print reach a device in tests.
        app(PrinterSettings::class)->update(['auto_print' => '0']);
    }

    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    /**
     * @param  list<array{0: Variant, 1: int}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function sell(array $lines, array $overrides = []): Transaction
    {
        $payload = array_merge([
            'lines' => array_map(fn ($line) => ['variant_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payment_type' => 'cash',
            'cash_received' => 100000,
        ], $overrides);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), $payload)
            ->assertSessionHasNoErrors();

        return Transaction::latest('created_at')->firstOrFail();
    }

    public function test_it_carries_business_identity_number_items_and_totals(): void
    {
        app(BusinessSettings::class)->update([
            'business_name' => 'Aling Nena Store',
            'business_address' => '123 Rizal St',
            'tin' => '999-888-777',
            'receipt_footer' => 'Salamat po!',
        ]);

        $variant = $this->sellable(price: 100, cost: 40, stock: 10);
        $transaction = $this->sell([[$variant, 2]], ['cash_received' => 1000]);

        $data = app(ReceiptContent::class)->forTransaction($transaction);

        $this->assertSame('Aling Nena Store', $data['business']['name']);
        $this->assertSame('999-888-777', $data['business']['tin']);
        $this->assertSame('Salamat po!', $data['footer']);
        $this->assertSame($transaction->transaction_number, $data['number']);
        $this->assertSame('Cash', $data['payment_label']);
        $this->assertCount(1, $data['items']);
        $this->assertSame(2, $data['items'][0]['quantity']);
        $this->assertSame('200.00', $data['items'][0]['line_total']);
        $this->assertSame('200.00', $data['subtotal']);
        $this->assertSame('200.00', $data['total']);
        $this->assertSame('1000.00', $data['cash_received']);
        $this->assertSame('800.00', $data['change']);
    }

    public function test_it_never_exposes_item_cost(): void
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);
        $transaction = $this->sell([[$variant, 1]]);

        $data = app(ReceiptContent::class)->forTransaction($transaction);

        // No 'cost' key on any line, and the cost value never leaks into the array.
        foreach ($data['items'] as $line) {
            $this->assertArrayNotHasKey('cost', $line);
        }
        $this->assertStringNotContainsString('40.00', json_encode($data));
    }

    public function test_it_summarises_a_percentage_discount(): void
    {
        $variant = $this->sellable(price: 100, cost: null, stock: 10);
        $transaction = $this->sell([[$variant, 2]], [
            'discount' => 10,
            'discount_type' => 'percentage',
        ]);

        $data = app(ReceiptContent::class)->forTransaction($transaction);

        $this->assertSame('10%', $data['discount_display']);
        $this->assertSame('20.00', $data['discount_amount']);
        $this->assertSame('180.00', $data['total']);
    }

    public function test_a_reprint_is_flagged(): void
    {
        $variant = $this->sellable(price: 50, cost: null, stock: 3);
        $transaction = $this->sell([[$variant, 1]]);

        $this->assertFalse(app(ReceiptContent::class)->forTransaction($transaction)['is_reprint']);
        $this->assertTrue(app(ReceiptContent::class)->forTransaction($transaction, isReprint: true)['is_reprint']);
    }
}
