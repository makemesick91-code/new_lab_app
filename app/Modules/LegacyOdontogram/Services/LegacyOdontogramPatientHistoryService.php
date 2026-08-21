<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramRecordPolicy;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Support\Collection;

/**
 * FIX-04b — the read-only projection of a patient's PUBLISHED legacy odontogram
 * archive, for callers that render it next to the patient's native odontograms.
 *
 * THIS IS THE PUBLISHED INTEGRATION POINT. Another surface (the odontogram
 * history screen) calls {@see publishedRecordsFor()} to fold the archive into
 * what a doctor sees, so its signature and its return shape are a contract:
 *
 *     publishedRecordsFor(User $user, int $patientId): Collection<int, LegacyOdontogramRecord>
 *
 * Every item exposes at least `id`, `odontogram_date` (a Carbon date),
 * `page_count`, the `branch` relation (already eager-loaded, with its `code`),
 * and — through its `id` — a route-able identifier for
 * `rme.legacy-odontograms.show`. The `branch` relation is eager-loaded by the
 * repository precisely so a caller rendering a list of records does not trigger
 * one query per row.
 *
 * IT NEVER THROWS. A caller renders this inside a page that must still work for
 * a user with no archive access at all, so "you may not read this" is an EMPTY
 * COLLECTION, not an exception the caller has to catch. That is a rendering
 * decision, not a weakened boundary: the same three checks that guard the
 * viewer are applied here, and anything they refuse simply does not appear.
 *
 * WHAT APPEARS. ONLY records in status PUBLISHED. A staged import — draft,
 * queued, processing, ready-for-review, reviewed, failed or cancelled — is work
 * in progress and must never look like part of the patient's clinical history.
 * A VOIDed record is likewise excluded (the repository's published-only finder
 * enforces both).
 *
 * ORDERING. By the CLINICAL date (`odontogram_date`), never by upload or
 * creation time: a chart archived today is a document from years ago and must
 * sort where it clinically belongs.
 *
 * NOT GATED ON THE MIGRATION CAPABILITY. This deliberately does NOT consult
 * LegacyOdontogramFeatureGuard. That flag switches legacy MIGRATION on and off;
 * it is not a statement about whether evidence that was already published is
 * part of the patient's history. It is — so a doctor treating this patient
 * keeps reading it at the next visit with migration switched off.
 *
 * The read boundary is complete without the flag: PUBLISHED records only, a
 * read permission from LegacyOdontogramRecordPolicy::READ_PERMISSIONS,
 * server-resolved branch scope, and for a doctor a real clinical relationship
 * with the patient (DoctorPatientScopeService) — so the archive can never be a
 * wider door than the patient's native record.
 */
class LegacyOdontogramPatientHistoryService
{
    public function __construct(
        private readonly LegacyOdontogramRecordRepositoryInterface $records,
        private readonly LegacyOdontogramWorkspaceScope $scope,
        private readonly DoctorPatientScopeService $doctorScope,
    ) {}

    /**
     * Published, non-voided legacy odontogram records this user may see for a
     * patient, oldest clinical date first.
     *
     * @return Collection<int, LegacyOdontogramRecord>
     */
    public function publishedRecordsFor(User $user, int $patientId): Collection
    {
        // 1. Read permission. The set is taken from the policy so the history
        //    surface and the viewer can never disagree about who may read.
        if (! $user->canAny(LegacyOdontogramRecordPolicy::READ_PERMISSIONS)) {
            return collect();
        }

        // 2. The treating relationship, for a doctor. Branch scope alone would
        //    make the archive broader than the native record: a same-branch
        //    doctor with no relationship to this patient cannot open their
        //    native odontogram and must not list their archive either.
        if ($this->doctorScope->shouldApplyDoctorScope($user)) {
            $patient = Patient::query()->find($patientId);

            if (! $patient instanceof Patient || ! $this->doctorScope->doctorCanAccessPatient($user, $patient)) {
                return collect();
            }
        }

        // 3. Branch scope, resolved server-side from the caller. An empty scope
        //    makes the repository return nothing rather than everything.
        return $this->records->listPublishedForPatientInBranches(
            $this->scope->branchIdsFor($user),
            $patientId,
            $this->scope->includesUnscopedRowsFor($user),
        );
    }

    /**
     * Whether this patient has any legacy odontogram archive worth showing to
     * this user — so a caller can keep the whole section out of the way rather
     * than rendering an empty panel.
     */
    public function hasLegacyOdontograms(User $user, int $patientId): bool
    {
        return $this->publishedRecordsFor($user, $patientId)->isNotEmpty();
    }
}
