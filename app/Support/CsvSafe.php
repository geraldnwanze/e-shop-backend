<?php

namespace App\Support;

/**
 * Keeps text that customers typed (names, emails) from running as a formula when a merchant opens
 * an export in a spreadsheet.
 */
final class CsvSafe
{
    public static function cell(string $value): string
    {
        // An international phone number starts with "+" but cannot be a formula, so leave it readable.
        if (preg_match('/^\+[0-9][0-9 ()-]*$/', $value) === 1) {
            return $value;
        }

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
