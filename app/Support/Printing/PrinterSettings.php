<?php

namespace App\Support\Printing;

use App\Models\Setting;
use App\Support\Settings\BusinessSettings;
use Illuminate\Support\Facades\DB;

/**
 * Read/write accessor for the receipt-printer settings (Phase 11). Like
 * {@see BusinessSettings} these live as individual
 * key/value rows in the settings table; this class is the single place that
 * knows the printer key names and their defaults.
 *
 * Kept separate from BusinessSettings on purpose: business identity (name,
 * address, TIN, footer) is one concern; the physical output device (paper
 * width, connection, device path, auto-print, cash drawer) is another.
 *
 * The target hardware is a driverless ESC/POS thermal printer reached by
 * writing raw bytes to a device (USB `/dev/usb/lp0`, Bluetooth `/dev/rfcomm0`)
 * or a network socket — see {@see ReceiptPrinter}.
 */
class PrinterSettings
{
    public const CONNECTION_USB = 'usb';

    public const CONNECTION_BLUETOOTH = 'bluetooth';

    public const CONNECTION_NETWORK = 'network';

    public const CONNECTIONS = [
        self::CONNECTION_USB,
        self::CONNECTION_BLUETOOTH,
        self::CONNECTION_NETWORK,
    ];

    public const PAPER_SIZES = ['58mm', '80mm'];

    /**
     * Every recognised key mapped to its default. Booleans are stored as the
     * strings '1'/'0' to match the string-valued settings table.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'receipt_paper_size' => '58mm',
        'receipt_connection' => self::CONNECTION_USB,
        'receipt_device_path' => '/dev/usb/lp0',
        'receipt_network_host' => '',
        'receipt_network_port' => '9100',
        'auto_print' => '1',
        'open_cash_drawer' => '0',
    ];

    /**
     * Every printer setting as a key => string map, falling back to the default
     * for any key that has not been stored yet.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = Setting::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');

        return collect(self::DEFAULTS)
            ->mapWithKeys(fn (string $default, string $key): array => [
                $key => (string) ($stored[$key] ?? $default),
            ])
            ->all();
    }

    /**
     * Persist the given printer settings, upserting one row per key. Only the
     * recognised keys are written; anything else is ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach (array_keys(self::DEFAULTS) as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $values[$key]],
                );
            }
        });
    }

    public function paperSize(): string
    {
        $value = $this->get('receipt_paper_size');

        return in_array($value, self::PAPER_SIZES, true) ? $value : self::DEFAULTS['receipt_paper_size'];
    }

    public function connection(): string
    {
        $value = $this->get('receipt_connection');

        return in_array($value, self::CONNECTIONS, true) ? $value : self::DEFAULTS['receipt_connection'];
    }

    /**
     * The device path for a USB/Bluetooth connection (null when blank).
     */
    public function devicePath(): ?string
    {
        $value = trim($this->get('receipt_device_path'));

        return $value === '' ? null : $value;
    }

    public function networkHost(): ?string
    {
        $value = trim($this->get('receipt_network_host'));

        return $value === '' ? null : $value;
    }

    public function networkPort(): int
    {
        return (int) ($this->get('receipt_network_port') ?: self::DEFAULTS['receipt_network_port']);
    }

    public function autoPrint(): bool
    {
        return $this->get('auto_print') === '1';
    }

    public function openCashDrawer(): bool
    {
        return $this->get('open_cash_drawer') === '1';
    }

    /**
     * Whether a printer destination is actually configured for the chosen
     * connection. When false, printing is skipped rather than attempted.
     */
    public function isConfigured(): bool
    {
        return match ($this->connection()) {
            self::CONNECTION_NETWORK => $this->networkHost() !== null,
            default => $this->devicePath() !== null,
        };
    }

    private function get(string $key): string
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? '';
    }
}
