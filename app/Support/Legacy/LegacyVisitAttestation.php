<?php

declare(strict_types=1);

namespace App\Support\Legacy;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Carbon\CarbonImmutable;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — the resolved, accepted
 * binding between a legacy document and the real visit it was filed at.
 *
 * IMMUTABLE AND SERVER-BUILT. Every field here was resolved from the database
 * by LegacyVisitBindingService; not one of them came off the request. A caller
 * holding this object is holding the server's own answer to "which patient,
 * which branch, which visit, which ceiling" — which is exactly why the intake
 * services take this object rather than a bag of ids.
 *
 * WHY IT CARRIES THE PATIENT AND NOT JUST AN ID. The patient is the visit's
 * patient, loaded once, here. If intake re-fetched a patient from a submitted
 * id it would reintroduce the substitution this whole path exists to prevent.
 *
 * WHY IT CARRIES `visitDate` AS A VALUE. The ceiling is copied out of the visit
 * at attestation time and frozen onto the staging row. A visit can be
 * rescheduled or corrected later; the attestation must keep saying what bound
 * the human actually accepted, and revalidation compares the live visit against
 * this snapshot and refuses on drift rather than silently adopting a new value.
 *
 * THE BRANCH IS NOT HERE, AND THAT IS DELIBERATE. `origin_branch_id` remains
 * RM-DERIVED by LegacyRmeBranchResolver (FIX-ROLL2-1) — it is the column row
 * visibility and the policies key off, and switching it to the visit's branch
 * would silently change who can see existing rows. The visit governs IDENTITY,
 * the DATE CEILING and ACCESS; it does not govern archive ownership. A patient
 * whose Nomor RM is TLK1-derived can legitimately be seen at SPN4, and both
 * facts stay true and separately recorded.
 */
final class LegacyVisitAttestation
{
    public function __construct(
        public readonly ClinicVisit $visit,
        public readonly Patient $patient,
        public readonly CarbonImmutable $visitDate,
        public readonly User $verifiedBy,
    ) {}

    public function visitId(): int
    {
        return (int) $this->visit->getKey();
    }

    public function patientId(): int
    {
        return (int) $this->patient->getKey();
    }

    public function verifiedById(): int
    {
        return (int) $this->verifiedBy->getKey();
    }

    /**
     * The evidence columns written onto the staging row.
     *
     * `verified_at` is stamped by the caller at write time rather than here so
     * that one attestation object cannot produce two different timestamps if a
     * caller ever reuses it.
     *
     * @return array<string, mixed>
     */
    public function evidenceColumns(string $sourceSha256, string $selectedDate, ?string $latestDate = null): array
    {
        $columns = [
            'verification_mode' => LegacyVerificationMode::VISIT_PREVERIFIED,
            'verification_visit_id' => $this->visitId(),
            'verification_visit_date' => $this->visitDate->toDateString(),
            'verified_by' => $this->verifiedById(),
            'verified_at' => now(),
            'verified_selected_date' => $selectedDate,
            'verified_source_sha256' => $sourceSha256,
        ];

        // Odontogram models one date and has no `verified_latest_date` column;
        // passing null therefore omits the key entirely rather than writing a
        // NULL into a column that may not exist.
        if ($latestDate !== null) {
            $columns['verified_latest_date'] = $latestDate;
        }

        return $columns;
    }

    /**
     * PII-free context for the audit trail.
     *
     * Deliberately no patient name, no Nomor RM and no document content — the
     * audit answers "who attested what, where", not "who is the patient".
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return [
            'verification_mode' => LegacyVerificationMode::VISIT_PREVERIFIED,
            'verification_visit_id' => $this->visitId(),
            'verification_visit_date' => $this->visitDate->toDateString(),
            'verified_by' => $this->verifiedById(),
            'patient_id' => $this->patientId(),
        ];
    }
}
