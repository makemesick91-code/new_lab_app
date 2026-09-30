<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloadable preflight/outcome report — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §22.
 *
 * COLUMNS ARE CHOSEN BY SUBTRACTION
 * ---------------------------------
 * A migration report is a spreadsheet that gets emailed, forwarded and kept, so
 * every column has to justify itself against that. What is here is the minimum
 * an operator needs to act: which line, which RM they typed, which file, what
 * happened, and why.
 *
 * Deliberately absent: patient name, date of birth, KTP/NIK, any clinical
 * content, the internal patient id, the created import id, absolute paths and
 * disk names. The medical record number stays because the operator supplied it
 * themselves and cannot locate the row in their own spreadsheet without it.
 *
 * FORMULA INJECTION
 * -----------------
 * Every cell is escaped before it is written. A reason message can contain a
 * domain string, and an RM is operator-supplied free text — either could begin
 * with `=`, which a spreadsheet would execute on open. The guard follows the
 * pattern already used by LegacyPatientImportService and
 * LabTechnicianCapacityController; there is no shared helper in this codebase
 * to reuse, and inventing one here would mean touching two shipped exporters.
 */
class LegacyMassUploadReportService
{
    /** @var list<string> */
    private const HEADERS = [
        'row_number',
        'medical_record_number',
        'file_name',
        'status',
        'status_label',
        'reason_code',
        'reason_message',
        'document_date',
        'document_date_latest',
    ];

    public function filename(LegacyMassUploadBatch $batch): string
    {
        $prefix = (string) config('legacy_mass_upload.report.filename_prefix', 'legacy-mass-upload-preflight');

        return sprintf('%s-%s.csv', $prefix, substr((string) $batch->uuid, 0, 8));
    }

    /**
     * Stream the report.
     *
     * Streamed and chunked rather than built in memory: a 5000-row report
     * assembled as one string is a needless spike on a shared VPS, and the
     * operator gets the first bytes immediately.
     */
    public function stream(LegacyMassUploadBatch $batch): StreamedResponse
    {
        $filename = $this->filename($batch);
        $maxRows = max(1, (int) config('legacy_mass_upload.report.max_rows', 5000));

        return response()->streamDownload(function () use ($batch, $maxRows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // BOM so Excel opens UTF-8 correctly. Without it, Indonesian
            // messages render as mojibake and operators assume the tool is
            // broken.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, self::HEADERS);

            $written = 0;

            LegacyMassUploadItem::query()
                ->where('mass_upload_batch_id', $batch->getKey())
                ->orderBy('row_number')
                ->chunk(500, function ($items) use ($handle, &$written, $maxRows): bool {
                    foreach ($items as $item) {
                        if ($written >= $maxRows) {
                            return false;
                        }

                        fputcsv($handle, array_map(
                            fn ($value): string => $this->escape($value),
                            [
                                $item->row_number,
                                $item->manifest_medical_record_number,
                                $item->manifest_file_name,
                                $item->status,
                                LegacyMassUploadItemStatus::label((string) $item->status),
                                $item->reason_code,
                                $item->reasonText(),
                                $item->manifest_selected_date,
                                $item->manifest_latest_date,
                            ],
                        ));

                        $written++;
                    }

                    return true;
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // The report names a clinical migration batch; no cache should keep
            // a copy of it.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Neutralise a leading formula character.
     *
     * Prefixing with an apostrophe makes a spreadsheet treat the cell as text.
     * Tab and carriage return are included because both can begin a cell that
     * a spreadsheet then re-interprets.
     */
    private function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * Manifest template for the operator to fill in (§59).
     *
     * @return array{filename: string, headers: list<string>, example: list<string>}
     */
    public function manifestTemplate(string $importType, LegacyMassUploadManifestParser $parser): array
    {
        $template = $parser->template($importType);

        return [
            'filename' => sprintf('manifest-%s.csv', str_replace('_', '-', $importType)),
            'headers' => $template['headers'],
            'example' => $template['example'],
        ];
    }

    /**
     * Stream the manifest template.
     */
    public function streamManifestTemplate(string $importType, LegacyMassUploadManifestParser $parser): StreamedResponse
    {
        $template = $this->manifestTemplate($importType, $parser);

        return response()->streamDownload(function () use ($template): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $template['headers']);
            fputcsv($handle, array_map(fn ($value): string => $this->escape($value), $template['example']));

            fclose($handle);
        }, $template['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
