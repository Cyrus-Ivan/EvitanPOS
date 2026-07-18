<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects names that would execute as formulas when the inventory CSV export
 * is opened in a spreadsheet (CSV injection): a leading =, +, - or @ turns a
 * cell into a formula in Excel/LibreOffice. Blocking the four characters at
 * creation time keeps the export clean without corrupting the import
 * round-trip the way output-escaping would (Phase 18, F-16).
 */
class SpreadsheetSafeName implements ValidationRule
{
    public static function isSafe(string $value): bool
    {
        return preg_match('/^[=+@-]/', $value) !== 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! self::isSafe($value)) {
            $fail('The :attribute may not start with "=", "+", "-" or "@".');
        }
    }
}
