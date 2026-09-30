<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Modules\LegacyImport\MassUpload\Exceptions\LegacyMassUploadPackageRejected;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadManifestRow;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyImport\Support\LegacyImportType;

/**
 * Manifest parsing — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §10.
 *
 * Native fgetcsv with a BOM strip and an exact header assertion, mirroring
 * ProductImportService and LegacyPatientImportService. maatwebsite/excel is
 * deliberately not used and must not be introduced for this: a spreadsheet
 * library would parse formulas, and a manifest is a data file, not a program.
 *
 * WHY A BAD HEADER IS A PACKAGE FAILURE
 * -------------------------------------
 * If the header is wrong we do not know which column meant what, so we cannot
 * honestly evaluate a single row. Guessing — "this looks like a date column" —
 * is how a mass tool binds a document to the wrong patient. The refusal is
 * therefore total (§17) rather than per-item.
 *
 * NIK/KTP IS STRUCTURALLY REFUSED
 * -------------------------------
 * Identity columns are rejected by name, and so are patient_id and branch_id.
 * The first is a privacy boundary; the latter two are authorization boundaries
 * that must be resolved server-side and can never be accepted from a file an
 * operator edits (§10, §11).
 */
class LegacyMassUploadManifestParser
{
    /**
     * Hard ceiling on data rows, evaluated while streaming so a crafted
     * manifest cannot exhaust memory before the check runs.
     */
    private const ABSOLUTE_MAX_ROWS = 20000;

    /**
     * @return list<LegacyMassUploadManifestRow>
     */
    public function parse(string $absolutePath, string $importType): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::MANIFEST_UNREADABLE);
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::MANIFEST_UNREADABLE);
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === null) {
                throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::MANIFEST_EMPTY);
            }

            $columns = $this->normalizeHeader($header);

            $this->assertHeader($columns, $importType);

            $index = $this->columnIndex($columns);

            $rows = [];
            $rowNumber = 0;
            $seenFiles = [];
            $maxRows = min(
                (int) config('legacy_mass_upload.report.max_rows', 5000),
                self::ABSOLUTE_MAX_ROWS,
            );

            while (($record = fgetcsv($handle)) !== false && $record !== null) {
                $row = $this->buildRow($record, $index, $importType, $rowNumber + 1);

                // Spreadsheet exports routinely append blank lines. Counting
                // them as items would fail real batches on a cosmetic artefact.
                if ($row->isBlank()) {
                    continue;
                }

                $rowNumber++;

                if ($rowNumber > $maxRows) {
                    throw LegacyMassUploadPackageRejected::because(
                        LegacyMassUploadReason::MANIFEST_TOO_MANY_ROWS,
                        ['max_rows' => $maxRows],
                    );
                }

                // Two rows claiming one file cannot both be honoured, and the
                // staging table's unique index would refuse the second anyway.
                // Catching it here turns a database error into a clear message.
                $fileKey = $row->fileName;

                if ($fileKey !== '' && isset($seenFiles[$fileKey])) {
                    throw LegacyMassUploadPackageRejected::because(
                        LegacyMassUploadReason::MANIFEST_DUPLICATE_FILE,
                        ['file_name' => $fileKey, 'row' => $rowNumber],
                    );
                }

                $seenFiles[$fileKey] = true;

                // Re-stamp with the corrected, blank-skipping row number so the
                // operator's report line matches their spreadsheet.
                $rows[] = new LegacyMassUploadManifestRow(
                    rowNumber: $rowNumber,
                    medicalRecordNumber: $row->medicalRecordNumber,
                    fileName: $row->fileName,
                    selectedDate: $row->selectedDate,
                    latestDate: $row->latestDate,
                );
            }

            if ($rows === []) {
                throw LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::MANIFEST_EMPTY);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private function normalizeHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $position => $value) {
            $text = (string) ($value ?? '');

            // The UTF-8 BOM attaches to the first cell of a file exported from
            // Excel and would otherwise make `medical_record_number` compare
            // unequal to itself.
            if ($position === 0) {
                $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
            }

            $columns[] = strtolower(trim($text));
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertHeader(array $columns, string $importType): void
    {
        $forbidden = array_map(
            static fn ($value): string => strtolower((string) $value),
            (array) config('legacy_mass_upload.manifest.forbidden_headers', []),
        );

        foreach ($columns as $column) {
            if ($column !== '' && in_array($column, $forbidden, true)) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::MANIFEST_HEADER_FORBIDDEN,
                    ['column' => $column],
                );
            }
        }

        $required = (array) config("legacy_mass_upload.manifest.{$importType}.required_headers", []);

        if ($required === []) {
            // An unknown import type means the caller is wrong, not the
            // operator. Refuse rather than accept an unvalidated header.
            throw LegacyMassUploadPackageRejected::because(
                LegacyMassUploadReason::MANIFEST_HEADER_INVALID,
                ['import_type' => $importType],
            );
        }

        foreach ($required as $column) {
            if (! in_array(strtolower((string) $column), $columns, true)) {
                throw LegacyMassUploadPackageRejected::because(
                    LegacyMassUploadReason::MANIFEST_HEADER_INVALID,
                    ['missing_column' => (string) $column],
                );
            }
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, int>
     */
    private function columnIndex(array $columns): array
    {
        $index = [];

        foreach ($columns as $position => $column) {
            if ($column === '') {
                continue;
            }

            // First occurrence wins. A duplicated column is ambiguous, but
            // refusing the batch for it would be harsher than necessary when
            // the first one is almost always the intended data.
            $index[$column] ??= $position;
        }

        return $index;
    }

    /**
     * @param  list<string|null>  $record
     * @param  array<string, int>  $index
     */
    private function buildRow(array $record, array $index, string $importType, int $rowNumber): LegacyMassUploadManifestRow
    {
        $value = static function (string $column) use ($record, $index): ?string {
            if (! isset($index[$column])) {
                return null;
            }

            $raw = $record[$index[$column]] ?? null;

            if ($raw === null) {
                return null;
            }

            $text = trim((string) $raw);

            return $text === '' ? null : $text;
        };

        // The two document types name their date column differently; mapping
        // happens here so nothing downstream has to branch on type.
        $selectedColumn = $importType === LegacyImportType::LEGACY_ODONTOGRAM
            ? 'document_date'
            : 'rme_date_earliest';

        return new LegacyMassUploadManifestRow(
            rowNumber: $rowNumber,
            medicalRecordNumber: (string) ($value('medical_record_number') ?? ''),
            fileName: (string) ($value('file_name') ?? ''),
            selectedDate: $value($selectedColumn),
            latestDate: $importType === LegacyImportType::LEGACY_RME
                ? $value('rme_date_latest')
                : null,
        );
    }

    /**
     * Template rows for the operator-downloadable manifest example.
     *
     * Values are obviously-fake placeholders. A template that shipped a
     * plausible real RM invites someone to submit it unchanged.
     *
     * @return array{headers: list<string>, example: list<string>}
     */
    public function template(string $importType): array
    {
        if ($importType === LegacyImportType::LEGACY_ODONTOGRAM) {
            return [
                'headers' => ['medical_record_number', 'file_name', 'document_date'],
                'example' => ['RM-CONTOH-001', 'odontogram-contoh-001.pdf', '2019-04-11'],
            ];
        }

        return [
            'headers' => ['medical_record_number', 'file_name', 'rme_date_earliest', 'rme_date_latest'],
            'example' => ['RM-CONTOH-001', 'rme-contoh-001.pdf', '2018-02-07', '2021-11-30'],
        ];
    }
}
