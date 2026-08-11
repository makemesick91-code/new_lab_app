<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Support\MedicalRecordNumberParts;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Sprint 23 Phase 23.8 — finalized patient medical record number composer.
 *
 * The clinic owner locked the final format to:
 *
 *     DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}
 *
 * Examples:
 *   - DG-TKM1-2026-0001
 *   - DG-LDK2-2026-25
 *
 * Rules enforced here:
 *   - Prefix is exactly "DG".
 *   - Branch code is uppercased and trimmed.
 *   - Year must be four digits.
 *   - Manual RM number is trimmed but NOT auto-generated and NOT auto-padded.
 *     Leading zeros entered by the admin are preserved verbatim.
 *   - The composed value must be globally unique in mst_patients.
 *
 * This composer never invents a sequence; the manual RM number always comes
 * from the user/admin. Legacy/auto-sequence behavior lives in the separate
 * {@see PatientCodeGenerator} and is untouched.
 */
class PatientMedicalRecordNumberService
{
    public const PREFIX = 'DG';

    /**
     * Compose the final medical record number from its components.
     */
    public function compose(string $branchCode, int|string $year, string $manualRmNumber): string
    {
        $branchCode = strtoupper(trim($branchCode));
        $manualRmNumber = trim($manualRmNumber);
        $year = $this->normalizeYear($year);

        if ($branchCode === '') {
            throw new InvalidArgumentException('Branch code is required to compose a medical record number.');
        }

        if ($manualRmNumber === '') {
            throw new InvalidArgumentException('Manual RM number is required to compose a medical record number.');
        }

        return self::PREFIX.'-'.$branchCode.'-'.$year.'-'.$manualRmNumber;
    }

    /**
     * Convenience composer that derives the year from a registration date.
     */
    public function composeForRegistration(string $branchCode, ?CarbonInterface $registeredAt, string $manualRmNumber): string
    {
        $registeredAt = $registeredAt ?? Carbon::now();

        return $this->compose($branchCode, (int) $registeredAt->format('Y'), $manualRmNumber);
    }

    /**
     * LEGACY-RME-PDF-FIX-ROLL2-1 — the canonical PARSER for the format this
     * class composes. It is the exact inverse of {@see self::compose()}.
     *
     * It exists because the branch a document belongs to is encoded in the
     * patient's own Nomor RM, and every consumer of that fact must read it the
     * same way. No controller, form request, service or view may carry its own
     * regex for this format: parsing lives here, next to the composer, so the
     * two can never drift apart.
     *
     * Returns NULL — never a partial guess — for anything that is not exactly
     * `DG-{KODE_CABANG}-{TAHUN}-{NOMOR}`. A caller that needs a branch must
     * treat null as fail-closed; nothing here invents a default.
     *
     * The manual sequence may itself contain a hyphen (compose() accepts any
     * non-empty trimmed string), so the split is bounded to four parts and the
     * remainder is kept verbatim. That guarantees
     * `parse($x)?->toString() === $x` for every value compose() can produce.
     */
    public function parse(?string $medicalRecordNumber): ?MedicalRecordNumberParts
    {
        $value = trim((string) $medicalRecordNumber);

        if ($value === '') {
            return null;
        }

        $parts = explode('-', $value, 4);

        if (count($parts) !== 4) {
            return null;
        }

        [$prefix, $branchCode, $year, $sequence] = $parts;

        // The prefix is a fixed literal; anything else is not our format.
        if (strtoupper(trim($prefix)) !== self::PREFIX) {
            return null;
        }

        // compose() uppercases and trims the branch code, so a stored value is
        // already normalized. Reject anything with padding or a separator in
        // it rather than silently repairing it — a value that does not round
        // trip is not a canonical Nomor RM.
        if ($branchCode === '' || preg_match('/^[A-Z0-9]{2,16}$/', $branchCode) !== 1) {
            return null;
        }

        if (preg_match('/^\d{4}$/', $year) !== 1) {
            return null;
        }

        if (trim($sequence) === '') {
            return null;
        }

        return MedicalRecordNumberParts::make(self::PREFIX, $branchCode, $year, $sequence);
    }

    /**
     * The branch code encoded in a Nomor RM, or null when the value is not a
     * canonical medical record number.
     */
    public function branchCodeFrom(?string $medicalRecordNumber): ?string
    {
        return $this->parse($medicalRecordNumber)?->branchCode;
    }

    /**
     * Whether the composed medical record number already exists (including
     * soft-deleted patients). Optionally ignore a specific patient id so an
     * unchanged edit does not collide with itself.
     */
    public function exists(string $medicalRecordNumber, ?int $ignoreId = null): bool
    {
        return Patient::withTrashed()
            ->where('medical_record_number', $medicalRecordNumber)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function normalizeYear(int|string $year): string
    {
        $year = trim((string) $year);

        if (! preg_match('/^\d{4}$/', $year)) {
            throw new InvalidArgumentException('Registration year must be exactly four digits.');
        }

        return $year;
    }
}
