<?php

namespace Tests\Feature\Printing;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Printing\PrinterException;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptPrinter;
use App\Support\Settings\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReceiptPrinterTest extends TestCase
{
    use RefreshDatabase;

    private ?string $deviceFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(PrinterSettings::class)->update(['auto_print' => '0']);
    }

    protected function tearDown(): void
    {
        if ($this->deviceFile !== null && file_exists($this->deviceFile)) {
            @unlink($this->deviceFile);
        }

        parent::tearDown();
    }

    /**
     * Point the printer at a temp file that stands in for the device node.
     */
    private function useTempDevice(): string
    {
        $this->deviceFile = tempnam(sys_get_temp_dir(), 'rcpt');

        app(PrinterSettings::class)->update([
            'receipt_connection' => 'usb',
            'receipt_device_path' => $this->deviceFile,
        ]);

        return $this->deviceFile;
    }

    private function makeSale(float $price = 100, ?float $cost = 40): Transaction
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);
        app(StockLedger::class)->record(
            $variant, 10, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'cash',
                'cash_received' => 100000,
            ])->assertSessionHasNoErrors();

        return Transaction::latest('created_at')->firstOrFail();
    }

    public function test_it_writes_receipt_bytes_to_the_configured_device(): void
    {
        app(BusinessSettings::class)->update(['business_name' => 'Corner Store']);
        $device = $this->useTempDevice();
        $transaction = $this->makeSale(price: 100, cost: 40);

        $printed = app(ReceiptPrinter::class)->print($transaction);

        $this->assertTrue($printed);
        $bytes = file_get_contents($device);
        $this->assertStringContainsString('Corner Store', $bytes);
        $this->assertStringContainsString('P100.00', $bytes);
        // The cost (40.00) must never reach the receipt.
        $this->assertStringNotContainsString('40.00', $bytes);
    }

    public function test_a_reprint_marks_the_slip(): void
    {
        $device = $this->useTempDevice();
        $transaction = $this->makeSale();

        app(ReceiptPrinter::class)->print($transaction, isReprint: true);

        $this->assertStringContainsString('*** REPRINT ***', file_get_contents($device));
    }

    public function test_the_drawer_kick_is_written_when_enabled(): void
    {
        $device = $this->useTempDevice();
        app(PrinterSettings::class)->update(['open_cash_drawer' => '1']);
        $transaction = $this->makeSale();

        app(ReceiptPrinter::class)->print($transaction);

        $this->assertStringContainsString("\x1B\x70\x00\x19\xFA", file_get_contents($device));
    }

    public function test_it_returns_false_when_no_device_is_configured(): void
    {
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'usb',
            'receipt_device_path' => '',
        ]);
        $transaction = $this->makeSale();

        $this->assertFalse(app(ReceiptPrinter::class)->print($transaction));
    }

    public function test_a_missing_device_surfaces_a_printer_exception(): void
    {
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'usb',
            'receipt_device_path' => '/dev/does-not-exist-'.uniqid(),
        ]);
        $transaction = $this->makeSale();

        $this->expectException(PrinterException::class);
        app(ReceiptPrinter::class)->print($transaction);
    }

    public function test_an_unreachable_network_printer_surfaces_a_printer_exception(): void
    {
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'network',
            'receipt_network_host' => '127.0.0.1',
            'receipt_network_port' => '9', // discard port: nothing listening
        ]);
        $transaction = $this->makeSale();

        $this->expectException(PrinterException::class);
        app(ReceiptPrinter::class)->print($transaction);
    }

    public function test_a_system_printer_is_not_configured_without_a_name(): void
    {
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'system',
            'receipt_printer_name' => '',
        ]);
        $transaction = $this->makeSale();

        $this->assertFalse(app(ReceiptPrinter::class)->print($transaction));
    }

    public function test_a_system_printer_sends_bytes_to_the_os_queue(): void
    {
        Process::fake();
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'system',
            'receipt_printer_name' => 'Corner-Store-Printer',
        ]);
        $transaction = $this->makeSale();

        $this->assertTrue(app(ReceiptPrinter::class)->print($transaction));

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && in_array('Corner-Store-Printer', $process->command, true));
    }

    public function test_a_failing_system_printer_surfaces_a_printer_exception(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'offline')]);
        app(PrinterSettings::class)->update([
            'receipt_connection' => 'system',
            'receipt_printer_name' => 'Corner-Store-Printer',
        ]);
        $transaction = $this->makeSale();

        $this->expectException(PrinterException::class);
        app(ReceiptPrinter::class)->print($transaction);
    }
}
