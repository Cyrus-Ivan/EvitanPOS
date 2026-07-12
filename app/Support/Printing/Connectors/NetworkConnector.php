<?php

namespace App\Support\Printing\Connectors;

use App\Support\Printing\Contracts\PrinterConnector;
use App\Support\Printing\PrinterException;

/**
 * Sends raw ESC/POS bytes to a network thermal printer over a TCP socket
 * (typically port 9100, the RAW/JetDirect port). The optional path for
 * Wi-Fi/Ethernet printers alongside the USB/Bluetooth device connector.
 */
class NetworkConnector implements PrinterConnector
{
    public function __construct(
        private readonly string $host,
        private readonly int $port = 9100,
        private readonly float $timeout = 5.0,
    ) {}

    public function write(string $bytes): void
    {
        $handle = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if ($handle === false) {
            throw new PrinterException("Could not reach printer at {$this->host}:{$this->port} ({$errstr}).");
        }

        try {
            stream_set_timeout($handle, (int) $this->timeout);
            $written = @fwrite($handle, $bytes);

            if ($written === false || $written < strlen($bytes)) {
                throw new PrinterException("Failed to send the receipt to {$this->host}:{$this->port}.");
            }

            @fflush($handle);
        } finally {
            @fclose($handle);
        }
    }
}
