<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Support\Printing\PrinterSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'receipt_paper_size' => '80mm',
            'receipt_connection' => 'usb',
            'receipt_printer_name' => '',
            'receipt_device_path' => '/dev/usb/lp0',
            'receipt_network_host' => '',
            'receipt_network_port' => 9100,
            'auto_print' => true,
            'open_cash_drawer' => true,
        ], $overrides);
    }

    public function test_the_owner_can_save_printer_settings(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('printer-settings.update'), $this->payload([
                'receipt_paper_size' => '58mm',
                'receipt_device_path' => '/dev/rfcomm0',
                'receipt_connection' => 'bluetooth',
                'open_cash_drawer' => false,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = app(PrinterSettings::class);
        $this->assertSame('58mm', $settings->paperSize());
        $this->assertSame('bluetooth', $settings->connection());
        $this->assertSame('/dev/rfcomm0', $settings->devicePath());
        $this->assertTrue($settings->autoPrint());
        $this->assertFalse($settings->openCashDrawer());
    }

    public function test_a_cashier_may_not_change_printer_settings(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->patch(route('printer-settings.update'), $this->payload())
            ->assertForbidden();
    }

    public function test_an_admin_may_not_change_printer_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('printer-settings.update'), $this->payload())
            ->assertForbidden();
    }

    public function test_the_paper_size_is_validated(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('printer-settings.update'), $this->payload(['receipt_paper_size' => 'A4']))
            ->assertSessionHasErrors('receipt_paper_size');
    }

    public function test_a_network_printer_requires_a_host(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('printer-settings.update'), $this->payload([
                'receipt_connection' => 'network',
                'receipt_network_host' => '',
            ]))
            ->assertSessionHasErrors('receipt_network_host');
    }

    public function test_a_usb_printer_requires_a_device_path(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('printer-settings.update'), $this->payload([
                'receipt_connection' => 'usb',
                'receipt_device_path' => '',
            ]))
            ->assertSessionHasErrors('receipt_device_path');
    }

    public function test_a_system_printer_requires_a_printer_name(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('printer-settings.update'), $this->payload([
                'receipt_connection' => 'system',
                'receipt_printer_name' => '',
            ]))
            ->assertSessionHasErrors('receipt_printer_name');
    }

    public function test_the_owner_can_save_a_system_printer(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('printer-settings.update'), $this->payload([
                'receipt_connection' => 'system',
                'receipt_printer_name' => 'EPSON TM-T20',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = app(PrinterSettings::class);
        $this->assertSame('system', $settings->connection());
        $this->assertSame('EPSON TM-T20', $settings->printerName());
        $this->assertTrue($settings->isConfigured());
    }
}
