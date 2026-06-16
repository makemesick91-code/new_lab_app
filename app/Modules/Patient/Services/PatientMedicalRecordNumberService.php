<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\Patient;
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
