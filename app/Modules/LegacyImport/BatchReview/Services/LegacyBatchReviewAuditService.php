<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Support\DeveloperConsole\SensitiveValueMasker;

/**
 * Audit trail for batch review — PR1 §15.
 *
 * Wraps the shared sys_audit_logs writer rather than adding another audit table,
 * mirroring LegacyRmeAuditService and LegacyMassUploadAuditService. What this
 * class adds is a PAYLOAD POLICY, which the shared writer does not have.
 *
 * WHAT THESE EVENTS ARE FOR — AND WHAT THEY ARE NOT
 * -------------------------------------------------
 * §15 is explicit: the batch event carries COUNTS AND REFERENCES, and the
 * per-item canonical audit stays authoritative. So BATCH_REVIEW_SUBMITTED says
 * "this actor submitted 74 attestations, 72 applied, 2 refused, in session
 * <uuid>" — it does NOT restate 72 clinical reviews. Those are already written
 * by the canonical review service as LEGACY_RME_IMPORT_REVIEWED /
 * LEGACY_ODONTOGRAM_IMPORT_REVIEWED, now carrying the BATCH channel so an
 * auditor can tell which surface asked.
 *
 * A BATCH EVENT IS NOT AN AUTHORIZATION RECORD. It records that a submit
 * happened. Whether each item was permitted was decided, and audited, by the
 * canonical layer.
 *
 * WHAT MAY NEVER BE RECORDED
 * --------------------------
 * Patient name, KTP/NIK, date of birth, any clinical content, any page bytes,
 * any absolute path, any disk name, and the raw text of an internal exception.
 * Enforcement is structural: every value is reduced to a bounded masked scalar
 * and keys outside the allowlist are dropped, so a caller cannot leak by
 * accident. The reviewer's free-text triage note is DELIBERATELY ABSENT from
 * the allowlist — it is operator prose that may name a patient, so it lives on
 * the decision row behind authorization and never in a permanent audit payload.
 */
class LegacyBatchReviewAuditService
{
    public const ENTITY_SESSION = 'legacy_batch_review_session';

    public const ENTITY_DECISION = 'legacy_batch_review_decision';

    public const ENTITY_TRIAGE = 'legacy_review_triage';

    public const SESSION_OPENED = 'LEGACY_BATCH_REVIEW_SESSION_OPENED';

    public const ITEM_DECIDED = 'LEGACY_BATCH_REVIEW_ITEM_DECIDED';

    public const SUBMITTED = 'LEGACY_BATCH_REVIEW_SUBMITTED';

    public const SESSION_ABANDONED = 'LEGACY_BATCH_REVIEW_SESSION_ABANDONED';

    /**
     * Fills the review-time refusal gap the audit found on the RME side: the
     * canonical single-item review writes no refusal event, so a batch refusal
     * would otherwise leave no trail at all. Scoped to the batch path only —
     * this sprint does not retrofit the single-item page.
     */
    public const ITEM_REFUSED = 'LEGACY_BATCH_REVIEW_ITEM_REFUSED';

    public const TRIAGE_RAISED = 'LEGACY_REVIEW_TRIAGE_RAISED';

    public const TRIAGE_CHANGED = 'LEGACY_REVIEW_TRIAGE_CHANGED';

    public const TRIAGE_CLEARED = 'LEGACY_REVIEW_TRIAGE_CLEARED';

    /**
     * Keys permitted in an audit payload.
     *
     * An allowlist, not a denylist: a denylist protects against the leaks you
     * thought of, an allowlist against the ones you did not. Note the absence of
     * `reason_note`, `patient_name` and anything page-related.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'session_uuid',
        'import_type',
        'status',
        'previous_status',
        'import_id',
        'patient_id',
        'branch_id',
        'origin_branch_id',
        'source_sha256',
        'decision',
        'previous_decision',
        'reason_code',
        'triage_status',
        'previous_triage_status',
        'submit_status',
        'refusal_code',
        'marked_reviewed',
        'marked_blocked',
        'marked_attention',
        'applied_reviewed',
        'refused_reviewed',
        'attempted',
        'skipped',
        'channel',
        'refusal_counts',
        'decision_counts',
    ];

    private const MAX_STRING_LENGTH = 200;

    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function logSessionEvent(
        string $action,
        ?LegacyBatchReviewSession $session,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $this->auditLogs->log(
            self::ENTITY_SESSION,
            $session?->getKey() !== null ? (int) $session->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->sessionContext($session)),
            $actor,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function logDecisionEvent(
        string $action,
        ?LegacyBatchReviewItemDecision $decision,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $this->auditLogs->log(
            self::ENTITY_DECISION,
            $decision?->getKey() !== null ? (int) $decision->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->decisionContext($decision)),
            $actor,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function logTriageEvent(
        string $action,
        ?LegacyReviewTriage $triage,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $this->auditLogs->log(
            self::ENTITY_TRIAGE,
            $triage?->getKey() !== null ? (int) $triage->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->triageContext($triage)),
            $actor,
        );
    }

    /** @return array<string, mixed> */
    private function sessionContext(?LegacyBatchReviewSession $session): array
    {
        if ($session === null) {
            return [];
        }

        return [
            'session_uuid' => (string) $session->uuid,
            'import_type' => (string) $session->import_type,
            'status' => (string) $session->status,
            'origin_branch_id' => $session->origin_branch_id,
        ];
    }

    /** @return array<string, mixed> */
    private function decisionContext(?LegacyBatchReviewItemDecision $decision): array
    {
        if ($decision === null) {
            return [];
        }

        return [
            'import_type' => (string) $decision->import_type,
            'import_id' => $decision->importId(),
            'patient_id' => $decision->patient_id,
            'source_sha256' => $decision->source_sha256,
            'decision' => (string) $decision->decision,
            'reason_code' => $decision->reason_code,
            'submit_status' => (string) $decision->submit_status,
            'refusal_code' => $decision->refusal_code,
        ];
    }

    /** @return array<string, mixed> */
    private function triageContext(?LegacyReviewTriage $triage): array
    {
        if ($triage === null) {
            return [];
        }

        return [
            'import_type' => (string) $triage->import_type,
            'import_id' => $triage->importId(),
            'patient_id' => $triage->patient_id,
            'triage_status' => (string) $triage->triage_status,
            'reason_code' => $triage->reason_code,
        ];
    }

    /**
     * Reduce a payload to allowlisted, bounded, masked scalars.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safePayload(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                // Masked FIRST, then bounded. Truncating before masking could
                // cut a pattern in half and let a fragment through.
                $safe[$key] = mb_substr($this->masker->mask($value), 0, self::MAX_STRING_LENGTH);

                continue;
            }

            if (is_array($value)) {
                // One level, scalar leaves only, and only for the counting
                // payloads that legitimately need it (refusal_counts,
                // decision_counts). Anything deeper is a caller dumping a model.
                $flat = [];

                foreach ($value as $innerKey => $innerValue) {
                    if (! is_string($innerKey) && ! is_int($innerKey)) {
                        continue;
                    }

                    if (is_int($innerValue) || is_float($innerValue) || is_bool($innerValue) || $innerValue === null) {
                        $flat[(string) $innerKey] = $innerValue;

                        continue;
                    }

                    if (is_string($innerValue)) {
                        $flat[(string) $innerKey] = mb_substr(
                            $this->masker->mask($innerValue),
                            0,
                            self::MAX_STRING_LENGTH
                        );
                    }
                }

                $safe[$key] = $flat;
            }
        }

        return $safe;
    }
}
