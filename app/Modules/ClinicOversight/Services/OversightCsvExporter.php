<?php

namespace App\Modules\ClinicOversight\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — the one CSV writer of the
 * oversight module.
 *
 * - Every cell passes the spreadsheet formula-injection guard (a value opening
 *   with = + - @ TAB CR is prefixed with a quote), the same convention the
 *   other exports in this codebase use.
 * - The download name is generic (area + date): never a patient name or a
 *   medical-record number, which would leak into the reader's file system.
 * - Streamed, never written to disk, never stored publicly.
 * - Callers pass only columns that may be shown: NIK/KTP, consent and clinical
 *   free text are never part of an oversight export.
 */
class OversightCsvExporter
{
    /**
     * @param  list<string>  $header
     * @param  iterable<int, list<scalar|null>>  $rows
     */
    public function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // UTF-8 BOM so spreadsheet software reads Indonesian names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($cell) => self::safe($cell), $header));

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => self::safe($cell), $row));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Neutralise CSV/formula injection in a cell. */
    public static function safe(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
