<?php

namespace App\Services\Foundation;

use App\Support\Observability\CentralizedObservabilityReadinessService;

/**
 * OBS-2 — read-only centralized logging / error tracking governance rule
 * catalog.
 *
 * Publishes OBS-R013..R024 into the foundation governance summary and
 * reports the current readiness signal. Kept as a separate service from
 * ObservabilityGovernanceService (OBS-1, OBS-R001..R012) so the existing
 * OBS-1 governance section/tests are never touched — mirrors the
 * old-cache-governance vs cache-redis-governance separation. Informational
 * only — never wired into the blocking combinedDecision, matching the
 * foundation-readiness (not real vendor rollout) posture of OBS-2.
 */
class ObservabilityPipelineGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'OBS-R013',
                'title' => 'Centralized logging/error tracking stays OFF by default',
                'description' => 'Centralized logging and error tracking must remain OFF by default until an endpoint, a privacy review, and a rollback plan are all in place.',
            ],
            [
                'id' => 'OBS-R014',
                'title' => 'External log/error export never carries PII or secrets',
                'description' => 'External log/error export must never send PII, KTP/NIK, clinical notes, scan/consent content, payment-sensitive payload, secrets, tokens, cookies, or session ids.',
            ],
            [
                'id' => 'OBS-R015',
                'title' => 'DSN/API key/endpoint values never appear outside env/config',
                'description' => 'DSN, API key, and endpoint secrets may only come from environment/config and must never appear in command output, documentation, logs, tests, or the governance summary.',
            ],
            [
                'id' => 'OBS-R016',
                'title' => 'Request id/correlation id (OBS-1) must accompany every exported event',
                'description' => 'Every log/error event that is exported must carry the OBS-1 request id/correlation id for cross-system correlation.',
            ],
            [
                'id' => 'OBS-R017',
                'title' => 'Synthetic smoke stays non-PII, bounded, and safe',
                'description' => 'Synthetic logging/error-tracking smoke must be non-PII, bounded, and must never create an unhandled production exception.',
            ],
            [
                'id' => 'OBS-R018',
                'title' => 'Production debug mode stays off before external error tracking',
                'description' => 'Production debug mode must remain off before error tracking is enabled externally.',
            ],
            [
                'id' => 'OBS-R019',
                'title' => 'Central logging rollout requires retention, access-control, and redaction policy',
                'description' => 'A centralized logging rollout must have a documented retention policy, access control, and redaction policy before it carries live traffic.',
            ],
            [
                'id' => 'OBS-R020',
                'title' => 'Observability tooling never changes critical transaction flow',
                'description' => 'Alerting/error tracking must never change the flow of RME, payment, inventory movement, visit completion, or finalization transactions.',
            ],
            [
                'id' => 'OBS-R021',
                'title' => 'Readiness command stays non-destructive with no default external traffic',
                'description' => 'The readiness command must be non-destructive and must never clear logs, flush cache, delete sessions, or send external traffic by default.',
            ],
            [
                'id' => 'OBS-R022',
                'title' => 'Governance summary shows pipeline readiness without weakening other chains',
                'description' => 'The foundation governance summary must show centralized observability pipeline readiness without regressing STORAGE/STATELESS/LB/REPLICA/CACHE/OBS-1/NSF governance chains.',
            ],
            [
                'id' => 'OBS-R023',
                'title' => 'Vendor-adding sprints must include a data-processing/privacy note',
                'description' => 'Any future sprint that adds an observability vendor must include a data-processing/privacy note in its PR evidence before merge.',
            ],
            [
                'id' => 'OBS-R024',
                'title' => 'Log/error sampling must be configurable to protect the VPS and vendor',
                'description' => 'Log sampling and error sampling must be configurable so exported volume never floods the single VPS pilot or an external vendor.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(CentralizedObservabilityReadinessService::class)->check(false);

        $decision = match ($readiness['status']) {
            'fail' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'readiness_status' => $readiness['status'],
            'central_logging_enabled' => $readiness['central_logging_enabled'],
            'central_logging_driver' => $readiness['central_logging_driver'],
            'error_tracking_enabled' => $readiness['error_tracking_enabled'],
            'error_tracking_driver' => $readiness['error_tracking_driver'],
            'request_id_enabled' => $readiness['request_id_enabled'],
            'warnings' => $readiness['warnings'],
        ];
    }
}
