<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Support\DoctorBranchLockBulkAssignmentOutcome as Outcome;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — drive an owner-approved home-branch
 * matrix through the canonical approval workflow, one doctor at a time.
 *
 * THIS IS A THIN ADAPTER AND MUST STAY ONE. It orchestrates; it does not
 * decide. Every branch validation, every maker/checker comparison, every row
 * lock, the session invalidation, the markOffline, the online-impact
 * acknowledgement and every audit row belong to
 * {@see DoctorBranchLockApprovalService} and are reached by CALLING it, twice
 * per doctor, exactly as the two HTTP endpoints do. There is no second
 * implementation of any rule here, and adding one would mean the CLI and the
 * screen could disagree about whether a branch move was legal.
 *
 * WHY IT EXISTS AT ALL, GIVEN THE SCREEN ALREADY WORKS.
 *
 * The screen 404s unless BOTH `doctor.branch_lock` and
 * `doctor.single_active_session` are armed — the surface is gated by
 * `DoctorEffectiveBranchResolver::enabled()`, and the approval service
 * deliberately is not. Assigning fourteen doctors through the screen therefore
 * means arming single-session enforcement across a live clinic for the length
 * of twenty-eight manual actions, during which any doctor who opens a second
 * session is evicted mid-consultation. Going through the service directly needs
 * no arming at all, which is what the sprint asked for in the first place.
 *
 * WHAT IT DELIBERATELY CANNOT DO.
 *
 * It never infers a branch — not from a doctor code, a device, a device's
 * branch, a last login, a room, a visit, a roster or a frequency. The matrix is
 * supplied whole by the owner and an entry it cannot read is REFUSED rather
 * than guessed or skipped. It never performs a TRANSFER: a doctor already
 * locked elsewhere stops the row. It writes no flag, never touches the
 * enforcement scope or the pilot cohort, and issues no raw SQL write.
 */
class DoctorBranchLockBulkAssignmentService
{
    public function __construct(
        private readonly DoctorBranchLockApprovalService $approvals,
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly BranchRepositoryInterface $branches,
        private readonly DoctorAccessSubjectGuard $subjects,
    ) {}

    /**
     * Classify every row of the matrix WITHOUT writing anything.
     *
     * TAKES A LIST OF PAIRS, NOT A MAP, AND THAT IS LOAD-BEARING. A PHP array
     * keyed by doctor id cannot represent the same doctor twice: `17=SPN4` then
     * `17=LDK2` silently collapses to whichever came last, and the tool would
     * assign a branch the operator did not intend while reporting success. A
     * list preserves the duplicate so it can be REFUSED — which is the only
     * honest answer, because nothing here is entitled to decide which of two
     * contradictory owner instructions wins.
     *
     * @param  list<array{0:mixed,1:mixed}>  $pairs  [doctor id, branch code], exactly as the owner approved it
     * @return array{rows:list<array<string,mixed>>,summary:array<string,int>,digest:string}
     */
    public function plan(array $pairs): array
    {
        $rows = [];
        $seen = [];

        foreach ($pairs as $pair) {
            $row = $this->classify($pair[0] ?? null, $pair[1] ?? null, $seen);

            if (is_int($row['doctor_id'])) {
                $seen[$row['doctor_id']] = true;
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'summary' => $this->summarise($rows),
            'digest' => $this->digest($rows),
        ];
    }

    /**
     * @param  array<int,bool>  $seen
     * @return array<string,mixed>
     */
    private function classify(mixed $doctorId, mixed $branchCode, array $seen): array
    {
        /*
         * FAIL CLOSED ON A MALFORMED ENTRY. Not skipped, not coerced, not
         * treated as "no filter" — refused as its own row so the operator sees
         * that the matrix they approved is not the matrix that would run.
         */
        if (! is_int($doctorId) || $doctorId < 1 || ! is_string($branchCode) || trim($branchCode) === '') {
            return $this->refusal(
                is_int($doctorId) ? $doctorId : null,
                is_string($branchCode) ? $branchCode : null,
                Outcome::REASON_MALFORMED_ENTRY,
            );
        }

        $branchCode = trim($branchCode);

        if (isset($seen[$doctorId])) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_DUPLICATE_DOCTOR);
        }

        $doctor = Doctor::query()->whereKey($doctorId)->first();

