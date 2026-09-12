<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationOutcome;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationPair;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationPlan;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationRefusal;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceAuthorizationRepositoryInterface;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — reconcile the
 * (eligible doctor x eligible trusted clinic device) authorization matrix.
 *
 * The product rule is one sentence: EVERY eligible active doctor must be
 * explicitly authorized on EVERY eligible trusted clinic tablet, with exactly
 * one ACTIVE authorization per pair. This class computes the gap and, only when
 * an operator has approved that exact gap, closes it.
 *
 * ── WHAT THIS IS NOT ──────────────────────────────────────────────────────
 *
 * It is not a replacement for {@see DoctorDeviceAuthorization}. Trust stays
 * three-layered — a trusted physical device, PLUS an explicit per-doctor
 * authorization, PLUS a device-bound cryptographic proof at login. PR-C makes
 * the middle layer easy to populate; it never dissolves it into "a trusted
 * tablet may be used by anyone".
 *
 * It is not automatic. There is no observer, no listener and no login-time
 * hook. A new doctor or a newly admitted tablet does NOT silently acquire the
 * whole matrix: an operator runs the preview, reads the delta and approves it.
 * That is deliberate governance, not missing automation.
 *
 * ── EVERY WRITE GOES THROUGH THE CANONICAL LIFECYCLE SERVICE ──────────────
 *
 * This class owns NO state transition of its own. It calls exactly two methods
 * on {@see DoctorDeviceAuthorizationService} — `resolveOrRequest()` to create a
 * PENDING row and `approve()` to make it ACTIVE — because those already carry
 * the locking, the re-validation, the idempotence and the audit trail, and a
 * second writer of `STATUS_ACTIVE` would be a drift liability with no
 * compensating benefit. There is not a single direct model write below.
 *
 * ── THE DEVICE-MUTATION GUARD ─────────────────────────────────────────────
 *
 * `approve()` has a second half: it promotes a `pending_approval` device to
 * `active` and writes a DOCTOR_DEVICE_ADMITTED audit row. PR-C must not mutate
 * devices at all. The guard is not "we only pick active devices" — that is a
 * read, and reads go stale. It is {@see assertStillEligible()}, which re-asserts
 * ACTIVE under the SAME row lock `approve()` will re-acquire on the same
 * connection, inside the same transaction. Holding that lock makes
 * `isPendingApproval()` provably false at the moment `approve()` tests it.
 *
 * ── WHAT IT REFUSES TO TOUCH ──────────────────────────────────────────────
 *
 * REJECTED and REVOKED pairs are classified, reported and then left completely
 * alone. In particular `resolveOrRequest()` is NEVER called on an existing row:
 * it routes one into `reopenIfPermitted()`, which can transition REJECTED to
 * PENDING and write an audit row. A naive "call it for every pair" loop would
 * therefore write during what the operator was told is a preview.
 *
 * Branch is REPORTED, never filtered. BranchContext is deliberately not used
 * anywhere in this file: scoping a fleet-wide console tool to the operator's
 * own branch would under-provision the estate silently.
 */
