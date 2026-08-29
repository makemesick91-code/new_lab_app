<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — the authorized, bounded,
 * least-disclosure patient lookup behind the "Kunjungan Baru" combobox.
 *
 * This replaces a control that rendered EVERY patient row (across every branch,
 * including each patient's phone number) into the create-visit HTML and filtered
 * them in the browser. Nothing is preloaded any more: the browser asks, the
 * server decides what may be seen, and only the four identity fields the
 * dropdown actually draws come back.
 *
 * Authorization, by design:
 *  - Branch scope is delegated to {@see RmeWorkingBranchScope}, the canonical
 *    "which RME branches may this workspace read?" authority. A context-bound
 *    role (Admin Klinik / Perawat / Kasir) therefore searches ONLY its active
 *    working-branch — the daily branch context — and FAILS CLOSED to no results
 *    when it has no valid context. Scope is never re-derived here and a request
 *    `branch_id` is never consulted, so a crafted query cannot widen it.
 *  - Doctors are additionally narrowed to their own RM scope via
 *    {@see DoctorPatientScopeService}, exactly as every other doctor-facing
 *    patient query already is.
 *  - Legacy patients without a Cabang RME (`branch_id` null) stay selectable for
 *    an in-scope operator, mirroring the Lab Request selector; they would
 *    otherwise become unregisterable.
 *
 * This is NOT {@see CrossBranchPatientLookupService}. That service is the
 * deliberate cross-branch Nomor RM *duplicate-detection* panel; it never returns
 * a patient id and must not become a selection source. The two stay separate.
 *
 * Deliberately unchanged from the control this replaces: inactive patients are
 * still selectable. Excluding them would be an operational policy change nobody
 * asked for, and "inactive" is not "unauthorized".
 */
class PatientSelectorSearchService
{
    /**
     * Characters required before the server is asked anything. Two is enough for
     * "nur" style name prefixes and for a short RM tail, while guaranteeing an
     * empty or one-character box can never turn into a patient dump.
     */
    public const MIN_QUERY_LENGTH = 2;

    /**
     * Hard server-side ceiling on returned rows. Sized like the sibling
     * {@see CrossBranchPatientLookupService::DISPLAY_LIMIT} (10) but a little
     * roomier, because this list is a working selector rather than a duplicate
     * warning: 15 rows still fits one scroll-free dropdown, and an operator who
     * needs more should type more. The client cannot raise it — the request
     * carries no limit parameter at all.
     */
    public const RESULT_LIMIT = 15;

    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly RmeWorkingBranchScope $workingScope,
        private readonly DoctorPatientScopeService $doctorScope,
    ) {}

    /**
     * Search the authorized patient set by name or Nomor RM.
     *
     * @return array{
     *     query: string,
     *     searched: bool,
     *     too_short: bool,
     *     min_length: int,
     *     limit: int,
     *     results: array<int, array{id: int, name: string, medical_record_number: string, branch_label: string}>
     * }
     */
    public function search(?User $user, ?string $term): array
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return [
                'query' => $term,
                'searched' => false,
                'too_short' => $term !== '',
                'min_length' => self::MIN_QUERY_LENGTH,
                'limit' => self::RESULT_LIMIT,
                'results' => [],
            ];
        }

        $patients = $this->patients->searchSelectable(
            $this->authorizedBranchIds($user),
            $term,
            self::RESULT_LIMIT,
            $this->doctorScopeFor($user),
        );

        return [
            'query' => $term,
            'searched' => true,
            'too_short' => false,
            'min_length' => self::MIN_QUERY_LENGTH,
            'limit' => self::RESULT_LIMIT,
            'results' => $patients->map(fn (Patient $patient): array => $this->toOption($patient))->values()->all(),
        ];
    }

    /**
     * The server-side answer to "may this user attach this patient to a visit?".
     *
     * Being able to see a row in the dropdown is a UI convenience; THIS is the
     * boundary. It is asserted again at submit time so a hand-crafted
     * `patient_id` for another branch's patient is rejected even though the
     * combobox would never have offered it.
     */
    public function isSelectable(?User $user, ?int $patientId): bool
    {
        return $this->findSelectable($user, $patientId) !== null;
    }

    public function findSelectable(?User $user, ?int $patientId): ?Patient
    {
        if ($patientId === null || $patientId <= 0) {
            return null;
        }

        return $this->patients->findSelectable(
            $this->authorizedBranchIds($user),
            $patientId,
            $this->doctorScopeFor($user),
        );
    }

    /**
     * Prefill payload for an already-chosen patient (validation redraw, or a
     * `?patient_id=` deep link from the queue). Returns null when the patient is
     * outside the authorized scope, so a crafted link cannot leak a name.
     *
     * @return array{id: int, name: string, medical_record_number: string, branch_label: string}|null
     */
    public function selectedOption(?User $user, ?int $patientId): ?array
    {
        $patient = $this->findSelectable($user, $patientId);

        return $patient === null ? null : $this->toOption($patient);
    }

    /**
     * The ONLY fields that leave the server for this control.
     *
     * Never phone, WhatsApp, KTP/NIK, address, date of birth, email, occupation
     * or anything clinical. `branch_label` is operational, non-sensitive
     * identity metadata — the same field {@see CrossBranchPatientLookupService}
     * already classifies as safe — and for an RM-bearing patient it merely
     * restates the branch code already inside the Nomor RM.
     *
     * @return array{id: int, name: string, medical_record_number: string, branch_label: string}
     */
    private function toOption(Patient $patient): array
    {
        return [
            'id' => (int) $patient->id,
            'name' => (string) $patient->name,
            'medical_record_number' => (string) ($patient->medical_record_number ?? ''),
            'branch_label' => $patient->branchLabel(),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function authorizedBranchIds(?User $user): array
    {
        // Fail closed on an unauthenticated caller. RmeWorkingBranchScope answers
        // "every active RME branch" for a null user because all of its own
        // callers sit behind `auth`; this selector is additionally rendered from
        // a Blade component, so it refuses rather than inheriting an estate-wide
        // default if it is ever resolved outside a request.
        if ($user === null) {
            return [];
        }

        // Never narrowed by a request value: the combobox sends no branch at all,
        // and the canonical scope is the whole authority.
        return $this->workingScope->branchIdsFor($user);
    }

    /**
     * @return null|\Closure(Builder<Patient>): Builder<Patient>
     */
    private function doctorScopeFor(?User $user): ?\Closure
    {
        if ($user === null) {
            return null;
        }

        return fn (Builder $query): Builder => $this->doctorScope->applyPatientScopeForUser($user, $query);
    }
}