        if (! $doctor instanceof Doctor) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_DOCTOR_NOT_FOUND);
        }

        if ($doctor->is_active !== true) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_DOCTOR_INACTIVE, $doctor);
        }

        if ($doctor->user_id === null) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_DOCTOR_NOT_LINKED, $doctor);
        }

        $branch = $this->branches->findByCode($branchCode);

        if ($branch === null) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_BRANCH_NOT_FOUND, $doctor);
        }

        if ((bool) $branch->is_active !== true) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_BRANCH_INACTIVE, $doctor, $branch->id);
        }

        if ((bool) $branch->is_rme_enabled !== true) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_BRANCH_NOT_RME, $doctor, $branch->id);
        }

        /*
         * Pre-checked here and re-asserted inside the approval transaction by
         * assertWithinPracticeBranches(). The duplicate is intentional: it turns
         * a decision-time refusal into a dry-run refusal the operator can read
         * BEFORE anything is filed.
         */
        $inPracticeSet = $doctor->branches()->whereKey($branch->id)->exists();

        if (! $inPracticeSet) {
            return $this->refusal($doctorId, $branchCode, Outcome::REASON_BRANCH_NOT_IN_PRACTICE_SET, $doctor, $branch->id);
        }

        $currentBranchId = $this->locks->lockedBranchIdFor((int) $doctor->id);

        $classification = match (true) {
            $currentBranchId === null => Outcome::PENDING_ASSIGNMENT,
            $currentBranchId === (int) $branch->id => Outcome::ALREADY_SATISFIED,
            default => Outcome::TRANSFER_REQUIRED,
        };

        $online = $this->onlineState((int) $doctor->id);

        return [
            'doctor_id' => (int) $doctor->id,
            'doctor_name' => (string) $doctor->name,
            'doctor_code' => $doctor->code === null ? null : (string) $doctor->code,
            'user_id' => (int) $doctor->user_id,
            'branch_code' => $branchCode,
            'branch_id' => (int) $branch->id,
            'current_home_branch_id' => $currentBranchId,
            'classification' => $classification,
            'refusal_reason' => null,
            'online' => $online,
        ];
    }

    /**
     * Is this doctor working right now?
     *
     * Read through the same guard the approval transaction uses, so the preview
     * and the decision cannot disagree about presence. A doctor whose subject
     * cannot be resolved is reported as NOT online rather than crashing the
     * preview — the approval itself will refuse that row on its own terms, and
     * a dry run that dies on one bad doctor tells the operator nothing about
     * the other thirteen.
     */
    private function onlineState(int $doctorId): bool
    {
        try {
            return $this->subjects->isOnline($this->subjects->assertRequestableSubject($doctorId));
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function refusal(?int $doctorId, ?string $branchCode, string $reason, ?Doctor $doctor = null, ?int $branchId = null): array
    {
        return [
            'doctor_id' => $doctorId,
            'doctor_name' => $doctor?->name === null ? null : (string) $doctor->name,
            'doctor_code' => $doctor?->code === null ? null : (string) $doctor->code,
            'user_id' => $doctor?->user_id === null ? null : (int) $doctor->user_id,
            'branch_code' => $branchCode,
            'branch_id' => $branchId,
            'current_home_branch_id' => null,
            'classification' => Outcome::REFUSED,
            'refusal_reason' => $reason,
            'online' => false,
        ];
    }

    /**
     * Execute only the rows the plan classified as assignable.
     *
     * Each doctor is a SEPARATE request and a SEPARATE approval, so each keeps
     * its own audit pair and its own transaction. There is no bulk row and no
     * bulk transaction: fourteen assignments that half-succeeded must leave
     * seven complete, individually attributable decisions rather than one
     * ambiguous partial write.
     *
     * @param  array{rows:list<array<string,mixed>>,summary:array<string,int>,digest:string}  $plan
     * @param  list<int>  $acknowledgedOnlineDoctorIds  doctors the operator explicitly cleared
     * @return array{rows:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function apply(array $plan, User $maker, User $checker, string $reason, array $acknowledgedOnlineDoctorIds = []): array
    {
        $acknowledged = array_flip($acknowledgedOnlineDoctorIds);
        $rows = [];

        foreach ($plan['rows'] as $row) {
            if ($row['classification'] !== Outcome::PENDING_ASSIGNMENT) {
                $rows[] = $row;

                continue;
            }

            /*
             * CLINICAL SAFETY. A doctor mid-consultation loses their session the
             * moment the approval lands. That is correct behaviour and it is
             * still not something a batch run may decide on the operator's
             * behalf, so an unacknowledged online doctor is held back rather
             * than assigned.
             */
            if ($row['online'] && ! isset($acknowledged[$row['doctor_id']])) {
                $rows[] = array_merge($row, ['classification' => Outcome::ONLINE_NEEDS_ACKNOWLEDGEMENT]);

                continue;
            }

            $rows[] = $this->assign($row, $maker, $checker, $reason, (bool) $row['online']);
        }

        return ['rows' => $rows, 'summary' => $this->summarise($rows)];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function assign(array $row, User $maker, User $checker, string $reason, bool $online): array
    {
        try {
            $request = $this->approvals->request(
                $maker,
                (int) $row['doctor_id'],
                (int) $row['branch_id'],
                $reason,
            );

            $approved = $this->approvals->approve(
                (int) $request->id,
                $checker,
                $reason,
                $online,
            );

            return array_merge($row, [
                'classification' => Outcome::ASSIGNED,
                'request_id' => (int) $approved->id,
                'maker_user_id' => (int) $maker->id,
                'checker_user_id' => (int) $checker->id,
            ]);
        } catch (ValidationException $e) {
            /*
             * The request may already have been filed when the APPROVAL is what
             * refused. It is left PENDING on purpose: it is a real, audited
             * proposal an approver can still decide through the screen, and
             * silently withdrawing it would erase the evidence of what was
             * attempted.
             */
            return array_merge($row, [
                'classification' => Outcome::FAILED,
                'refusal_reason' => implode(' ', array_merge(...array_values($e->errors()))),
            ]);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function summarise(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $key = (string) $row['classification'];
            $summary[$key] = ($summary[$key] ?? 0) + 1;
        }

        ksort($summary);

        return $summary;
    }

    /**
     * A digest over the exact matrix AND the preconditions it was classified
     * against.
     *
     * Consent binds to the state, not to a moment. If a doctor is locked, made
     * inactive, taken off a practice branch, or comes online between the dry run
     * and the apply, the digest changes and the apply is refused — which is the
     * whole point, because the operator approved a delta they can no longer see.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    private function digest(array $rows): string
    {
        $canonical = array_map(static fn (array $row): string => implode('|', [
            $row['doctor_id'] ?? 'null',
            $row['branch_id'] ?? 'null',
            $row['current_home_branch_id'] ?? 'null',
            $row['classification'],
            $row['refusal_reason'] ?? '',
            $row['online'] ? '1' : '0',
        ]), $rows);

        return substr(hash('sha256', implode("\n", $canonical)), 0, 32);
    }
}
