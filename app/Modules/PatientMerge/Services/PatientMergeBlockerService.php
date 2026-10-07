<?php

namespace App\Modules\PatientMerge\Services;

use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotOccupancy;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Services\PatientEarliestNativeOdontogramDateResolver;
use App\Modules\LegacyRme\Services\PatientEarliestNativeRmeDateResolver;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Support\PatientMergeField;

/**
 * Everything that must stop a merge, evaluated the same way for the preview
 * a reviewer reads and — again, under row locks — inside the merge itself.
 *
 * Every blocker is FAIL CLOSED. None of them is resolved by the merge on the
 * operator's behalf: a KTP held by a third patient is never taken from that
 * patient, a second active legacy archive is never voided, an in-flight legacy
 * import is never cancelled. The operator fixes the cause through its own
 * canonical flow, then the merge can proceed.
 */
class PatientMergeBlockerService
{
    public function __construct(
        private readonly PatientMergeOwnershipRepositoryInterface $ownership,
        private readonly LegacySingleActiveDocumentService $legacySlots,
        private readonly PatientIdentityComparator $comparator,
        private readonly PatientEarliestNativeRmeDateResolver $nativeRme,
        private readonly PatientEarliestNativeOdontogramDateResolver $nativeOdontogram,
    ) {}

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function blockers(PatientMergeCase $case, ?Patient $a, ?Patient $b, bool $requireCanonical = true): array
    {
        $blockers = [];

        if ($a === null || $b === null) {
            return [$this->blocker('PATIENT_MISSING', 'Salah satu pasien tidak ditemukan.')];
        }

        if ($a->id === $b->id) {
            $blockers[] = $this->blocker('PATIENT_SAME', 'Pasien A dan Pasien B adalah data yang sama.');
        }

        foreach (['A' => $a, 'B' => $b] as $label => $patient) {
            if ($patient->trashed()) {
                $blockers[] = $this->blocker('PATIENT_DELETED', "Pasien {$label} sudah dihapus dan tidak dapat digabungkan.");
            }

            if ($patient->isMerged()) {
                $blockers[] = $this->blocker('PATIENT_ALREADY_MERGED', "Pasien {$label} sudah digabungkan ke pasien lain. Gunakan pasien hasil penggabungan.");
            }
        }

        if ($requireCanonical && ! in_array($case->canonical_patient_id, [$a->id, $b->id], true)) {
            $blockers[] = $this->blocker('CANONICAL_NOT_SELECTED', 'Pasien canonical (data aktif hasil penggabungan) belum dipilih.');
        }

        $resolutions = $case->fieldResolutions->keyBy('field');
        $unresolved = [];

        foreach (PatientMergeField::fields() as $field) {
            if (! ($resolutions[$field] ?? null)?->isResolved()) {
                $unresolved[] = PatientMergeField::label($field);
            }
        }

        if ($unresolved !== []) {
            $blockers[] = $this->blocker('UNRESOLVED_IDENTITY_CONFLICT', 'Konflik identitas belum diselesaikan: '.implode(', ', $unresolved).'.');
        }

        $finalKtp = $resolutions['ktp_number'] ?? null;
        $finalKtpValue = $finalKtp?->isResolved() ? $finalKtp->final_value_encrypted : null;

        if (is_string($finalKtpValue) && $finalKtpValue !== '' && $this->ownership->ktpOwner($finalKtpValue, [$a->id, $b->id]) !== null) {
            // Never says who: the third patient may be outside the operator's scope.
            $blockers[] = $this->blocker('KTP_OWNED_BY_OTHER_PATIENT', 'NIK/KTP final sudah terdaftar pada pasien lain (bukan Pasien A/B). Selesaikan konflik identitas tersebut terlebih dahulu; penggabungan tidak akan mengambil NIK dari pasien lain.');
        }

        foreach ([LegacyImportType::LEGACY_RME => 'RME Lama', LegacyImportType::LEGACY_ODONTOGRAM => 'Odontogram Lama'] as $type => $label) {
            $slotA = $this->legacySlots->occupancyFor($type, $a->id);
            $slotB = $this->legacySlots->occupancyFor($type, $b->id);

            if ($this->inFlight($slotA) || $this->inFlight($slotB)) {
                $blockers[] = $this->blocker('LEGACY_IMPORT_IN_FLIGHT', "Masih ada impor arsip {$label} yang sedang diproses/direview. Selesaikan (publish atau batalkan) melalui alurnya sendiri sebelum menggabungkan.");
            } elseif ($slotA->occupied && $slotB->occupied) {
                $blockers[] = $this->blocker('LEGACY_SLOT_CONFLICT', "Kedua pasien sama-sama memiliki arsip {$label} aktif. Satu pasien hanya boleh memiliki satu arsip aktif; VOID salah satunya melalui alur arsip sebelum menggabungkan.");
            }
        }

        // A live encounter on either patient: merging would leave the canonical
        // patient with an ambiguous active visit (doctor locked out) and lets
        // writers that read patient_id from that visit race the merge.
        if ($this->ownership->activeEncounterCount([$a->id, $b->id]) > 0) {
            $blockers[] = $this->blocker('ACTIVE_ENCOUNTER', 'Masih ada kunjungan yang berjalan (terdaftar, menunggu, diperiksa, atau menunggu kasir) pada salah satu pasien. Selesaikan atau batalkan kunjungan tersebut sebelum menggabungkan.');
        }

        // SATUSEHAT identifiers are a non-FK patient reference. The merge never
        // re-points or discards one: a source holding an identifier (or both
        // patients holding one) needs a supervised reconciliation first.
        $sourceIds = $this->sourceCandidates($case, $a, $b);
        $withIdentifier = array_filter([$a->id, $b->id], fn (int $id): bool => $this->ownership->activeSatusehatPatientIdentifiers($id) > 0);

        if (count($withIdentifier) === 2 || array_intersect($withIdentifier, $sourceIds) !== []) {
            $blockers[] = $this->blocker('SATUSEHAT_IDENTIFIER_PRESENT', 'Pasien yang akan digabungkan memiliki identitas SATUSEHAT aktif. Rekonsiliasi identitas SATUSEHAT harus dilakukan secara terawasi sebelum menggabungkan.');
        }

        // The legacy archive must stay strictly older than every native record
        // of the merged person (rule 90/111/132), on the combined timeline.
        foreach ($this->legacyDateViolations($a->id, $b->id) as $label) {
            $blockers[] = $this->blocker('LEGACY_DATE_RULE_VIOLATION', "Tanggal arsip {$label} salah satu pasien tidak lebih awal dari catatan native pasien lainnya. Setelah digabung, arsip lama harus tetap lebih tua dari seluruh catatan native; perbaiki tanggal/arsip melalui alurnya sendiri.");
        }

        $unregistered = $this->ownership->unregisteredPatientForeignKeys();

        if ($unregistered !== []) {
            $blockers[] = $this->blocker('OWNERSHIP_REGISTRY_INCOMPLETE', 'Terdapat tabel data pasien yang belum terdaftar dalam aturan penggabungan ('.implode(', ', $unregistered).'). Penggabungan ditolak agar tidak ada data yang tertinggal.');
        }

        return $blockers;
    }

