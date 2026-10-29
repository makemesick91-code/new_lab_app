<?php

declare(strict_types=1);

namespace App\Support\Export;

use BackedEnum;
use Stringable;

/**
 * SECURITY-F3-CSV-CELL-GUARD-1 — one rule for every CSV cell the application
 * writes.
 *
 * A spreadsheet treats a cell beginning with `=`, `+`, `-`, `@`, a tab or a
 * carriage return as a formula. A patient name, a product name or a note typed
 * into the application can therefore become a live formula in the file a user
 * opens. Prefixing such a cell with an apostrophe makes the spreadsheet read it
 * as text.
 *
 * Numbers stay numbers (decision D20): an int or float, or a string that is a
 * plain signed decimal such as `-15000.00`, is written unchanged, so numeric
 * columns remain numeric. A leading sign alone cannot carry a formula; a
 * function call, a reference or an operator after it can, and such a value is
 * not a plain decimal.
 */
final class CsvCell
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    private const PLAIN_DECIMAL = '/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/D';

    /** Neutralise one cell. Non-text values are returned unchanged. */
    public static function guard(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
            if (! is_string($value)) {
                return $value;
            }
        }

        if (! is_string($value) && ! $value instanceof Stringable) {
            return $value;
        }

        $text = (string) $value;

        if ($text === '' || ! in_array($text[0], self::TRIGGERS, true)) {
            return $text;
        }

        if (preg_match(self::PLAIN_DECIMAL, $text) === 1) {
            return $text;
        }

        return "'".$text;
    }

    /**
     * Neutralise every cell of one row.
     *
     * @param  iterable<mixed>  $cells
     * @return array<mixed>
     */
    public static function row(iterable $cells): array
    {
        $out = [];
        foreach ($cells as $key => $cell) {
            $out[$key] = self::guard($cell);
        }

        return $out;
    }
}
