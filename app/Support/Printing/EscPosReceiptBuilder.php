<?php

namespace App\Support\Printing;

/**
 * Turns the receipt data array (from {@see ReceiptContent}) into an ESC/POS
 * byte stream for a thermal printer. Dependency-free — it only emits the small
 * set of ESC/POS commands a receipt needs: init, alignment, bold, a full cut
 * and (optionally) a cash-drawer kick.
 *
 * Layout is sized in monospace columns to the paper width (58mm ≈ 32 cols,
 * 80mm ≈ 48 cols). The peso sign is not in the default thermal code page, so
 * money is prefixed with a plain "P".
 *
 * It never emits item cost — receipts are customer-facing.
 */
class EscPosReceiptBuilder
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private const COLUMNS = ['58mm' => 32, '80mm' => 48];

    /**
     * Build the full ESC/POS byte stream for the given receipt.
     *
     * @param  array<string, mixed>  $data
     */
    public function build(array $data, bool $openDrawer = false): string
    {
        $width = self::COLUMNS[$data['paper_size'] ?? '58mm'] ?? self::COLUMNS['58mm'];
        $business = $data['business'] ?? [];

        $out = self::ESC.'@'; // initialise / reset

        // Kick the drawer first so it opens promptly at the start of the print.
        if ($openDrawer) {
            $out .= self::ESC.'p'.chr(0).chr(25).chr(250); // ESC p m t1 t2
        }

        // --- Header (centred) ---
        $out .= $this->align('center');

        if ($data['is_reprint'] ?? false) {
            $out .= $this->bold('*** REPRINT ***')."\n";
        }

        if (! empty($business['name'])) {
            $out .= $this->bold($this->clip((string) $business['name'], $width))."\n";
        }

        foreach ($this->wrap((string) ($business['address'] ?? ''), $width) as $line) {
            $out .= $line."\n";
        }

        if (! empty($business['tin'])) {
            $out .= $this->clip('TIN: '.$business['tin'], $width)."\n";
        }

        $out .= $this->align('left');
        $out .= $this->rule($width);

        // --- Meta ---
        $out .= $this->clip('Receipt: '.($data['number'] ?? ''), $width)."\n";
        $out .= $this->clip((string) ($data['datetime'] ?? ''), $width)."\n";
        $out .= $this->clip('Cashier: '.($data['cashier'] ?? ''), $width)."\n";
        $out .= $this->clip('Payment: '.($data['payment_label'] ?? ''), $width)."\n";
        $out .= $this->rule($width);

        // --- Items ---
        foreach ($data['items'] ?? [] as $item) {
            foreach ($this->wrap((string) $item['label'], $width) as $line) {
                $out .= $line."\n";
            }

            $qtyUnit = $item['quantity'].' x '.$this->money($item['unit_price']);
            $out .= $this->columns($qtyUnit, $this->money($item['line_total']), $width)."\n";
        }

        $out .= $this->rule($width);

        // --- Totals ---
        $out .= $this->columns('Subtotal', $this->money($data['subtotal'] ?? '0.00'), $width)."\n";

        $discountAmount = (string) ($data['discount_amount'] ?? '0.00');

        if ($discountAmount !== '' && (float) $discountAmount > 0) {
            $label = 'Discount';

            if (! empty($data['discount_display'])) {
                $label .= ' ('.$data['discount_display'].')';
            }

            $out .= $this->columns($label, '-'.$this->money($discountAmount), $width)."\n";
        }

        $out .= $this->bold($this->columns('TOTAL', $this->money($data['total'] ?? '0.00'), $width))."\n";

        if (($data['cash_received'] ?? null) !== null) {
            $out .= $this->columns('Cash', $this->money($data['cash_received']), $width)."\n";
            $out .= $this->columns('Change', $this->money($data['change'] ?? '0.00'), $width)."\n";
        }

        if (! empty($data['comment'])) {
            $out .= $this->rule($width);
            foreach ($this->wrap('Note: '.$data['comment'], $width) as $line) {
                $out .= $line."\n";
            }
        }

        // --- Footer (centred) ---
        if (! empty($data['footer'])) {
            $out .= "\n".$this->align('center');
            foreach ($this->wrap((string) $data['footer'], $width) as $line) {
                $out .= $line."\n";
            }
            $out .= $this->align('left');
        }

        // Feed clear of the tear bar, then a full cut.
        $out .= "\n\n\n\n";
        $out .= self::GS.'V'.chr(0);

        return $out;
    }

    private function align(string $where): string
    {
        $n = match ($where) {
            'center' => 1,
            'right' => 2,
            default => 0,
        };

        return self::ESC.'a'.chr($n);
    }

    private function bold(string $text): string
    {
        return self::ESC.'E'.chr(1).$text.self::ESC.'E'.chr(0);
    }

    /**
     * A left label and a right value padded to fill the line width. When the
     * two together overflow, the label is clipped so the value stays intact.
     */
    private function columns(string $left, string $right, int $width): string
    {
        $gap = $width - mb_strlen($left) - mb_strlen($right);

        if ($gap < 1) {
            $left = $this->clip($left, max(0, $width - mb_strlen($right) - 1));
            $gap = $width - mb_strlen($left) - mb_strlen($right);
        }

        return $left.str_repeat(' ', max(1, $gap)).$right;
    }

    private function rule(int $width): string
    {
        return str_repeat('-', $width)."\n";
    }

    /**
     * A peso amount for the printer: plain "P" prefix (the code page has no ₱).
     */
    private function money(string|float|int $value): string
    {
        return 'P'.$value;
    }

    private function clip(string $text, int $width): string
    {
        return mb_substr($text, 0, max(0, $width));
    }

    /**
     * Word-wrap text to the paper width, returning one entry per line. Empty
     * input yields no lines.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return explode("\n", wordwrap($text, $width, "\n", true));
    }
}
