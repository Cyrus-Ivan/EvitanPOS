<?php

namespace App\Support\Printing\Connectors;

use App\Support\Printing\Contracts\PrinterConnector;
use App\Support\Printing\PrinterException;

/**
 * Writes raw ESC/POS bytes straight to a character device — the driverless path
 * for USB (`/dev/usb/lp0`, exposed by the kernel `usblp` module) and paired
 * Bluetooth (`/dev/rfcomm0`, after `rfcomm bind`) thermal printers on Linux.
 *
 * No CUPS/driver is involved: the app's PHP process opens the device and writes
 * to it. The process therefore needs write access to the node (membership of
 * the `lp`/`dialout` group or a udev rule) — documented in
 * docs/receipt-printing-design.md.
 */
class RawDeviceConnector implements PrinterConnector
{
    public function __construct(private readonly string $devicePath) {}

    public function write(string $bytes): void
    {
        if (! file_exists($this->devicePath)) {
            throw new PrinterException("Printer device {$this->devicePath} was not found. Check that the printer is connected.");
        }

        // Suppress the native warning so a permission/IO problem surfaces as our
        // typed exception rather than leaking a PHP warning to the response.
        $handle = @fopen($this->devicePath, 'wb');

        if ($handle === false) {
            throw new PrinterException("Could not open printer device {$this->devicePath}. Check permissions (the app needs write access to the device).");
        }

        try {
            $written = @fwrite($handle, $bytes);

            if ($written === false || $written < strlen($bytes)) {
                throw new PrinterException("Failed to send the receipt to {$this->devicePath}.");
            }

            @fflush($handle);
        } finally {
            @fclose($handle);
        }
    }
}
