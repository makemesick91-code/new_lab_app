<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4A — issue remediation lifecycle.
 *
 * Every transition is server-validated, locked, and audited. Hard issues can
 * never be waived, and "resolve" is a REVALIDATION (the rule engine decides) —
 * an operator can never mark a still-failing hard issue resolved. Waivers only
 * silence workspace triage; the canonical readiness engine is untouched.
 */
class SatusehatRemediationService
{
    public function __construct(
        private readonly SatusehatDataQualityIssueService $issues,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    public function acknowledge(SatusehatDataQualityIssue $issue, User $actor): SatusehatDataQualityIssue
    {
        return $this->transition($issue, $actor, [
            SatusehatDataQualityIssue::STATUS_OPEN,
            SatusehatDataQualityIssue::STATUS_REOPENED,
        ], SatusehatDataQualityIssue::STATUS_ACKNOWLEDGED, SatusehatAuditLog::EVENT_ISSUE_ACKNOWLEDGED);
    }

    public function assign(SatusehatDataQualityIssue $issue, User $actor, int $assigneeId): SatusehatDataQualityIssue
    {
        return DB::transaction(function () use ($issue, $actor, $assigneeId) {
            $locked = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            $this->assertOpen($locked);

            $locked->update(['assigned_to' => $assigneeId, 'assigned_at' => now()]);
            $this->log($locked, SatusehatAuditLog::EVENT_ISSUE_ASSIGNED, $actor, ['assigned_to' => $assigneeId]);

            return $locked;
        });
    }

    public function startRemediation(SatusehatDataQualityIssue $issue, User $actor): SatusehatDataQualityIssue
    {
        return $this->transition($issue, $actor, [
            SatusehatDataQualityIssue::STATUS_OPEN,
            SatusehatDataQualityIssue::STATUS_ACKNOWLEDGED,
            SatusehatDataQualityIssue::STATUS_REOPENED,
        ], SatusehatDataQualityIssue::STATUS_IN_REMEDIATION, SatusehatAuditLog::EVENT_ISSUE_REMEDIATION_STARTED);
    }

    public function requestClinicalReview(SatusehatDataQualityIssue $issue, User $actor): SatusehatDataQualityIssue
    {
        return $this->transition($issue, $actor, [
            SatusehatDataQualityIssue::STATUS_OPEN,
            SatusehatDataQualityIssue::STATUS_ACKNOWLEDGED,
            SatusehatDataQualityIssue::STATUS_IN_REMEDIATION,
            SatusehatDataQualityIssue::STATUS_REOPENED,
        ], SatusehatDataQualityIssue::STATUS_AWAITING_CLINICAL_REVIEW, SatusehatAuditLog::EVENT_ISSUE_CLINICAL_REVIEW_REQUESTED);
    }

    /**
     * Resolve = revalidate. The rule engine re-runs for the issue's candidate;
     * only when the defect is genuinely gone does the issue resolve.
     *
     * @throws ValidationException when the defect is still detected
     */
    public function resolve(SatusehatDataQualityIssue $issue, User $actor): SatusehatDataQualityIssue
    {
        $this->assertOpen($issue);

        $candidate = $issue->candidate()->firstOrFail();
        $this->issues->syncForCandidate($candidate, $actor);

        return DB::transaction(function () use ($issue, $actor) {
            $fresh = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);

            if ($fresh->isOpen()) {
                throw ValidationException::withMessages([
                    'issue' => 'Isu masih terdeteksi oleh rule engine — perbaiki data sumbernya terlebih dahulu.',
                ]);
            }

            if ($fresh->status === SatusehatDataQualityIssue::STATUS_RESOLVED) {
                $fresh->update(['resolution_type' => 'manual', 'resolved_by' => $actor->id]);
                $this->log($fresh, SatusehatAuditLog::EVENT_ISSUE_RESOLVED, $actor);
            }

            return $fresh;
        });
    }

    /**
     * @throws ValidationException hard issues can never be waived
     */
    public function waive(SatusehatDataQualityIssue $issue, User $actor, string $reason, ?string $expiresAt = null): SatusehatDataQualityIssue
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Alasan waiver wajib diisi.']);
        }

        return DB::transaction(function () use ($issue, $actor, $reason, $expiresAt) {
            $locked = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            $this->assertOpen($locked);

            if ($locked->isHard()) {
                throw ValidationException::withMessages([
                    'issue' => 'Isu keras (hard) tidak dapat dikecualikan — data harus benar-benar diperbaiki.',
                ]);
            }

            $locked->update([
                'status' => SatusehatDataQualityIssue::STATUS_WAIVED,
                'waived_by' => $actor->id,
                'waived_at' => now(),
                'waiver_reason' => trim($reason),
                'waiver_expires_at' => $expiresAt,
            ]);
            $this->log($locked, SatusehatAuditLog::EVENT_ISSUE_WAIVED, $actor, ['reason' => trim($reason)]);

            return $locked;
        });
    }

    public function reopen(SatusehatDataQualityIssue $issue, User $actor): SatusehatDataQualityIssue
    {
        return $this->transition($issue, $actor, [
            SatusehatDataQualityIssue::STATUS_RESOLVED,
            SatusehatDataQualityIssue::STATUS_WAIVED,
        ], SatusehatDataQualityIssue::STATUS_REOPENED, SatusehatAuditLog::EVENT_ISSUE_REOPENED, function (SatusehatDataQualityIssue $locked) {
            $locked->update([
                'resolved_at' => null,
                'resolution_type' => null,
                'resolved_by' => null,
                'waived_by' => null,
                'waived_at' => null,
                'waiver_reason' => null,
                'waiver_expires_at' => null,
            ]);
        });
    }

    /**
     * @param  list<string>  $fromStatuses
     */
    private function transition(
        SatusehatDataQualityIssue $issue,
        User $actor,
        array $fromStatuses,
        string $toStatus,
        string $event,
        ?\Closure $extra = null,
    ): SatusehatDataQualityIssue {
        return DB::transaction(function () use ($issue, $actor, $fromStatuses, $toStatus, $event, $extra) {
            $locked = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);

            if (! in_array($locked->status, $fromStatuses, true)) {
                throw ValidationException::withMessages([
                    'issue' => "Transisi tidak valid dari status {$locked->status}.",
                ]);
            }

            $locked->update(['status' => $toStatus]);
            if ($extra !== null) {
                $extra($locked);
            }
            $this->log($locked, $event, $actor);

            return $locked->refresh();
        });
    }

    private function assertOpen(SatusehatDataQualityIssue $issue): void
    {
        if (! $issue->isOpen()) {
            throw ValidationException::withMessages([
                'issue' => "Isu tidak dalam status terbuka (status: {$issue->status}).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(SatusehatDataQualityIssue $issue, string $event, User $actor, array $context = []): void
    {
        $this->audit->log(
            'data_quality_issue',
            (int) $issue->id,
            $event,
            $issue->rule_code,
            $context + ['status' => $issue->status, 'rule_code' => $issue->rule_code],
            (int) $issue->branch_id,
            $actor,
        );
    }
}