    /** Risk flags that earn an enhanced warning (they do not block by themselves). */
    public function riskFlags(Patient $a, Patient $b, array $countsA, array $countsB, bool $crossBranch): array
    {
        $flags = [];
        $comparison = $this->comparator->compare($a, $b);

        foreach (['ktp_number' => 'ktp_conflict', 'date_of_birth' => 'dob_conflict', 'gender' => 'gender_conflict'] as $field => $flag) {
            if ($comparison[$field]['status'] === PatientMergeField::CONFLICT) {
                $flags[] = $flag;
            }
        }

        if (($countsA['medical_records'] ?? 0) > 0 && ($countsB['medical_records'] ?? 0) > 0) {
            $flags[] = 'both_have_rme';
        }

        if (($countsA['odontograms'] ?? 0) > 0 && ($countsB['odontograms'] ?? 0) > 0) {
            $flags[] = 'both_have_odontogram';
        }

        if (($countsA['receivables'] ?? 0) > 0 && ($countsB['receivables'] ?? 0) > 0) {
            $flags[] = 'both_have_receivables';
        }

        $legacyA = ($countsA['legacy_rme'] ?? 0) + ($countsA['legacy_odontogram'] ?? 0);
        $legacyB = ($countsB['legacy_rme'] ?? 0) + ($countsB['legacy_odontogram'] ?? 0);

        // One record is migrated-only history, the other is native clinical
        // activity: the timeline will interleave archive and native entries.
        if (($legacyA > 0 && ($countsB['visits'] ?? 0) > 0) || ($legacyB > 0 && ($countsA['visits'] ?? 0) > 0)) {
            $flags[] = 'legacy_native_mix';
        }

        if ($crossBranch) {
            $flags[] = 'cross_branch';
        }

        return array_values(array_unique($flags));
    }

    /** @return array<int, int> patients that will become the source */
    private function sourceCandidates(PatientMergeCase $case, Patient $a, Patient $b): array
    {
        return match ($case->canonical_patient_id) {
            $a->id => [$b->id],
            $b->id => [$a->id],
            default => [$a->id, $b->id],
        };
    }

    /** @return array<int, string> labels of the archive types that would break the date rule */
    private function legacyDateViolations(int $aId, int $bId): array
    {
        $checks = [
            'RME Lama' => [
                fn (int $id): ?string => $this->ownership->latestPublishedLegacyRmeDate($id),
                fn (int $id): ?string => $this->nativeRme->resolveAsDateString($id),
            ],
            'Odontogram Lama' => [
                fn (int $id): ?string => $this->ownership->latestPublishedLegacyOdontogramDate($id),
                fn (int $id): ?string => $this->nativeOdontogram->resolveAsDateString($id),
            ],
        ];

        $violations = [];

        foreach ($checks as $label => [$latestLegacy, $earliestNative]) {
            $legacy = array_filter([$latestLegacy($aId), $latestLegacy($bId)]);

            if ($legacy === []) {
                continue;
            }

            $native = array_filter([$earliestNative($aId), $earliestNative($bId)]);

            if ($native !== [] && max($legacy) >= min($native)) {
                $violations[] = $label;
            }
        }

        return $violations;
    }

    private function inFlight(LegacyDocumentSlotOccupancy $slot): bool
    {
        return $slot->occupied && $slot->reason === LegacyDocumentSlotOccupancy::REASON_ACTIVE_IMPORT_EXISTS;
    }

    /** @return array{code: string, message: string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
