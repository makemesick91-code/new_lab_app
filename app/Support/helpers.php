<?php

use Carbon\Carbon;
use Carbon\CarbonInterface;

if (! function_exists('format_date_id')) {
    function format_date_id(mixed $value, string $fallback = '-'): string
    {
        $date = parse_display_date($value);

        if (! $date) {
            return $fallback;
        }

        return $date->format('j').' '.month_name_id((int) $date->format('n')).' '.$date->format('Y');
    }
}

if (! function_exists('format_datetime_id')) {
    function format_datetime_id(mixed $value, string $fallback = '-'): string
    {
        $date = parse_display_date($value);

        if (! $date) {
            return $fallback;
        }

        return format_date_id($date, $fallback).' '.$date->format('H:i');
    }
}

if (! function_exists('format_time_id')) {
    function format_time_id(mixed $value, string $fallback = '-'): string
    {
        $date = parse_display_date($value);

        if (! $date) {
            return $fallback;
        }

        return $date->format('H:i');
    }
}

if (! function_exists('format_month_id')) {
    function format_month_id(mixed $value, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            $date = preg_match('/^\d{4}-\d{2}$/', (string) $value)
                ? Carbon::createFromFormat('Y-m', (string) $value)
                : parse_display_date($value);
        } catch (Throwable) {
            return $fallback;
        }

        if (! $date) {
            return $fallback;
        }

        return month_name_id((int) $date->format('n')).' '.$date->format('Y');
    }
}

if (! function_exists('format_number_id')) {
    function format_number_id(mixed $value, int $decimals = 0): string
    {
        $number = numeric_display_value($value);

        if ($number === null) {
            return (string) $value;
        }

        return number_format($number, $decimals, ',', '.');
    }
}

if (! function_exists('format_quantity_id')) {
    function format_quantity_id(mixed $value, int $decimals = 2): string
    {
        $number = numeric_display_value($value);

        if ($number === null) {
            return (string) $value;
        }

        if (abs($number - round($number)) < 0.0000001) {
            return number_format($number, 0, ',', '.');
        }

        return number_format($number, $decimals, ',', '.');
    }
}

if (! function_exists('format_currency_id')) {
    function format_currency_id(mixed $value): string
    {
        $number = numeric_display_value($value);

        if ($number === null) {
            return (string) $value;
        }

        $decimals = abs($number - round($number)) < 0.0000001 ? 0 : 2;

        return 'Rp '.number_format($number, $decimals, ',', '.');
    }
}

if (! function_exists('format_percent_id')) {
    function format_percent_id(mixed $value, int $decimals = 1): string
    {
        $number = numeric_display_value($value);

        if ($number === null) {
            return (string) $value;
        }

        $formatted = number_format($number, $decimals, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',').'%';
    }
}

if (! function_exists('numeric_display_value')) {
    function numeric_display_value(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = trim(str_replace(['Rp', ' '], '', $value));

        if (! preg_match('/^-?[\d.,]+$/', $clean)) {
            return null;
        }

        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (substr_count($clean, '.') > 1) {
            $clean = str_replace('.', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }
}

if (! function_exists('parse_display_date')) {
    function parse_display_date(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

if (! function_exists('month_name_id')) {
    function month_name_id(int $month): string
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ][$month] ?? '';
    }
}