final class DoctorDeviceBulkAuthorizationService
{
    public function __construct(
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,
        private readonly DoctorDeviceAuthorizationService $authorizations,
        // The persistence boundary, injected only to take the authorization row
        // lock in approve()'s own order before calling it. No lifecycle rule
        // lives here; every state change still goes through the service above.
        private readonly DoctorDeviceAuthorizationRepositoryInterface $authorizationRows,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Build the plan. READS ONLY — not one write, not even an audit row.
     *
     * A preview that logged its own execution would make "the tool was run"
     * indistinguishable from "the tool changed something", which is the first
     * thing an incident review needs to separate.
     *
     * @param  list<int>  $doctorIds  narrow to these mst_doctors.id, or [] for the whole fleet
     * @param  list<int>  $deviceIds  narrow to these mst_doctor_devices.id, or [] for all
     *
     * @throws DoctorDeviceBulkAuthorizationRefusal
     */
    public function plan(array $doctorIds = [], array $deviceIds = []): DoctorDeviceBulkAuthorizationPlan
    {
        $accounts = $this->estate->doctorAccounts();
        $records = $this->estate->doctorRecordsForUsers($accounts->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $eligibleDoctors = [];
        $excludedDoctors = [];

        foreach ($accounts as $account) {
            $record = $records->get((int) $account->id);

            $reason = $this->doctorExclusionReason($account, $record);

            if ($reason !== null) {
                $excludedDoctors[] = [
                    'doctor_id' => $record?->id !== null ? (int) $record->id : null,
                    'user_id' => (int) $account->id,
                    'name' => (string) ($record->name ?? $account->name ?? ''),
                    'reason' => $reason,
                ];

                continue;
            }

            // Narrowing happens AFTER eligibility so that `--doctor=` on an
            // ineligible id still reports why, rather than silently returning
            // an empty plan the operator reads as "nothing to do".
            if ($doctorIds !== [] && ! in_array((int) $record->id, $doctorIds, true)) {
                continue;
            }

            $eligibleDoctors[] = ['account' => $account, 'record' => $record];
        }

        $estate = $this->estate->deviceEstate();
        $ceiling = (int) config('doctor_access.bulk_authorization.max_estate_devices');

        if ($estate->count() > $ceiling) {
            throw DoctorDeviceBulkAuthorizationRefusal::estateTooLarge($estate->count(), $ceiling);
        }

        $eligibleDevices = [];
        $allEligibleDeviceIds = [];
        $excludedDevices = [];

        foreach ($estate as $device) {
            $reason = $this->deviceExclusionReason($device);

            if ($reason !== null) {
                $excludedDevices[] = [
                    'device_id' => (int) $device->id,
                    'name' => (string) $device->device_name,
                    'branch' => $device->branch?->code,
                    'reason' => $reason,
                ];

                continue;
            }

            // Eligible, and recorded as such BEFORE narrowing. The narrowed
            // list drives the matrix; this one answers "is this device still
            // trusted?", which `--device=` must not be able to change.
            $allEligibleDeviceIds[] = (int) $device->id;

            if ($deviceIds !== [] && ! in_array((int) $device->id, $deviceIds, true)) {
                continue;
            }

            $eligibleDevices[] = $device;
        }

        $matrixCeiling = (int) config('doctor_access.bulk_authorization.max_pairs');
        $matrixSize = count($eligibleDoctors) * count($eligibleDevices);

        if ($matrixSize > $matrixCeiling) {
            throw DoctorDeviceBulkAuthorizationRefusal::matrixTooLarge($matrixSize, $matrixCeiling);
        }

        // ONE read for every authorization in the fleet, indexed in PHP. The
        // matrix is then O(1) per cell. A findPair() per cell would turn a
        // fifteen-by-three estate into forty-five round trips and a national
        // one into thousands.
        $doctorIdList = array_map(static fn (array $row): int => (int) $row['record']->id, $eligibleDoctors);
        $byDoctor = $this->estate->authorizationsForDoctors($doctorIdList);

        $existing = [];
        $activeOnIneligibleDevice = [];

        foreach ($byDoctor as $doctorId => $rows) {
            foreach ($rows as $row) {
                $existing[(int) $doctorId][(int) $row->doctor_device_id] = $row;

                // An ACTIVE authorization pointing at a device that no longer
                // qualifies. REPORTED, NEVER REVOKED: withdrawing trust is a
                // lifecycle decision with its own route and a mandatory reason,
                // and a synchroniser that revoked as a side effect of tidying
                // would be making that decision on nobody's authority.
                // Compared against the FULL eligible set, never the narrowed
                // one: `--device=3` must not make every authorization on
                // devices 5 and 6 report as pointing at retired hardware. That
                // would be a false alarm on a line an operator is meant to act
                // on.
                if ($row->status === DoctorDeviceAuthorization::STATUS_ACTIVE
                    && ! in_array((int) $row->doctor_device_id, $allEligibleDeviceIds, true)) {
                    $activeOnIneligibleDevice[] = [
                        'authorization_id' => (int) $row->id,
                        'doctor_id' => (int) $doctorId,
                        'device_id' => (int) $row->doctor_device_id,
                    ];
                }
            }
        }

        $pairs = [];

        foreach ($eligibleDoctors as $doctor) {
            $record = $doctor['record'];
            $account = $doctor['account'];

            foreach ($eligibleDevices as $device) {
                $row = $existing[(int) $record->id][(int) $device->id] ?? null;

                $pairs[] = new DoctorDeviceBulkAuthorizationPair(
                    doctorId: (int) $record->id,
                    userId: (int) $account->id,
                    doctorName: (string) $record->name,
                    deviceId: (int) $device->id,
                    deviceName: (string) $device->device_name,
                    deviceBranchCode: $device->branch?->code,
                    bucket: $this->classify($row),
                    authorizationId: $row !== null ? (int) $row->id : null,
                    existingStatus: $row?->status,
                );
            }
        }

        $planDoctorIds = $doctorIdList;
        $planDeviceIds = array_map(static fn (DoctorDevice $device): int => (int) $device->id, $eligibleDevices);
        sort($planDoctorIds);
        sort($planDeviceIds);

        return new DoctorDeviceBulkAuthorizationPlan(
            pairs: $pairs,
            excludedDoctors: $excludedDoctors,
            excludedDevices: $excludedDevices,
            activeOnIneligibleDevice: $activeOnIneligibleDevice,
            // Passed EXPLICITLY rather than left to be derived from $pairs: when
            // either side of the matrix is empty there are no pairs to derive
            // from, and every empty estate would then produce the same digest.
            eligibleDoctorIds: $planDoctorIds,
            eligibleDeviceIds: $planDeviceIds,
            pendingInboxBefore: $this->authorizations->countPending(),
        );
    }

    /**
     * Close the gap the plan described.
     *
     * Per pair, TWO SEQUENTIAL TRANSACTIONS, deliberately not nested into one.
     *
     *   T1  resolveOrRequest() at TOP LEVEL, never inside an outer transaction.
     *       Its recovery path does a SELECT inside `catch (QueryException)`.
     *       On PostgreSQL a failed statement aborts the transaction, so that
     *       SELECT only works because the failing transaction is top-level and
     *       has fully rolled back by the time the catch runs. Nesting it would
     *       make correctness depend on savepoint-abort semantics that SQLite
     *       hides completely — a green local test would prove nothing.
     *
     *   T2  our own transaction: lock the device row and the doctor row,
     *       re-assert eligibility, then call approve() nested under a savepoint.
     *       approve() never catches a QueryException, so nesting it is free of
     *       that trap, and its own lockForUpdate on the device re-acquires a
     *       lock we already hold on the same connection.
     *
     * A crash between T1 and T2 leaves an orphan PENDING row. That is honest
     * and self-healing: it is byte-identical to what a doctor tapping login
     * produces, it is visible in the approval inbox, and the next run
     * classifies it as bucket C and approves it.
     *
     * @return array{outcomes: list<array<string, mixed>>, created: int, approved_existing: int, skipped: int, refused: int, orphan_pending: int}
     */
    public function apply(DoctorDeviceBulkAuthorizationPlan $plan, User $actor, string $reason): array
    {
        $outcomes = [];
        $created = 0;
        $approvedExisting = 0;
        $skipped = 0;
        $refused = 0;
        $orphanPending = 0;

        foreach ($plan->actionable() as $pair) {
            [$result, $leftPendingRow] = $this->applyPair($pair, $actor);

            $outcomes[] = $pair->toArray() + [
                'outcome' => $result,
                'left_pending_row' => $leftPendingRow,
            ];

            match (true) {
                $result === DoctorDeviceBulkAuthorizationOutcome::APPLIED_CREATED => $created++,
                $result === DoctorDeviceBulkAuthorizationOutcome::APPLIED_APPROVED_EXISTING => $approvedExisting++,
                $result === DoctorDeviceBulkAuthorizationOutcome::SKIPPED_ALREADY_ACTIVE => $skipped++,
                default => $refused++,
            };

            if ($leftPendingRow) {
                $orphanPending++;
            }
        }

        // ONE run-level audit row, written through the canonical shared writer
        // with an EXPLICIT actor. AuditLogService falls back to auth()->user(),
        // which from an unauthenticated console process is null — a forgotten
        // actor produces an unattributable security-history row.
        $this->auditLogs->log(
            'mst_doctor_device_authorizations',
            null,
            'DOCTOR_DEVICE_BULK_AUTHORIZATION_RUN',
            null,
            [
                // Scalars only. No key material, no credential, no patient data.
                'digest' => $plan->digest(),
                'reason' => $reason,
                'doctors' => $plan->eligibleDoctorCount(),
                'trusted_devices' => $plan->eligibleDeviceCount(),
                'existing' => $plan->existingActive(),
                'created' => $created,
                'approved_existing' => $approvedExisting,
                'skipped_already_active' => $skipped,
                'refused' => $refused,
                'orphan_pending' => $orphanPending,
                'final_expected' => $plan->finalExpectedActive(),
            ],
            $actor,
        );

        return [
            'outcomes' => $outcomes,
            'created' => $created,
            'approved_existing' => $approvedExisting,
            'skipped' => $skipped,
            'refused' => $refused,
            'orphan_pending' => $orphanPending,
        ];
    }

    /**
     * @return array{0: string, 1: bool} the outcome, and whether this pair left
     *                                   a PENDING row in the human approval inbox
     */
    private function applyPair(DoctorDeviceBulkAuthorizationPair $pair, User $actor): array
    {
        $authorization = null;
        $created = false;

        if ($pair->needsCreate()) {
            // T1. Top level. See the note on apply().
            $doctor = Doctor::query()->find($pair->doctorId);
            $device = DoctorDevice::query()->find($pair->deviceId);

            // Named apart, because an operator reading "doctor inactive" about a
            // deleted DEVICE would look in the wrong place.
            if ($device === null) {
                return [DoctorDeviceBulkAuthorizationOutcome::REFUSED_DEVICE_NOT_ACTIVE, $created];
            }

            if ($doctor === null) {
                return [DoctorDeviceBulkAuthorizationOutcome::REFUSED_DOCTOR_INACTIVE, $created];
            }

            // SOURCE_ADMIN, not SOURCE_APP_LOGIN. PR-C is the first consumer of
            // a constant that has existed unused since the authorization
            // lifecycle shipped; it gives a bulk-provisioned row a provenance
            // an operator can tell apart from a doctor's own login tap.
            $authorization = $this->authorizations->resolveOrRequest(
                $doctor,
                $device,
                $actor,
                DoctorDeviceAuthorization::SOURCE_ADMIN,
            );

            // What HAPPENED, not what the plan predicted. resolveOrRequest()
            // returns the existing row when a doctor's own login created the
            // pair between the preview and here, and reporting that as a create
            // would inflate a counter the evidence pack is read for.
            $created = (bool) $authorization->wasRecentlyCreated;
        } else {
            $authorization = DoctorDeviceAuthorization::query()->find($pair->authorizationId);
        }

        if ($authorization === null) {
            return [DoctorDeviceBulkAuthorizationOutcome::REFUSED_RACED_TO_NON_PENDING, $created];
        }

        if ($authorization->status === DoctorDeviceAuthorization::STATUS_ACTIVE) {
            return [DoctorDeviceBulkAuthorizationOutcome::SKIPPED_ALREADY_ACTIVE, $created];
        }

        if ($authorization->status !== DoctorDeviceAuthorization::STATUS_PENDING) {
            // A concurrent operator rejected or revoked the pair between the
            // plan and here. They win: this run reports the loss rather than
            // overriding a human decision.
            return [DoctorDeviceBulkAuthorizationOutcome::REFUSED_RACED_TO_NON_PENDING, $created];
        }

        // T2.
        try {
            $outcome = DB::transaction(function () use ($authorization, $pair, $actor, $created): string {
                // LOCK ORDER: authorization, then device. It matches approve()'s
                // own order exactly, and that is the point rather than a detail.
                // Taking the device first would invert the order against every
                // concurrent approval from the inbox — one holding the
                // authorization and wanting the device, this one holding the
                // device and wanting the authorization — which PostgreSQL
                // resolves by aborting one of them mid-run.
                $locked = $this->authorizationRows->findForUpdate((int) $authorization->id);

                if ($locked === null) {
                    return DoctorDeviceBulkAuthorizationOutcome::REFUSED_RACED_TO_NON_PENDING;
                }

                // Re-read UNDER the lock. The pre-read that chose this path was
                // unlocked, so a row another operator approved or revoked in
                // between would otherwise be acted on from a stale status.
                if ($locked->status === DoctorDeviceAuthorization::STATUS_ACTIVE) {
                    return DoctorDeviceBulkAuthorizationOutcome::SKIPPED_ALREADY_ACTIVE;
                }

                if ($locked->status !== DoctorDeviceAuthorization::STATUS_PENDING) {
                    return DoctorDeviceBulkAuthorizationOutcome::REFUSED_RACED_TO_NON_PENDING;
                }

                $refusal = $this->assertStillEligible($pair);

                if ($refusal !== null) {
                    return $refusal;
                }

                $this->authorizations->approve($locked, $actor);

                return $created
                    ? DoctorDeviceBulkAuthorizationOutcome::APPLIED_CREATED
                    : DoctorDeviceBulkAuthorizationOutcome::APPLIED_APPROVED_EXISTING;
            });
        } catch (ValidationException) {
            // approve() re-validates under its own lock and refuses in prose.
            // Anything it rejects that our guard did not catch is drift we do
            // not have a more precise word for.
            return [DoctorDeviceBulkAuthorizationOutcome::REFUSED_RACED_TO_NON_PENDING, $created];
        }

        // A bucket-B pair whose T2 did not apply leaves the PENDING row T1
        // already committed, sitting in the human approval inbox. Counting it is
        // the difference between an honest partial run and one that quietly
        // grows somebody else's queue.
        $applied = $outcome === DoctorDeviceBulkAuthorizationOutcome::APPLIED_CREATED
            || $outcome === DoctorDeviceBulkAuthorizationOutcome::APPLIED_APPROVED_EXISTING;

        return [$outcome, $created && ! $applied];
    }

    /**
     * The guard, taken under a row lock rather than read from the plan.
     *
     * MUST run inside the same transaction that then calls approve(), and AFTER
     * the authorization row is locked. Asserting ACTIVE at plan time is a TOCTOU
     * read; asserting it while holding the row lock approve() will re-acquire is
     * a guarantee, and it is the entire reason approve()'s device-promotion
     * branch cannot fire.
     *
     * The doctor row is locked here too, closing a gap approve() itself leaves:
     * it locks the device but reads the doctor with a plain find().
     *
     * @return string|null a REFUSED_* outcome, or null when the pair is still good
     */
    private function assertStillEligible(DoctorDeviceBulkAuthorizationPair $pair): ?string
    {
        // Called only AFTER the authorization row is locked, so the order here
        // continues approve()'s rather than competing with it.
        $device = DoctorDevice::query()->lockForUpdate()->find($pair->deviceId);
        $doctor = Doctor::query()->lockForUpdate()->find($pair->doctorId);

        if ($device === null || ! $device->isActive()) {
            return DoctorDeviceBulkAuthorizationOutcome::REFUSED_DEVICE_NOT_ACTIVE;
        }

        if (! $device->isCryptographicallyVerified()) {
            return DoctorDeviceBulkAuthorizationOutcome::REFUSED_DEVICE_IDENTITY_UNVERIFIED;
        }

        if ($doctor === null || $doctor->is_active !== true) {
            return DoctorDeviceBulkAuthorizationOutcome::REFUSED_DOCTOR_INACTIVE;
        }

        return null;
    }

    private function doctorExclusionReason(User $account, ?Doctor $record): ?string
    {
        if ($record === null) {
            return DoctorGlobalRolloutReadinessService::REASON_DOCTOR_NOT_LINKED;
        }

        if ((bool) $account->is_active !== true) {
            return DoctorDeviceBulkAuthorizationOutcome::REASON_USER_ACCOUNT_INACTIVE;
        }

        // STRICT !== true, mirroring the readiness engine: a NULL column is not
        // "active", and a loose comparison would read it as one.
        if ($record->is_active !== true) {
            return DoctorGlobalRolloutReadinessService::REASON_DOCTOR_INACTIVE;
        }

        return null;
    }

    /**
     * Device eligibility.
     *
     * The bar is set by approve(), not by preference: it hard-throws on a
     * device that is not cryptographically verified, so a WebAuthn-only tablet
     * — legitimately active with no keystore key — cannot be bulk-authorized
     * even though it can log a doctor in. That is a real limitation and the
     * report says so rather than hiding it.
     *
     * `enrollment_status` is deliberately NOT read: isEnrollmentVerified() has
     * zero call sites in the estate, so treating it as a trust input would
     * fabricate a signal nothing else honours.
     */
    private function deviceExclusionReason(DoctorDevice $device): ?string
    {
        if ($device->isRevoked()) {
            return DoctorDeviceBulkAuthorizationOutcome::REASON_DEVICE_REVOKED;
        }

        if ($device->isDisabled()) {
            return DoctorDeviceBulkAuthorizationOutcome::REASON_DEVICE_DISABLED;
        }

        if ($device->isPendingApproval()) {
            return DoctorDeviceBulkAuthorizationOutcome::REASON_DEVICE_PENDING_APPROVAL;
        }

        if (! $device->isActive()) {
            return DoctorGlobalRolloutReadinessService::REASON_DEVICE_NOT_ACTIVE;
        }

        if (! $device->isCryptographicallyVerified()) {
            return DoctorGlobalRolloutReadinessService::REASON_DEVICE_IDENTITY_UNVERIFIED;
        }

        if ($device->branch_id === null) {
            // approve() refuses a branchless device. Excluding it here means the
            // plan never proposes a write that is certain to be refused.
            return DoctorGlobalRolloutReadinessService::REASON_DEVICE_NOT_ACTIVE;
        }

        return null;
    }

    private function classify(?DoctorDeviceAuthorization $row): string
    {
        if ($row === null) {
            return DoctorDeviceBulkAuthorizationOutcome::BUCKET_CREATE;
        }

        return match ($row->status) {
            DoctorDeviceAuthorization::STATUS_ACTIVE => DoctorDeviceBulkAuthorizationOutcome::BUCKET_ALREADY_ACTIVE,
            DoctorDeviceAuthorization::STATUS_PENDING => DoctorDeviceBulkAuthorizationOutcome::BUCKET_ADOPT_PENDING,
            DoctorDeviceAuthorization::STATUS_REJECTED => DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REJECTED,
            DoctorDeviceAuthorization::STATUS_REVOKED => DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REVOKED,
            // Named rather than folded into REVOKED. A `default` arm here would
            // report a revocation that never happened, and a status this
            // vocabulary has not been taught is exactly the thing an operator
            // needs to see rather than have translated.
            default => DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_UNKNOWN_STATUS,
        };
    }
}
