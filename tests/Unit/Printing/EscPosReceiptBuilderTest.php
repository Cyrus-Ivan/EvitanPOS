<?php

namespace Tests\Unit\Printing;

use App\Support\Printing\EscPosReceiptBuilder;
use PHPUnit\Framework\TestCase;

class EscPosReceiptBuilderTest extends TestCase
{
    // ESC/POS control sequences the receipt relies on.
    private const INIT = "\x1B\x40";

    private const CUT = "\x1D\x56\x00";

    private const DRAWER_KICK = "\x1B\x70\x00\x19\xFA";

    private const BOLD_ON = "\x1B\x45\x01";

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sampleData(array $overrides = []): array
    {
        return array_merge([
            'paper_size' => '58mm',
            'is_reprint' => false,
            'business' => ['name' => 'Sample Store', 'address' => '1 Main St', 'tin' => '111-222'],
            'footer' => 'Thank you!',
            'number' => '0000000001',
            'datetime' => '2026-07-12 03:15 PM',
            'cashier' => 'Cashier One',
            'payment_label' => 'Cash',
            'comment' => null,
            'items' => [
                ['label' => 'Softdrink', 'quantity' => 2, 'unit_price' => '25.00', 'line_total' => '50.00'],
            ],
            'subtotal' => '50.00',
            'discount_display' => null,
            'discount_amount' => '0.00',
            'total' => '50.00',
            'cash_received' => '100.00',
            'change' => '50.00',
        ], $overrides);
    }

    public function test_it_emits_init_business_name_items_bold_total_and_a_cut(): void
    {
        $bytes = (new EscPosReceiptBuilder)->build($this->sampleData());

        $this->assertStringStartsWith(self::INIT, $bytes);
        $this->assertStringContainsString('Sample Store', $bytes);
        $this->assertStringContainsString('Softdrink', $bytes);
        $this->assertStringContainsString('2 x P25.00', $bytes);
        $this->assertStringContainsString('P50.00', $bytes);
        $this->assertStringContainsString('Thank you!', $bytes);
        $this->assertStringContainsString(self::BOLD_ON.'TOTAL', $bytes);
        $this->assertStringEndsWith(self::CUT, $bytes);
    }

    public function test_the_drawer_kick_is_present_only_when_requested(): void
    {
        $off = (new EscPosReceiptBuilder)->build($this->sampleData(), openDrawer: false);
        $on = (new EscPosReceiptBuilder)->build($this->sampleData(), openDrawer: true);

        $this->assertStringNotContainsString(self::DRAWER_KICK, $off);
        $this->assertStringContainsString(self::DRAWER_KICK, $on);
    }

    public function test_a_reprint_renders_the_reprint_banner(): void
    {
        $normal = (new EscPosReceiptBuilder)->build($this->sampleData(['is_reprint' => false]));
        $reprint = (new EscPosReceiptBuilder)->build($this->sampleData(['is_reprint' => true]));

        $this->assertStringNotContainsString('REPRINT', $normal);
        $this->assertStringContainsString('*** REPRINT ***', $reprint);
    }

    public function test_paper_width_drives_the_column_count(): void
    {
        $narrow = (new EscPosReceiptBuilder)->build($this->sampleData(['paper_size' => '58mm']));
        $wide = (new EscPosReceiptBuilder)->build($this->sampleData(['paper_size' => '80mm']));

        $this->assertStringContainsString(str_repeat('-', 32), $narrow);
        $this->assertStringNotContainsString(str_repeat('-', 48), $narrow);
        $this->assertStringContainsString(str_repeat('-', 48), $wide);
    }

    public function test_cash_lines_appear_only_for_a_cash_sale(): void
    {
        $cash = (new EscPosReceiptBuilder)->build($this->sampleData());
        $cashless = (new EscPosReceiptBuilder)->build($this->sampleData([
            'payment_label' => 'Cashless',
            'cash_received' => null,
            'change' => null,
        ]));

        $this->assertStringContainsString('Cash', $cash);
        $this->assertStringContainsString('Change', $cash);
        $this->assertStringNotContainsString('Change', $cashless);
    }

    public function test_a_discount_line_shows_for_both_percentage_and_amount(): void
    {
        $percentage = (new EscPosReceiptBuilder)->build($this->sampleData([
            'discount_display' => '10%',
            'discount_amount' => '5.00',
            'total' => '45.00',
        ]));
        $amount = (new EscPosReceiptBuilder)->build($this->sampleData([
            'discount_display' => null,
            'discount_amount' => '5.00',
            'total' => '45.00',
        ]));

        $this->assertStringContainsString('Discount (10%)', $percentage);
        $this->assertStringContainsString('-P5.00', $percentage);
        // Amount discounts still print, just without a parenthetical label.
        $this->assertStringContainsString('Discount', $amount);
        $this->assertStringContainsString('-P5.00', $amount);
    }
}
