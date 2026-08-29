<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeOnlineContext\Interfaces\BranchChangeRequestRepositoryInterface;
use App\Modules\RmeOnlineContext\Interfaces\DailyBranchContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Interfaces\UserOnlineContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the Super Admin approval workflow that
 * is the ONLY way a locked working branch moves mid-day.
 *
 * ── AN APPROVAL IS AN EVENT, NOT A CREDENTIAL ─────────────────────────────
 *
 * The branch switch is applied INSIDE the approval transaction. There is no
 * "approved" token the requester can carry to a second endpoint and replay,
 * because there is no second endpoint. Once the transaction commits the row is
 * APPROVED with `applied_at` set, and any further attempt finds a row that is no
 * longer PENDING. Single-use is a property of the design, not a counter someone
 * has to remember to decrement.
 *
 * ── EVERY BINDING IS RE-ASSERTED UNDER A LOCK ─────────────────────────────
 *
 *   USER         the row carries `requester_user_id`; the daily context locked
 *                is that user's, never the approver's and never a request field
 *   SOURCE       the live context must STILL sit on `source_branch_id`. If it
 *                moved after the request was filed the approval is refused as
 *                stale rather than applied against a different starting point
 *   DESTINATION  re-validated for eligibility at approval time — an approval is
 *                not a grant of access to a branch the user could not otherwise
 *                work in, and a branch deactivated in the meantime is refused
 *   CLINICAL DAY the row's `clinical_date` must be today. A yesterday request
 *                is refused whether or not any job has stamped it EXPIRED
 *
 * ── THE APPROVAL RACE ─────────────────────────────────────────────────────
 *
 * Two Super Admins approving the same request simultaneously serialise on
 * `lockById()`. The second one to acquire the lock re-reads a row that is no
 * longer PENDING and is refused, so the switch is applied exactly once and
 * `change_count` can never double-increment.
 */
class BranchChangeApprovalService
{
    public const ENTITY_TYPE = 'trx_branch_change_requests';

    public function __construct(
        private readonly BranchChangeRequestRepositoryInterface $requests,
        private readonly DailyBranchContextRepositoryInterface $contexts,
        private readonly UserOnlineContextRepositoryInterface $onlineContexts,
        private readonly BranchRepositoryInterface $branches,
        private readonly DailyBranchContextService $daily,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * File a request to move today's locked working branch.
     *
     * `source_branch_id`, `clinical_date`, `role_context` and
     * `requester_user_id` are all derived server-side. The requester supplies
     * exactly two things: where they want to go, and why.
     *
     * @throws ValidationException
     */
    public function request(User $requester, int $destinationBranchId, string $reason): BranchChangeRequest
    {
        $clinicalDate = $this->daily->clinicalToday();
        $context = $this->daily->currentFor($requester);

        if ($context === null) {
            // Nothing to move. A user whose day is still open should simply make
            // their free first selection.
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Anda belum memilih cabang kerja hari ini. Pilih cabang terlebih dahulu.',
            ]);
        }

