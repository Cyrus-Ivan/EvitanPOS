<?php

namespace Tests\Feature\Printing;

use App\Support\Printing\Connectors\SystemPrinterConnector;
use App\Support\Printing\PrinterException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SystemPrinterConnectorTest extends TestCase
{
    public function test_it_pipes_bytes_to_cups_raw_on_unix(): void
    {
        Process::fake();

        (new SystemPrinterConnector('Star_TSP100', osFamily: 'Linux'))->write('ESC/POS-BYTES');

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command === ['lp', '-d', 'Star_TSP100', '-o', 'raw']
                && $process->input === 'ESC/POS-BYTES';
        });
    }

    public function test_a_cups_failure_surfaces_a_printer_exception(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'printer offline')]);

        $this->expectException(PrinterException::class);
        $this->expectExceptionMessage('Star_TSP100');

        (new SystemPrinterConnector('Star_TSP100', osFamily: 'Linux'))->write('x');
    }

    public function test_it_invokes_the_windows_raw_helper_on_windows(): void
    {
        Process::fake();

        (new SystemPrinterConnector('EPSON TM-T20', osFamily: 'Windows'))->write('ESC/POS-BYTES');

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command)
                && $command[0] === 'powershell'
                && in_array('-PrinterName', $command, true)
                && in_array('EPSON TM-T20', $command, true)
                && str_contains(implode(' ', $command), 'windows-raw-print.ps1');
        });
    }

    public function test_a_windows_failure_surfaces_a_printer_exception(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'OpenPrinter failed')]);

        $this->expectException(PrinterException::class);

        (new SystemPrinterConnector('EPSON TM-T20', osFamily: 'Windows'))->write('x');
    }

    public function test_an_empty_printer_name_throws_without_running_a_process(): void
    {
        Process::fake();

        try {
            (new SystemPrinterConnector('   ', osFamily: 'Linux'))->write('x');
            $this->fail('Expected a PrinterException for an empty printer name.');
        } catch (PrinterException $e) {
            $this->assertStringContainsString('No system printer', $e->getMessage());
        }

        Process::assertNothingRan();
    }
}
