<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyImport\Completeness\Interfaces\LegacyPatientArchiveCompletenessRepositoryInterface;
use App\Modules\LegacyImport\Completeness\Support\LegacyArchiveCompleteness;
use App\Modules\LegacyImport\Completeness\Support\LegacyArchiveDocumentState;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessFilter;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessQuery;
use App\Modules\LegacyImport\Completeness\Support\LegacyPatientArchiveRow;
use App\Modules\LegacyImport\Services\LegacyImportHubService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the authority behind
 * "Kelengkapan Arsip Pasien Legacy".
 *
 * WHAT THIS PAGE IS. A read-only monitor over the legacy archive: which
 * legacy-IMPORTED patients do not yet hold both a published legacy RME and a
 * published legacy odontogram. It exists because the migration backlog was
 * otherwise invisible — an operator had to open each patient to find out.
 *
 * WHAT THIS PAGE IS NOT. It creates no patient, no import, no archive record,
 * no clinic visit, no medical record, no odontogram, no invoice, no payment, no
 * lab order and no SATUSEHAT candidate. It reviews nothing, publishes nothing,
 * voids nothing, cancels nothing and transitions no lifecycle. It assigns no
 * wave and changes no branch. The repository behind it exposes no write method,
 * and a test asserts the whole module introduces no mutating route.
 *
 * NATIVE PATIENTS ARE OUT OF SCOPE BY CONSTRUCTION. The provenance predicate
 * lives in the repository's single base query and cannot be switched off by a
 * filter, a parameter or a crafted request. A patient registered through the
 * ordinary application flow has `import_batch_id` NULL and can never appear
 * here, however old their Nomor RM looks and whether or not they have a native
 * RME.
 *
 * BRANCH SCOPE IS BORROWED, NOT INVENTED. The governance tier is
 * {@see LegacyImportHubService::GOVERNANCE_PERMISSIONS} — referenced, never
 * copied, because that constant is already defined as "the union of the two
 * archive capabilities' own governance sets" for the sibling page in this same
 * module and this same nav group. Two copies of that list would drift, and the
 * one that drifts is the one that widens somebody.
 *
 * WHY NOT LegacyOdontogramWorkspaceScope. Its governance set INCLUDES
 * `create_legacy_odontogram_imports`, which Admin Klinik and Front Office both
 * hold — so reusing it would promote every branch intake operator to
 * estate-wide visibility, which is the precise opposite of the requirement that
 * Admin Klinik stay branch-scoped. The hub's set contains only review, publish
 * and void, which in this system no intake role holds.
 *
 * WHY NOT RmeWorkingBranchScope. That is the CLINIC-OPERATIONS authority (visit
 * list, queue, cashier) and it fails closed to empty for a context-bound role
 * with no active online context. The legacy archive is a master-data/migration
 * surface whose own established authority is the BranchContext-based workspace
 * scope, which is also what resolves the Front Office branch pin. Borrowing the
 * operational scope would mean a second definition of the archive's branch
 * authority living on one page, which is how two surfaces come to disagree.
 *
 * MEASURED, SO THE REASONING STAYS HONEST: this route is NOT in
 * `EnsureRmeOnlineContext::EXEMPT_ROUTE_NAMES`, and that middleware is
 * global on `web`. A context-bound actor with no satisfied context is therefore
 * redirected to the selector BEFORE this service runs, so in practice
 * `BranchContext::forUser()` always resolves from the online context or the
 * Front Office pin and the empty-scope path is a backstop rather than a routine
 * state. The outcome is more restrictive than the choice above implies, never
 * less — the point of the choice is one definition, not a wider one.
 */
class LegacyPatientArchiveCompletenessService
{
    /**
     * The read permission for this page, and nothing else.
     *
     * Holding it grants no upload, no review, no publish, no void, no wave
     * governance and no cross-branch management. It deliberately does NOT
     * appear in any GOVERNANCE_PERMISSIONS list, so granting a reader this
     * permission can never widen their branch scope — the same reason
     * `view_legacy_odontogram_archive` is absent from the odontogram scope's
     * governance set.
     */
    public const READ_PERMISSION = 'view_legacy_patient_archive_completeness';

