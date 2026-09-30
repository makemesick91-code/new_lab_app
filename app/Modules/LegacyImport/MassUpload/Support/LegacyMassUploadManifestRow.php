<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

/**
 * One parsed manifest data row, verbatim.
 *
 * Every value here is exactly what the operator typed, trimmed of surrounding
 * whitespace and nothing else. No date parsing, no RM normalisation, no
 * defaulting happens at this layer.
 *
 * That is not laziness — it is the only way the preflight report can tell an
 * operator what THEY wrote. If this object silently repaired `2021-13-45` into
 * something parseable, the report would show a date the operator never entered
 * and they would have no way to find the bad cell in their spreadsheet.
 * Normalisation and validation belong to the canonical domain services, which
 * own those rules.
 */
final class LegacyMassUploadManifestRow
{
    public function __construct(
        /** 1-based data row, header excluded — matches the operator's line. */
        public readonly int $rowNumber,

        public readonly string $medicalRecordNumber,

        public readonly string $fileName,

        /**
         * RME: rme_date_earliest. Odontogram: document_date.
         * Adapters map their own column onto this field so the rest of the
         * pipeline does not branch on document type.
         */
        public readonly ?string $selectedDate,

        /** RME only: rme_date_latest. Always null for odontogram. */
        public readonly ?string $latestDate = null,
    ) {}

    /**
     * True when the row carries no usable content at all.
     *
     * Trailing blank lines are endemic in operator-produced CSVs — a spreadsheet
     * export routinely appends them. Treating those as items would fail every
     * batch on a cosmetic artefact, so the parser drops them; this predicate is
     * what it asks.
     */
    public function isBlank(): bool
    {
        return $this->medicalRecordNumber === ''
            && $this->fileName === ''
            && ($this->selectedDate === null || $this->selectedDate === '')
            && ($this->latestDate === null || $this->latestDate === '');
    }
}
