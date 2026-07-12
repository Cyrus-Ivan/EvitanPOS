<?php

namespace App\Support\Printing\Contracts;

use App\Support\Printing\EscPosReceiptBuilder;
use App\Support\Printing\PrinterException;

/**
 * A transport that delivers already-built ESC/POS bytes to a printer. The one
 * responsibility is "get these bytes to the device"; byte generation lives in
 * {@see EscPosReceiptBuilder}.
 *
 * Implementations exist for a raw device file (USB/Bluetooth) and a network
 * socket. Tests bind an in-memory implementation. This seam is also where a
 * future OS-driver or libusb transport would slot in.
 */
interface PrinterConnector
{
    /**
     * Write the given bytes to the printer.
     *
     * @throws PrinterException when the device cannot be opened or written to.
     */
    public function write(string $bytes): void;
}
