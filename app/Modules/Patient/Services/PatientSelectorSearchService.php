<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
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
 * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 supersedes this service's original
 * branch-scoped rule. The lookup is now GLOBAL PATIENT IDENTITY LOOKUP:
 *
 *     PATIENT IDENTITY  !=  VISIT BRANCH AUTHORITY
 *
 * An authorized registration operator searches the whole RME patient registry by
 * name or Nomor RM, so a patient who registered at Telkomas can be served at
 * Landak without anyone switching work branch. What that emphatically does NOT
 * change is where the visit happens: the new ClinicVisit branch still comes from
 * the operator's authorized daily working context, never from the patient's RM
 * origin branch and never from a request `branch_id`. Selecting a cross-branch
 * patient reuses the existing master row — no RM rewrite, no duplicate, no
 * branch move, no context change.
 *
 * Equally deliberate: global IDENTITY discovery is not global CLINICAL HISTORY
 * access. Being findable here grants exactly enough to identify and select a
 * patient for a new authorized visit. Visit history, RME, odontogram and payment
 * authorization are untouched by this service and stay independently scoped.
 *
 * Authorization, by design:
 *  - Registration authority is the gate. The endpoint sits behind the ClinicVisit
 *    `create` ability, so global does not mean publicly enumerable.
 *  - Branch scope is the active RME-enabled set — see {@see authorizedBranchIds}
 *    for why that, and not every row in mst_patients, is "the registry".
 *  - A context-bound role (Admin Klinik / Perawat / Kasir) still FAILS CLOSED to
 *    no results when it has no valid working context. That protection predates
 *    this revision and survives it.
 *  - {@see RmeWorkingBranchScope} itself is NOT relaxed. It remains the canonical
 *    working-branch authority for every other surface; this service simply stops
 *    using it as the identity-lookup boundary.
 *  - Doctors are additionally narrowed to their own RM scope via
 *    {@see DoctorPatientScopeService}, exactly as every other doctor-facing
 *    patient query already is. That is a CLINICAL scope, not a branch scope, and
 *    widening branch reach for registration must not widen it.
 *  - Legacy patients without a Cabang RME (`branch_id` null) stay selectable,
 *    mirroring the Lab Request selector; they would otherwise become
 *    unregisterable.
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
        private readonly BranchService $branches,
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
     * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — the branches whose patients
     * this registration lookup may read.
     *
     * GLOBAL, deliberately. A patient who first registered at Telkomas and
     * walks into Landak today must be findable by the Landak operator. So the
     * answer is the whole RME patient registry, not the operator's own branch.
     *
     * "The whole registry" means every ACTIVE, RME-enabled branch — the exact
     * set a governance role (Owner, Supervisor RME, Super Admin) already reads
     * today, plus the legacy no-branch patients {@see selectableQuery} keeps
     * selectable. It is not "every row in mst_patients": MAIN, disabled and
     * non-RME branches stay out, because nobody may register a visit there and
     * exposing them would widen disclosure past what the change requires.
     *
     * Two guards remain, and they are the reason this is a wider scope rather
     * than an absent one:
     *
     *  - Registration authority is still required. The endpoint is behind the
     *    ClinicVisit `create` ability, so a user who may not register a visit
     *    cannot enumerate the registry through it.
     *  - A context-bound operator (Admin Klinik / Perawat / Kasir) still FAILS
     *    CLOSED without a valid working context. Global means "any branch's
     *    patient", not "no authority required": someone who is not working
     *    anywhere cannot register anywhere, so they read nothing here either.
     *    This is the same protection the working-branch scope gave, kept intact.
     *
     * A request value never reaches this method — the combobox sends no branch
     * at all — so nothing here can be widened or re-pointed from the query
     * string. And this is NOT a relaxation of {@see RmeWorkingBranchScope}: that
     * canonical authority is untouched and every other surface it scopes (visit
     * list, patient queue, RME reports, cashier, receivables) keeps reading one
     * working branch. Only THIS registration identity lookup is global.
     *
     * @return array<int, int>
     */
    private function authorizedBranchIds(?User $user): array
    {
        // Fail closed on an unauthenticated caller. This selector is also
        // rendered from a Blade component, so it refuses rather than inheriting
        // an estate-wide default if it is ever resolved outside a request.
        if ($user === null) {
            return [];
        }

        if ($this->workingScope->isContextBound($user) && $this->workingScope->activeBranchId($user) === null) {
            return [];
        }

        return $this->branches->rmeEnabledIds();
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
