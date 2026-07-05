<?php

namespace App\Services\Foundation;

use App\Support\Observability\ObservabilityReadinessService;

/**
 * OBS-1 — read-only request id / correlation id / observability governance
 * rule catalog.
 *
 * Publishes OBS-R001..OBS-R012 into the foundation governance summary and
 * reports the current readiness signal. Informational only — never wired
 * into the blocking combinedDecision, matching the foundation-readiness
 * (not full APM/ELK/Sentry/NewRelic rollout) posture of OBS-1.
 */
class ObservabilityGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'OBS-R001',
                'title' => 'Every HTTP request carries a safe, bounded request/correlation id',
                'description' => 'Every HTTP request must be attached to a safe, length-bounded request id and correlation id so logs can be correlated across a request lifecycle.',
            ],
            [
                'id' => 'OBS-R002',
                'title' => 'Inbound correlation headers are trusted only with strict validation',
                'description' => 'Inbound X-Request-ID/X-Correlation-ID headers must only be trusted when strict validation (length + safe character set) is active; otherwise a generated id must replace them.',
            ],
            [
                'id' => 'OBS-R003',
                'title' => 'Log context stays minimal request metadata, never sensitive content',
                'description' => 'Log context may include minimal request metadata (request id, correlation id, method, path, route name) but must never include PII, secrets, tokens, cookies, session id, full request payload, or raw clinical notes.',
            ],
            [
                'id' => 'OBS-R004',
                'title' => 'Sensitive clinical/financial data is masked or excluded from logs',
                'description' => 'KTP/NIK, medical notes, private scan paths, consent content, and payment-sensitive/audit-sensitive payloads must be masked or excluded from all logs.',
            ],
            [
                'id' => 'OBS-R005',
                'title' => 'Readiness command is non-destructive and never dumps sensitive log content',
                'description' => 'The observability readiness command must be non-destructive and must never display the contents of sensitive log files.',
            ],
            [
                'id' => 'OBS-R006',
                'title' => 'Public health/diagnostic endpoints stay minimal and non-sensitive',
                'description' => 'Any public health/diagnostic endpoint must remain minimal and non-sensitive, never exposing environment variables, configuration internals, or stack traces.',
            ],
            [
                'id' => 'OBS-R007',
                'title' => 'Multi-node deployment requires request id propagation across instances',
                'description' => 'Before real multi-node deployment, request id propagation must be verified so logs remain traceable across app instances.',
            ],
            [
                'id' => 'OBS-R008',
                'title' => 'Queue/job correlation propagation required before expanding async workflows',
                'description' => 'Correlation id propagation into queue/job execution must be designed and implemented before significantly expanding asynchronous workflows.',
            ],
            [
                'id' => 'OBS-R009',
                'title' => 'Centralized logging/APM roadmap does not justify sending PII externally',
                'description' => 'Centralized logging/APM adoption is a future scale roadmap item, not a justification to send PII or secrets to an external vendor without an explicit privacy/security review.',
            ],
            [
                'id' => 'OBS-R010',
                'title' => 'Production debug mode stays off',
                'description' => 'APP_DEBUG must remain off in production; this is treated as a warning (and a failure under strict mode) if detected otherwise.',
            ],
            [
                'id' => 'OBS-R011',
                'title' => 'Governance summary surfaces observability readiness without weakening other chains',
                'description' => 'The foundation governance summary must show observability readiness without regressing the STORAGE/STATELESS/LB/REPLICA/CACHE/NSF governance chains.',
            ],
            [
                'id' => 'OBS-R012',
                'title' => 'New logging in future sprints must state its PII/secret masking strategy',
                'description' => 'Any sprint that adds new logging must document its PII/secret masking strategy explicitly in PR evidence before merge.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(ObservabilityReadinessService::class)->check(false);

        $decision = match ($readiness['status']) {
            'fail' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'readiness_status' => $readiness['status'],
            'observability_enabled' => $readiness['observability_enabled'],
            'request_id_enabled' => $readiness['request_id_enabled'],
            'middleware_detected' => $readiness['middleware_detected'],
            'log_channel' => $readiness['log_channel'],
            'trust_inbound_request_id' => $readiness['trust_inbound_request_id'],
            'trust_inbound_correlation_id' => $readiness['trust_inbound_correlation_id'],
            'warnings' => $readiness['warnings'],
        ];
    }
}
