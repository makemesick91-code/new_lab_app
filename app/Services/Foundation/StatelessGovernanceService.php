<?php

namespace App\Services\Foundation;

use App\Support\Runtime\RuntimePortabilityReadinessService;

/**
 * STATELESS-1 — read-only runtime statelessness governance rule catalog.
 *
 * Publishes STATELESS-R001..R008 into the foundation governance summary and
 * reports the current readiness signal. Informational only, matching the
 * foundation-readiness (not full migration) posture of STATELESS-1.
 */
class StatelessGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'STATELESS-R001',
                'title' => 'No new local-ephemeral durable files',
                'description' => 'New features must not depend on local ephemeral container filesystem for durable user files; use the storage abstraction / object storage readiness path instead.',
            ],
            [
                'id' => 'STATELESS-R002',
                'title' => 'Restricted runtime writable paths',
                'description' => 'Only storage/ and bootstrap/cache/ may be runtime writable paths for the application.',
            ],
            [
                'id' => 'STATELESS-R003',
                'title' => 'Session/cache/queue driver auditability',
                'description' => 'Session, cache, and queue drivers must be auditable via runtime:stateless-readiness-check before every deploy.',
            ],
            [
                'id' => 'STATELESS-R004',
                'title' => 'Scheduler/worker idempotency guardrail before scale-out',
                'description' => 'Scheduler and queue worker jobs intended to run on multiple instances must have an idempotency/locking guardrail before scale-out.',
            ],
            [
                'id' => 'STATELESS-R005',
                'title' => 'Explicit cache rebuild on deploy',
                'description' => 'Deploys must explicitly rebuild config/route/view caches and must not depend on stale local state from a previous deploy.',
            ],
            [
                'id' => 'STATELESS-R006',
                'title' => 'Secrets/config from environment only',
                'description' => 'Runtime secrets and configuration must come from environment/config, never hardcoded in code, docs, or logs.',
            ],
            [
                'id' => 'STATELESS-R007',
                'title' => 'Centralized logging roadmap for scale-out',
                'description' => 'Local log files are acceptable for a single VPS pilot only; a centralized logging roadmap is required before horizontal scale-out.',
            ],
            [
                'id' => 'STATELESS-R008',
                'title' => 'Non-destructive readiness/smoke commands',
                'description' => 'Readiness and smoke commands must be non-destructive and safe to run directly on the VPS.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(RuntimePortabilityReadinessService::class)->check(false);

        $decision = match ($readiness['status']) {
            'fail' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'readiness_status' => $readiness['status'],
            'session_driver' => $readiness['session_driver'],
            'cache_store' => $readiness['cache_store'],
            'queue_connection' => $readiness['queue_connection'],
            'horizontal_scale_warnings' => $readiness['horizontal_scale_warnings'],
        ];
    }
}