    public function __construct(
        private readonly LegacyPatientArchiveCompletenessRepositoryInterface $repository,
        private readonly BranchService $branches,
        private readonly BranchContext $context,
    ) {}

    /**
     * Whether the page exists at all.
     *
     * Gated on the legacy hub switch — the same operator-visible kill switch
     * the sibling "Ringkasan Import Legacy" page uses — and deliberately NOT on
     * the legacy RME / odontogram MIGRATION capability flags. Those govern
     * intake, their resting state is OFF, and a backlog monitor that disappeared
     * whenever intake was closed would be unavailable exactly when an operator
     * wants to plan the next wave. Reading what has already been published is a
     * separate concern from being allowed to publish more.
     */
    public function enabled(): bool
    {
        return (bool) config('legacy_import_hub.enabled', true);
    }

    public function isReachableBy(?User $user): bool
    {
        return $user !== null && $user->can(self::READ_PERMISSION);
    }

    /** True when this actor governs the legacy archive across every RME branch. */
    public function governsEveryBranch(User $user): bool
    {
        return $user->canAny(LegacyImportHubService::GOVERNANCE_PERMISSIONS);
    }

    /**
     * The branch ids this actor may read, resolved entirely server-side.
     *
     * Governance tier -> every active RME-enabled branch.
     * Everyone else   -> their own resolved BranchContext branch, and only when
     *                    it is an active RME branch. Unresolvable, MAIN, or a
     *                    non-RME branch all yield an EMPTY list, which the
     *                    repository turns into "no rows" rather than "no filter".
     *
     * @return list<int>
     */
    public function authorizedBranchIds(User $user): array
    {
        $all = $this->branches->rmeEnabledIds();

        if ($this->governsEveryBranch($user)) {
            return array_values(array_map('intval', $all));
        }

        $own = $this->context->forUser($user);

        if ($own === null) {
            return [];
        }

        return in_array((int) $own, array_map('intval', $all), true) ? [(int) $own] : [];
    }

    /**
     * Build the resolved query for one request.
     *
     * A REQUESTED BRANCH CAN ONLY NARROW. The authorized set is resolved first,
     * then intersected with any requested branch. A branch the actor may not
     * read is DROPPED rather than honoured, so a crafted `?branch_id=` leaves
     * the scope exactly as it was — it can never widen it, and it never denies
     * the whole page either (the established convention for an out-of-scope
     * filter value on a report).
     */
    public function resolveQuery(
        User $user,
        ?string $filter = null,
        ?string $search = null,
        ?int $requestedBranchId = null,
        int $perPage = LegacyCompletenessQuery::PER_PAGE,
    ): LegacyCompletenessQuery {
        $authorized = $this->authorizedBranchIds($user);
        $governs = $this->governsEveryBranch($user);

        $branchIds = $authorized;
        $includeUnscoped = $governs;

        if ($requestedBranchId !== null && in_array($requestedBranchId, $authorized, true)) {
            $branchIds = [$requestedBranchId];
            // An explicit branch filter is an explicit question about THAT
            // branch, so provenance-less rows are no longer part of the answer.
            $includeUnscoped = false;
        }

        $trimmed = $search === null ? null : trim($search);

        return new LegacyCompletenessQuery(
            branchIds: $branchIds,
            filter: LegacyCompletenessFilter::normalize($filter),
            search: ($trimmed === null || $trimmed === '') ? null : $trimmed,
            includeUnscopedBranch: $includeUnscoped,
            perPage: $perPage,
        );
    }

