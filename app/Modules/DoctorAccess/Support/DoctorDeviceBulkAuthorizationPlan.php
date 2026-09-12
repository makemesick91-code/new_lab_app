<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — an immutable snapshot of
 * what the estate looks like and what a run would therefore write.
 *
 * ── THE PLAN IS THE APPROVAL UNIT ─────────────────────────────────────────
 *
 * The operator does not approve "a run"; they approve THIS EXACT ROW DELTA.
 * {@see digest()} is a deterministic hash of the eligible sets and the two
 * actionable pair lists, and `--apply` refuses unless the operator passes it
 * back. That binds consent to the content rather than to a moment in time: if
 * the estate drifts between the preview and the write — a device revoked, a
 * doctor deactivated, another operator approving a row — the digest changes and
 * the run fails closed instead of applying a delta nobody read.
 *
 * A prompt could not do this job. `$this->confirm()` appears nowhere in this
 * repository's commands, and for good reason: it auto-answers with its default
 * under a non-interactive invocation — an SSH one-liner, a deploy script, CI —
 * which at fleet scale is how a preview becomes an unreviewed write.
 *
 * ── BRANCH IS REPORTED, NEVER FILTERED ────────────────────────────────────
 *
 * `deviceBranchCode` rides along so the operator can see where a tablet lives.
 * It is NOT an eligibility input. Device trust and a doctor's branch authority
 * are independent boundaries, and production already depends on that: drg
 * Karmila is home-locked to SPN4 and holds an ACTIVE authorization on an ATG3
 * tablet. Anything that intersected the two would have deprovisioned her.
 */
final class DoctorDeviceBulkAuthorizationPlan
{
    /**
     * @param  list<DoctorDeviceBulkAuthorizationPair>  $pairs  every matrix cell, classified
     * @param  array<int, array{doctor_id: int|null, user_id: int, name: string, reason: string}>  $excludedDoctors
     * @param  array<int, array{device_id: int, name: string, branch: string|null, reason: string}>  $excludedDevices
     * @param  list<array<string, mixed>>  $activeOnIneligibleDevice  reported, never revoked
     * @param  list<int>  $eligibleDoctorIds  carried EXPLICITLY, never derived from $pairs
     * @param  list<int>  $eligibleDeviceIds  carried EXPLICITLY, never derived from $pairs
     * @param  int  $pendingInboxBefore  countPending() at plan time
     */
    public function __construct(
        public readonly array $pairs,
        public readonly array $excludedDoctors,
        public readonly array $excludedDevices,
        public readonly array $activeOnIneligibleDevice,
        public readonly array $eligibleDoctorIds,
        public readonly array $eligibleDeviceIds,
        public readonly int $pendingInboxBefore,
    ) {}

    public function eligibleDoctorCount(): int
    {
        return count($this->eligibleDoctorIds);
    }

    public function eligibleDeviceCount(): int
    {
        return count($this->eligibleDeviceIds);
    }

    /** @return list<DoctorDeviceBulkAuthorizationPair> */
    public function inBucket(string $bucket): array
    {
        return array_values(array_filter(
            $this->pairs,
            static fn (DoctorDeviceBulkAuthorizationPair $pair): bool => $pair->bucket === $bucket,
        ));
    }

    public function countIn(string $bucket): int
    {
        return count($this->inBucket($bucket));
    }

    /**
     * The pairs this run would write to, in a STABLE order.
     *
     * Ordered so that two runs over an unchanged estate produce byte-identical
     * output and therefore the same digest. An unordered plan would hash
     * differently on every invocation and the approval gate would refuse a
     * delta the operator had just read.
     *
     * @return list<DoctorDeviceBulkAuthorizationPair>
     */
    public function actionable(): array
    {
        $actionable = array_values(array_filter(
            $this->pairs,
            static fn (DoctorDeviceBulkAuthorizationPair $pair): bool => $pair->isActionable(),
        ));

        usort(
            $actionable,
            static fn (DoctorDeviceBulkAuthorizationPair $a, DoctorDeviceBulkAuthorizationPair $b): int => [$a->doctorId, $a->deviceId] <=> [$b->doctorId, $b->deviceId],
        );

        return $actionable;
    }

    public function matrixSize(): int
    {
        return $this->eligibleDoctorCount() * $this->eligibleDeviceCount();
    }

