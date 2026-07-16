<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Models\User;
use App\Modules\Satusehat\Interfaces\SatusehatDataQualityRuleInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;
use Illuminate\Support\Facades\DB;

/**
 * SATUSEHAT-4A — deterministic, idempotent issue synchronization.
 *
 * For one candidate: refresh the canonical readiness verdict (reuse, never
 * re-implement), run every registered rule, then upsert issues by fingerprint.
 * Issues no longer detected auto-resolve (`revalidated`); re-detected resolved
 * issues reopen. NEVER performs HTTP; never stores PII.
 */
class SatusehatDataQualityIssueService
{
    public function __construct(
        private readonly SatusehatCandidateService $candidates,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @return array{candidate_id: int, detected: int, created: int, reopened: int, auto_resolved: int, open: int}
     */
    public function syncForCandidate(SatusehatCandidate $candidate, ?User $actor = null, bool $refresh = true): array
    {
        if ($refresh) {
            $candidate = $this->candidates->refresh($candidate, $actor);
        }

        $context = $this->buildContext($candidate);
        $drafts = [];
        foreach ((array) config('satusehat_data_quality.rules', []) as $ruleClass) {
            /** @var SatusehatDataQualityRuleInterface $rule */
            $rule = app($ruleClass);
            foreach ($rule->evaluate($context) as $draft) {
                $drafts[$this->fingerprint($candidate, $draft)] = $draft;
            }
        }

        $summary = ['candidate_id' => (int) $candidate->id, 'detected' => count($drafts), 'created' => 0, 'reopened' => 0, 'auto_resolved' => 0, 'open' => 0];

        DB::transaction(function () use ($candidate, $drafts, &$summary) {
            $now = now();
            $existing = SatusehatDataQualityIssue::query()
                ->where('satusehat_candidate_id', $candidate->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('fingerprint');

            foreach ($drafts as $fingerprint => $draft) {
                /** @var SatusehatDataQualityIssue|null $issue */
                $issue = $existing->get($fingerprint);

                if ($issue === null) {
                    SatusehatDataQualityIssue::create([
                        'environment' => (string) $candidate->environment,
                        'branch_id' => (int) $candidate->branch_id,
                        'satusehat_candidate_id' => (int) $candidate->id,
                        'clinic_visit_id' => $candidate->clinic_visit_id,
                        'patient_id' => $candidate->patient_id,
                        'doctor_id' => $candidate->doctor_id,
                        'rule_code' => $draft['rule_code'],
                        'severity' => $draft['severity'],
                        'status' => $draft['initial_status'] ?? SatusehatDataQualityIssue::STATUS_OPEN,
                        'fingerprint' => $fingerprint,
                        'entity_type' => $draft['entity_type'],
                        'entity_id' => $draft['entity_id'],
                        'field_path' => $draft['field_path'],
                        'message' => $draft['message'],
                        'remediation_action' => $draft['remediation_action'],
                        'owner_role' => $draft['owner_role'],
                        'source_hash' => $candidate->source_hash,
                        'first_detected_at' => $now,
                        'last_detected_at' => $now,
                        'metadata' => $draft['metadata'] ?? [],
                    ]);
                    $summary['created']++;

                    continue;
                }

                $updates = [
                    'last_detected_at' => $now,
                    'severity' => $draft['severity'],
                    'message' => $draft['message'],
                    'remediation_action' => $draft['remediation_action'],
                    'owner_role' => $draft['owner_role'],
                    'source_hash' => $candidate->source_hash,
                    'metadata' => $draft['metadata'] ?? [],
                ];

                $waiverExpired = $issue->status === SatusehatDataQualityIssue::STATUS_WAIVED
                    && $issue->waiver_expires_at !== null
                    && $issue->waiver_expires_at->isPast();

                if ($issue->status === SatusehatDataQualityIssue::STATUS_RESOLVED || $waiverExpired) {
                    $updates['status'] = SatusehatDataQualityIssue::STATUS_REOPENED;
                    $updates['resolved_at'] = null;
                    $updates['resolution_type'] = null;
                    $updates['resolved_by'] = null;
                    $summary['reopened']++;
                }

                $issue->update($updates);
            }

            // Auto-resolve issues whose defect is no longer detected.
            $stale = $existing->filter(
                fn (SatusehatDataQualityIssue $issue) => ! array_key_exists($issue->fingerprint, $drafts)
                    && $issue->isOpen()
            );
            foreach ($stale as $issue) {
                $issue->update([
                    'status' => SatusehatDataQualityIssue::STATUS_RESOLVED,
                    'resolved_at' => $now,
                    'resolution_type' => 'revalidated',
                ]);
                $summary['auto_resolved']++;
            }
        });

        $summary['open'] = SatusehatDataQualityIssue::query()
            ->where('satusehat_candidate_id', $candidate->id)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES)
            ->count();

        $this->audit->log(
            'satusehat_candidate',
            (int) $candidate->id,
            SatusehatAuditLog::EVENT_DATA_QUALITY_SCANNED,
            'Data-quality scan kandidat',
            $summary,
            (int) $candidate->branch_id,
            $actor,
        );

        return $summary;
    }

    private function buildContext(SatusehatCandidate $candidate): SatusehatDataQualityContext
    {
        $visit = $candidate->clinicVisit()
            ->with(['patient', 'doctor', 'medicalRecord', 'branch', 'clinicRoom'])
            ->first();

        $codes = collect((array) $candidate->readiness_reasons)->pluck('code')->filter()->values()->all();
        $dentalCodes = collect((array) $candidate->dental_readiness_reasons)->pluck('code')->filter()->values()->all();

        return new SatusehatDataQualityContext(
            candidate: $candidate,
            visit: $visit,
            reasonCodes: $codes,
            dentalReasonCodes: $dentalCodes,
            environment: (string) $candidate->environment,
        );
    }

    /**
     * Deterministic idempotency key: same defect ⇒ same fingerprint, always.
     *
     * @param  array<string, mixed>  $draft
     */
    private function fingerprint(SatusehatCandidate $candidate, array $draft): string
    {
        return hash('sha256', implode('|', [
            (string) $candidate->environment,
            (string) $candidate->id,
            (string) $draft['rule_code'],
            (string) ($draft['entity_type'] ?? ''),
            (string) ($draft['entity_id'] ?? ''),
            (string) ($draft['field_path'] ?? ''),
        ]));
    }
}
