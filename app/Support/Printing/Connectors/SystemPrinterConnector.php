<?php

namespace App\Support\Printing\Connectors;

use App\Support\Printing\Contracts\PrinterConnector;
use App\Support\Printing\PrinterException;
use Illuminate\Support\Facades\Process;
use Native\Laravel\System;

/**
 * Sends raw ESC/POS bytes to a printer installed as an OS print queue — the
 * cross-platform path for macOS and Windows (and Linux with CUPS), where a
 * USB/Bluetooth thermal printer is enrolled as a named queue rather than
 * exposed as a device file.
 *
 * The bytes are handed to the OS spooler in a raw/passthrough mode so the
 * ESC/POS stream reaches the printer untouched:
 *
 * - macOS / Linux (CUPS): `lp -d "<queue>" -o raw` with the bytes on stdin.
 * - Windows: a bundled PowerShell helper that P/Invokes the winspool RAW API
 *   ({@see resources/printing/windows-raw-print.ps1}).
 *
 * The queue name is what {@see System::printers()} reports, so
 * the owner can pick it from a list in Business settings.
 */
class SystemPrinterConnector implements PrinterConnector
{
    /**
     * @param  string  $printerName  the OS print-queue name to target
     * @param  string  $osFamily  PHP_OS_FAMILY value; injectable so the
     *                            Windows branch is testable off-Windows
     * @param  float  $timeout  seconds to wait for the spooler command
     */
    public function __construct(
        private readonly string $printerName,
        private readonly string $osFamily = PHP_OS_FAMILY,
        private readonly float $timeout = 15.0,
    ) {}

    public function write(string $bytes): void
    {
        if (trim($this->printerName) === '') {
            throw new PrinterException('No system printer is selected. Choose one in Business settings.');
        }

        if ($this->osFamily === 'Windows') {
            $this->writeViaWindowsSpooler($bytes);

            return;
        }

        $this->writeViaCups($bytes);
    }

    /**
     * macOS / Linux: pipe the bytes to `lp -o raw` for the named queue.
     */
    private function writeViaCups(string $bytes): void
    {
        $result = Process::timeout($this->timeout)
            ->input($bytes)
            ->run(['lp', '-d', $this->printerName, '-o', 'raw']);

        if ($result->failed()) {
            throw new PrinterException($this->failureMessage(trim($result->errorOutput())));
        }
    }

    /**
     * Windows: write the bytes to a temp file and hand it to the RAW spooler
     * helper, which writes them to the named queue untouched.
     */
    private function writeViaWindowsSpooler(string $bytes): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'rcpt');

        if ($tempFile === false || @file_put_contents($tempFile, $bytes) === false) {
            throw new PrinterException('Could not stage the receipt for printing (temp file failed).');
        }

        try {
            $result = Process::timeout($this->timeout)->run([
                'powershell',
                '-NoProfile',
                '-ExecutionPolicy', 'Bypass',
                '-File', $this->windowsHelperPath(),
                '-PrinterName', $this->printerName,
                '-FilePath', $tempFile,
            ]);

            if ($result->failed()) {
                $detail = trim($result->errorOutput()) ?: trim($result->output());
                throw new PrinterException($this->failureMessage($detail));
            }
        } finally {
            @unlink($tempFile);
        }
    }

    private function windowsHelperPath(): string
    {
        return resource_path('printing/windows-raw-print.ps1');
    }

    private function failureMessage(string $detail): string
    {
        $message = "Could not print to '{$this->printerName}'. Check that the printer is installed and online.";

        return $detail === '' ? $message : "{$message} ({$detail})";
    }
}
