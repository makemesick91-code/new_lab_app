<?php

namespace App\Modules\PatientMerge\Services;

use App\Modules\Patient\Models\Patient;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — a merged patient receives no
 * new clinical activity.
 *
 * Enforced in the SERVICES that create activity (visit registration, legacy
 * archive intake, patient identity edits), never by hiding a button. Visit
 * registration additionally re-reads the patient under a SHARE lock, which
 * queues behind a running merge's FOR UPDATE lock: a visit racing a merge
 * either commits before the merge starts (and is then moved by it) or sees
 * the committed merged state and is refused — it can never land on the
 * merged row after the merge has emptied it.
 */
class PatientMergeGuard
{
    public const MESSAGE = 'Pasien ini telah digabungkan ke pasien lain dan tidak dapat menerima aktivitas baru. Gunakan data pasien hasil penggabungan.';

    public function assertNotMerged(?Patient $patient, string $field = 'patient_id'): void
    {
        if ($patient !== null && $patient->isMerged()) {
            throw ValidationException::withMessages([$field => self::MESSAGE]);
        }
    }

    /**
     * Re-read the patient under FOR SHARE and refuse if merged. Callers must
     * already be inside a transaction.
     */
    public function assertNotMergedLocked(int $patientId, string $field = 'patient_id'): void
    {
        $mergedInto = Patient::withTrashed()
            ->whereKey($patientId)
            ->sharedLock()
            ->value('merged_into_patient_id');

        if ($mergedInto !== null) {
            throw ValidationException::withMessages([$field => self::MESSAGE]);
        }
    }
}