    /**
     * One page of rows, each already carrying its derived states.
     *
     * The paginator is transformed in place so the view receives
     * {@see LegacyPatientArchiveRow} objects and never a raw database row —
     * the DTO is the disclosure boundary, and a Blade template must not be
     * able to reach a column the report did not intend to publish.
     *
     * @return LengthAwarePaginator<int, LegacyPatientArchiveRow>
     */
    public function rows(LegacyCompletenessQuery $query): LengthAwarePaginator
    {
        $paginator = $this->repository->paginate($query);

        /** @var list<int> $patientIds */
        $patientIds = array_values(array_map(
            static fn (object $row): int => (int) $row->patient_id,
            $paginator->items(),
        ));

        // Bounded by the page size, not by the estate: three lookups for at
        // most `perPage` patients, so the query count is constant.
        // The query goes with the ids: the ids say which page, the query says
        // which patients this actor may read at all. Passing only the ids would
        // make the lookups trust their caller.
        $rmeStatuses = $this->repository->slotOccupyingRmeStatuses($query, $patientIds);
        $odontogramStatuses = $this->repository->slotOccupyingOdontogramStatuses($query, $patientIds);
        $blocked = $this->repository->reviewBlockedPatientIds($query, $patientIds);

        $blockedRme = $blocked[LegacyImportType::LEGACY_RME] ?? [];
        $blockedOdontogram = $blocked[LegacyImportType::LEGACY_ODONTOGRAM] ?? [];

        return $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (object $row): LegacyPatientArchiveRow => $this->toRow(
                    $row,
                    $rmeStatuses,
                    $odontogramStatuses,
                    $blockedRme,
                    $blockedOdontogram,
                ),
            ),
        );
    }

    /** @return array<string, int> */
    public function summary(LegacyCompletenessQuery $query): array
    {
        return $this->repository->summary($query);
    }

    /** @return array<int, string> */
    public function branchOptions(LegacyCompletenessQuery $query): array
    {
        return $this->repository->branchOptions($query);
    }

    /**
     * @param  array<int, string>  $rmeStatuses
     * @param  array<int, string>  $odontogramStatuses
     * @param  list<int>  $blockedRme
     * @param  list<int>  $blockedOdontogram
     */
    private function toRow(
        object $row,
        array $rmeStatuses,
        array $odontogramStatuses,
        array $blockedRme,
        array $blockedOdontogram,
    ): LegacyPatientArchiveRow {
        $patientId = (int) $row->patient_id;

        // The derivation order is the occupancy order — record first, staging
        // second — and it lives in LegacyArchiveDocumentState so the listing,
        // the counters and the labels cannot disagree about it.
        $rmeState = LegacyArchiveDocumentState::derive(
            (bool) (int) $row->rme_published,
            (bool) (int) $row->rme_active,
            (bool) (int) $row->rme_void,
        );

        $odontogramState = LegacyArchiveDocumentState::derive(
            (bool) (int) $row->odontogram_published,
            (bool) (int) $row->odontogram_active,
            (bool) (int) $row->odontogram_void,
        );

        return new LegacyPatientArchiveRow(
            patientId: $patientId,
            medicalRecordNumber: $row->medical_record_number === null ? null : (string) $row->medical_record_number,
            name: (string) $row->name,
            branchCode: $row->branch_code === null ? null : (string) $row->branch_code,
            branchName: $row->branch_name === null ? null : (string) $row->branch_name,
            rmeState: $rmeState,
            rmeRawStatus: $rmeStatuses[$patientId] ?? null,
            rmeReviewBlocked: $rmeState === LegacyArchiveDocumentState::IN_PROGRESS
                && in_array($patientId, $blockedRme, true),
            rmeArchiveDate: $this->asDateString($row->rme_archive_date ?? null),
            odontogramState: $odontogramState,
            odontogramRawStatus: $odontogramStatuses[$patientId] ?? null,
            odontogramReviewBlocked: $odontogramState === LegacyArchiveDocumentState::IN_PROGRESS
                && in_array($patientId, $blockedOdontogram, true),
            odontogramArchiveDate: $this->asDateString($row->odontogram_archive_date ?? null),
            completeness: LegacyArchiveCompleteness::derive($rmeState, $odontogramState),
            importBatchId: $row->import_batch_id === null ? null : (int) $row->import_batch_id,
            registeredAt: $this->asDateString($row->registered_at ?? null),
        );
    }

    /**
     * Normalise a driver-dependent date value to a plain `Y-m-d` string.
     *
     * PostgreSQL returns a date column as `2024-01-31` while SQLite may return
     * `2024-01-31 00:00:00`; the report shows a calendar date either way, and a
     * date is never shifted between timezones here because a historical archive
     * date is an opaque calendar fact.
     */
    private function asDateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return substr($text, 0, 10);
    }
}