    public function existingActive(): int
    {
        return $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_ALREADY_ACTIVE);
    }

    public function proposedCreate(): int
    {
        return $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_CREATE);
    }

    public function proposedApproveExisting(): int
    {
        return $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_ADOPT_PENDING);
    }

    public function blocked(): int
    {
        return $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REJECTED)
            + $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REVOKED)
            + $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_UNKNOWN_STATUS);
    }

    /**
     * What the matrix would hold if every proposed write lands.
     *
     * Blocked pairs are deliberately absent: a bulk tool that folded rows it
     * cannot touch into its own expected total would report a number it can
     * never reach and call the shortfall a failure every single run.
     */
    public function finalExpectedActive(): int
    {
        return $this->existingActive() + $this->proposedCreate() + $this->proposedApproveExisting();
    }

    /** Matrix cells this run cannot bring to ACTIVE. Needs a human, not a retry. */
    public function unreachable(): int
    {
        return $this->matrixSize() - $this->finalExpectedActive();
    }

    public function hasWork(): bool
    {
        return $this->proposedCreate() > 0 || $this->proposedApproveExisting() > 0;
    }

    /**
     * The operator's consent token.
     *
     * Deterministic by construction: sorted id lists, sorted pair lists, no
     * timestamp, no randomness, nothing derived from the machine it ran on. The
     * eligible SETS are included as well as the pair lists, so a change that
     * leaves the delta identical but the estate different — a device revoked
     * that had no pending work — still invalidates a stale approval.
     *
     * The id lists are the ones the plan was BUILT with, not ones re-derived
     * from $pairs. Deriving them looks equivalent and is not: when either side
     * of the matrix is empty there are no pairs to derive from, so every empty
     * estate would hash identically and a digest taken against one estate would
     * satisfy the gate against a completely different one.
     */
    public function digest(): string
    {
        $canonical = json_encode([
            'doctors' => $this->eligibleDoctorIds,
            'devices' => $this->eligibleDeviceIds,
            'create' => $this->pairTuples(DoctorDeviceBulkAuthorizationOutcome::BUCKET_CREATE),
            'approve' => $this->pairTuples(DoctorDeviceBulkAuthorizationOutcome::BUCKET_ADOPT_PENDING),
        ], JSON_THROW_ON_ERROR);

        return substr(hash('sha256', $canonical), 0, 12);
    }

    /** @return list<array{0: int, 1: int}> */
    private function pairTuples(string $bucket): array
    {
        $tuples = array_map(
            static fn (DoctorDeviceBulkAuthorizationPair $pair): array => [$pair->doctorId, $pair->deviceId],
            $this->inBucket($bucket),
        );

        usort($tuples, static fn (array $a, array $b): int => $a <=> $b);

        return $tuples;
    }

    /**
     * The five counters the sprint requires, plus the context that makes them
     * safe to act on.
     *
     * PENDING_INBOX_* is not decoration. Bucket C DRAINS A HUMAN APPROVAL
     * QUEUE: an operator who applies without seeing that number has silently
     * emptied an inbox another operator may have been working through.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'doctors' => $this->eligibleDoctorCount(),
            'trusted_devices' => $this->eligibleDeviceCount(),
            'matrix' => $this->matrixSize(),
            'existing_authorizations' => $this->existingActive(),
            'proposed_new_authorizations' => $this->proposedCreate(),
            'proposed_approvals_of_existing_pending' => $this->proposedApproveExisting(),
            'final_expected_authorizations' => $this->finalExpectedActive(),
            'blocked' => $this->blocked(),
            'blocked_rejected' => $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REJECTED),
            'blocked_revoked' => $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REVOKED),
            'blocked_unknown_status' => $this->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_UNKNOWN_STATUS),
            'unreachable' => $this->unreachable(),
            'excluded_doctors' => count($this->excludedDoctors),
            'excluded_devices' => count($this->excludedDevices),
            'existing_active_on_ineligible_device' => count($this->activeOnIneligibleDevice),
            'pending_inbox_before' => $this->pendingInboxBefore,
            'pending_inbox_after_expected' => $this->pendingInboxBefore - $this->proposedApproveExisting(),
            'plan_digest' => $this->digest(),
        ];
    }
}
