<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4C — issue SLA, priority, escalation, and review.
 *
 * Additive to the SATUSEHAT-4A remediation lifecycle (which owns
 * acknowledge/assign/start/resolve/waive/reopen). This service adds SLA-aware
 * assignment (priority + due date), escalation up the internal ladder, and a
 * review stamp. Every transition is locked + audited. It NEVER resolves a hard
 * issue (resolution stays a revalidation in SatusehatRemediationService), NEVER
 * waives, and NEVER performs an external request. Branch scope is enforced by
 * the policy/controller that loads the issue.
 */
class SatusehatIssueSlaService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * SLA-aware assignment: assignee + priority + routed role + computed due
     * date. Only open issues can be assigned.
     */
    public function assign(
        SatusehatDataQualityIssue $issue,
        User $actor,
        int $assigneeId,
        ?string $priority = null,
        ?string $assignedRole = null,
    ): SatusehatDataQualityIssue {
        return DB::transaction(function () use ($issue, $actor, $assigneeId, $priority, $assignedRole) {
            $locked = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            $this->assertOpen($locked);

            $priority = $this->resolvePriority($locked, $priority);
            $dueAt = $this->computeDueAt($priority);

            $locked->update([
                'assigned_to' => $assigneeId,
                'assigned_at' => now(),
                'assigned_role' => $assignedRole !== null ? mb_substr($assignedRole, 0, 60) : $locked->assigned_role,
                'priority' => $priority,
                'due_at' => $dueAt,
            ]);

            $this->log($locked, SatusehatAuditLog::EVENT_ISSUE_ASSIGNED, $actor, [
                'assigned_to' => $assigneeId,
                'priority' => $priority,
                'assigned_role' => $locked->assigned_role,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Escalate up the internal ladder. When no target is given, clinical rule
     * codes route to the clinical reviewer and everything else to IT operator.
     * Escalation can only move up (never down) the configured ladder.
     */
    public function escalate(SatusehatDataQualityIssue $issue, User $actor, ?string $target = null): SatusehatDataQualityIssue
    {
        $levels = (array) config('satusehat_pilot.escalation.levels', SatusehatDataQualityIssue::ESCALATION_LEVELS);

        return DB::transaction(function () use ($issue, $actor, $target, $levels) {
            $locked = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            $this->assertOpen($locked);

            $current = $locked->escalation_level ?: SatusehatDataQualityIssue::ESCALATION_NONE;
            $target = $target ?: $this->defaultEscalationTarget($locked);

            if (! in_array($target, $levels, true)) {
                throw ValidationException::withMessages(['escalation_level' => 'Tingkat eskalasi tidak dikenal.']);
            }

            $currentIndex = array_search($current, $levels, true);
            $targetIndex = array_search($target, $levels, true);
            if ($targetIndex === false || $currentIndex === false || $targetIndex <= $currentIndex) {
                throw ValidationException::withMessages([
                    'escalation_level' => 'Eskalasi hanya dapat naik ke tingkat yang lebih tinggi.',
                ]);
            }

            $locked->update(['escalation_level' => $target, 'escalated_at' => now()]);

            $this->log($locked, SatusehatAuditLog::EVENT_ISSUE_ESCALATED, $actor, [
                'from_level' => $current,
                'to_level' => $target,
            ]);

            return $locked->refresh();
        });
    }

    /** Stamp a review (does NOT resolve — resolution stays a revalidation). */
    public function markReviewed(SatusehatDataQualityIssue $issue, User $actor, ?string $evidence = null): SatusehatDataQualityIssue
    {
        return DB::transaction(function () use ($issue, $actor, $evidence) {
            $locked = SatusehatDataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);

            $locked->update([
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'resolution_evidence' => $evidence !== null ? mb_substr($evidence, 0, 500) : $locked->resolution_evidence,
            ]);

            $this->log($locked, SatusehatAuditLog::EVENT_ISSUE_REVIEWED, $actor);

            return $locked->refresh();
        });
    }

    public function computeDueAt(string $priority): Carbon
    {
        $hours = (array) config('satusehat_pilot.sla.due_hours', []);
        $h = (int) ($hours[$priority] ?? $hours[SatusehatDataQualityIssue::PRIORITY_NORMAL] ?? 72);

        return now()->addHours(max(1, $h));
    }

    private function resolvePriority(SatusehatDataQualityIssue $issue, ?string $priority): string
    {
        if ($priority !== null && in_array($priority, SatusehatDataQualityIssue::PRIORITIES, true)) {
            return $priority;
        }

        if ($issue->priority !== null && in_array($issue->priority, SatusehatDataQualityIssue::PRIORITIES, true)) {
            return $issue->priority;
        }

        return $issue->isHard()
            ? (string) config('satusehat_pilot.sla.hard_default_priority', SatusehatDataQualityIssue::PRIORITY_HIGH)
            : (string) config('satusehat_pilot.sla.default_priority', SatusehatDataQualityIssue::PRIORITY_NORMAL);
    }

    private function defaultEscalationTarget(SatusehatDataQualityIssue $issue): string
    {
        $clinical = (array) config('satusehat_pilot.escalation.clinical_rule_codes', []);

        return in_array($issue->rule_code, $clinical, true) ? 'clinical_reviewer' : 'it_operator';
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