        if (! DailyBranchContextService::isLockedRoleContext((string) $context->role_context)) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Konteks kerja Anda tidak memerlukan persetujuan perpindahan cabang.',
            ]);
        }

        if ((int) $context->current_branch_id === $destinationBranchId) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Cabang tujuan sama dengan cabang kerja Anda saat ini.',
            ]);
        }

        $this->assertEligibleDestination($destinationBranchId);

        try {
            $request = $this->requests->create([
                'requester_user_id' => (int) $requester->id,
                'clinical_date' => $clinicalDate,
                'role_context' => (string) $context->role_context,
                'source_branch_id' => (int) $context->current_branch_id,
                'destination_branch_id' => $destinationBranchId,
                'reason' => $reason,
                'requested_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // The partial unique index refused a second PENDING row for this
            // user and day — including the double-submit an application-level
            // check would have raced through.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'destination_branch_id' => 'Anda sudah memiliki permintaan perpindahan cabang yang menunggu persetujuan.',
            ]);
        }

        $this->audit->log(
            self::ENTITY_TYPE,
            (int) $request->id,
            'BRANCH_CHANGE_REQUESTED',
            null,
            $this->auditPayload($request),
            $requester,
        );

        return $request;
    }

    /**
     * Approve the request AND apply the switch, atomically.
     *
     * @throws ValidationException
     */
    public function approve(int $requestId, User $approver, ?string $decisionNote = null): BranchChangeRequest
    {
        return DB::transaction(function () use ($requestId, $approver, $decisionNote): BranchChangeRequest {
            $request = $this->lockPendingRequest($requestId, $approver);

            $context = $this->contexts->lockForUser(
                (int) $request->requester_user_id,
                (string) $request->clinical_date->toDateString(),
            );

            if ($context === null) {
                throw ValidationException::withMessages([
                    'request' => 'Konteks cabang harian pemohon tidak ditemukan. Permintaan tidak dapat disetujui.',
                ]);
            }

            // STALE-SOURCE GUARD. The context must still be where the request
            // said it was. Approving a request whose starting point has moved
            // would silently apply it against a branch the approver never saw.
            if ((int) $context->current_branch_id !== (int) $request->source_branch_id) {
                throw ValidationException::withMessages([
                    'request' => 'Permintaan tidak lagi valid karena konteks cabang telah berubah. Minta pemohon mengajukan ulang.',
                ]);
            }

            // An approval never confers access to a branch the user could not
            // otherwise work in, and never resurrects a deactivated one.
            $this->assertEligibleDestination((int) $request->destination_branch_id);

            $before = $this->auditPayload($request) + [
                'context_current_branch_id' => (int) $context->current_branch_id,
            ];

            $this->contexts->update($context, [
                'current_branch_id' => (int) $request->destination_branch_id,
                'last_changed_at' => now(),
                'change_count' => (int) $context->change_count + 1,
            ]);

            // Keep the session representation consistent with the authority, so
            // the operator's existing sessions resolve to the new branch on
            // their very next request instead of lingering on the old one.
            $this->realignOnlineContext(
                (int) $request->requester_user_id,
                (int) $request->destination_branch_id,
            );

            $approved = $this->requests->update($request, [
                'status' => BranchChangeRequest::STATUS_APPROVED,
                'decided_by_user_id' => (int) $approver->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
                'applied_at' => now(),
            ]);

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $approved->id,
                'BRANCH_CHANGE_APPROVED',
                $before,
                $this->auditPayload($approved) + [
                    'context_current_branch_id' => (int) $approved->destination_branch_id,
                ],
                $approver,
            );

            return $approved;
        });
    }

    /**
     * Reject the request. The working branch is left exactly as it was.
     *
     * @throws ValidationException
     */
    public function reject(int $requestId, User $approver, ?string $decisionNote = null): BranchChangeRequest
    {
        return DB::transaction(function () use ($requestId, $approver, $decisionNote): BranchChangeRequest {
            $request = $this->lockPendingRequest($requestId, $approver);
            $before = $this->auditPayload($request);

            $rejected = $this->requests->update($request, [
                'status' => BranchChangeRequest::STATUS_REJECTED,
                'decided_by_user_id' => (int) $approver->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ]);

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $rejected->id,
                'BRANCH_CHANGE_REJECTED',
                $before,
                $this->auditPayload($rejected),
                $approver,
            );

            return $rejected;
        });
    }

    /**
     * The requester withdraws their own pending request.
     *
     * @throws ValidationException
     */
    public function cancel(int $requestId, User $requester): BranchChangeRequest
    {
        return DB::transaction(function () use ($requestId, $requester): BranchChangeRequest {
            $request = $this->requests->lockById($requestId);

            if ($request === null || (int) $request->requester_user_id !== (int) $requester->id) {
                throw ValidationException::withMessages([
                    'request' => 'Permintaan tidak ditemukan.',
                ]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => 'Permintaan ini sudah diputuskan dan tidak dapat dibatalkan.',
                ]);
            }

            $before = $this->auditPayload($request);

            $cancelled = $this->requests->update($request, [
                'status' => BranchChangeRequest::STATUS_CANCELLED,
                'decided_at' => now(),
            ]);

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $cancelled->id,
                'BRANCH_CHANGE_CANCELLED',
                $before,
                $this->auditPayload($cancelled),
                $requester,
            );

            return $cancelled;
        });
    }

    /**
     * Read a PENDING, non-stale request under a row lock, with the
     * self-approval boundary applied.
     *
     * @throws ValidationException
     */
    private function lockPendingRequest(int $requestId, User $approver): BranchChangeRequest
    {
        $request = $this->requests->lockById($requestId);

        if ($request === null) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan tidak ditemukan.',
            ]);
        }

        // SELF-APPROVAL BOUNDARY. Structurally a locked role can never also be
        // Super Admin (the online-context service exempts Super Admin, so such a
        // user never gets a daily context to move). This is the explicit second
        // layer, because a boundary that only holds by side effect is one
        // refactor away from not holding at all.
        if ((int) $request->requester_user_id === (int) $approver->id) {
            throw ValidationException::withMessages([
                'request' => 'Anda tidak dapat menyetujui permintaan perpindahan cabang Anda sendiri.',
            ]);
        }

        // FAIL CLOSED ON THE DAY BOUNDARY — CHECKED BEFORE THE STATUS.
        //
        // Ordered first on purpose. A previous-day request must be refused as
        // KEDALUWARSA whether it is still PENDING or has already been stamped
        // EXPIRED by the queue's housekeeping, and the operator deserves the
        // message that says which of the two happened.
        //
        // The ordering also matters for the tests: an earlier draft stamped the
        // row EXPIRED before opening this transaction, which meant the refusal
        // always came from the isPending() check below and this guard was never
        // exercised. Mutation testing caught it — deleting this condition left
        // every test green. The bookkeeping now lives entirely in the queue
        // listing (BranchChangeRequestRepository::expireStale), so this is the
        // single, observable boundary.
        //
        // Correctness therefore does not wait for a cron: even on a deployment
        // where no expiry ever runs, a request from a past clinical day is
        // refused right here.
        if ($request->isStaleForClinicalDay($this->daily->clinicalToday())) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan ini berasal dari hari klinis sebelumnya dan sudah kedaluwarsa.',
            ]);
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan ini sudah diputuskan.',
            ]);
        }

        return $request;
    }

    /**
     * @throws ValidationException
     */
    private function assertEligibleDestination(int $branchId): void
    {
        $branch = $this->branches->findById($branchId);

        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Cabang tujuan harus cabang RME yang aktif.',
            ]);
        }
    }

    /**
     * Point the requester's session-scoped online context at the approved
     * branch, when one exists.
     *
     * Never creates a context and never brings an offline operator back online:
     * only the branch value is realigned, so an offline user still has to go
     * through the selector — where the daily lock will hold them to exactly this
     * destination.
     */
    private function realignOnlineContext(int $userId, int $branchId): void
    {
        if ($this->onlineContexts->findForUser($userId) === null) {
            return;
        }

        $this->onlineContexts->upsertForUser($userId, ['branch_id' => $branchId]);
    }

    /**
     * Audit payload. Ids, dates and the decision only — no patient data, no
     * financial figures, nothing that would turn the audit trail into a second
     * copy of the clinical record.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(BranchChangeRequest $request): array
    {
        return [
            'request_id' => (int) $request->id,
            'requester_user_id' => (int) $request->requester_user_id,
            'clinical_date' => $request->clinical_date?->toDateString(),
            'role_context' => (string) $request->role_context,
            'source_branch_id' => (int) $request->source_branch_id,
            'destination_branch_id' => (int) $request->destination_branch_id,
            'status' => (string) $request->status,
            'decided_by_user_id' => $request->decided_by_user_id ? (int) $request->decided_by_user_id : null,
            'applied_at' => $request->applied_at?->toIso8601String(),
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception->getCode() === '23505') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }

    /**
     * The daily context for a user, for surfaces that need to render it.
     */
    public function contextFor(User $user): ?DailyBranchContext
    {
        return $this->daily->currentFor($user);
    }
}
