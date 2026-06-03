<?php

namespace App\Modules\Reporting\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams report datasets as CSV. No file persistence, no mutation
 * (sprint_8_technical_design.md §13).
 */
class ExportReportService
{
    /**
     * @param  array{filename: string, header: array<int, string>, rows: iterable}  $data
     */
    public function stream(array $data): StreamedResponse
    {
        $filename = $data['filename'] ?? 'report.csv';
        $header = $data['header'] ?? [];
        $rows = $data['rows'] ?? [];

        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');

            if (! empty($header)) {
                fputcsv($handle, $header);
            }

            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn ($v) => $v ?? '', (array) $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
