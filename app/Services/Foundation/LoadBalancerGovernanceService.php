<?php

namespace App\Services\Foundation;

use App\Support\LoadBalancer\LoadBalancerReadinessService;

/**
 * LB-1 — read-only load balancer pilot governance rule catalog.
 *
 * Publishes LB-R001..R010 into the foundation governance summary and reports
 * the current readiness signal. Informational only, matching the
 * foundation-readiness (not real multi-node traffic switch) posture of LB-1.
 */
class LoadBalancerGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'LB-R001',
                'title' => 'Explicit trusted proxy configuration required behind a reverse proxy',
                'description' => 'The application must be able to run safely behind a reverse proxy/load balancer only with an explicit trusted proxy IP/CIDR configuration, never a blanket trust-all default.',
            ],
            [
                'id' => 'LB-R002',
                'title' => 'No raw client IP trust without forwarded-header consideration',
                'description' => 'New features must not rely on the raw connecting IP for security/rate-limit decisions without accounting for trusted forwarded headers.',
            ],
            [
                'id' => 'LB-R003',
                'title' => 'Minimal, non-authenticated, non-sensitive health endpoint',
                'description' => 'The load balancer health endpoint must stay minimal, unauthenticated, and must never expose PII, secrets, or internal configuration.',
            ],
            [
                'id' => 'LB-R004',
                'title' => 'HTTPS/proxy termination verified before secure-cookie or redirect changes',
                'description' => 'HTTPS termination at the proxy must be verified before enabling secure-cookie enforcement or a global HTTPS redirect.',
            ],
            [
                'id' => 'LB-R005',
                'title' => 'Shared session/cache/queue strategy required for real multi-node',
                'description' => 'Real multi-node deployment requires a shared session/cache/queue strategy; single-VPS local-disk warnings must not be ignored when scaling out.',
            ],
            [
                'id' => 'LB-R006',
                'title' => 'Non-destructive load balancer smoke checks',
                'description' => 'Load balancer readiness/smoke commands must be non-destructive and safe to run directly on the VPS.',
            ],
            [
                'id' => 'LB-R007',
                'title' => 'Explicit cache rebuild and rollback path on deploy behind LB',
                'description' => 'Deploying behind a load balancer must preserve an explicit cache rebuild step and a clear rollback path.',
            ],
            [
                'id' => 'LB-R008',
                'title' => 'Request/correlation id roadmap before large multi-node traffic',
                'description' => 'A request-id/correlation-id observability roadmap is required before routing significant multi-node production traffic.',
            ],
            [
                'id' => 'LB-R009',
                'title' => 'Governance summary shows LB readiness without weakening other chains',
                'description' => 'The foundation governance summary must surface load balancer readiness without regressing STORAGE/STATELESS/NSF governance chains.',
            ],
            [
                'id' => 'LB-R010',
                'title' => 'No public diagnostic endpoint exposing internal state',
                'description' => 'No new public diagnostic endpoint may expose internal configuration, secrets, PII, or stack traces.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(LoadBalancerReadinessService::class)->check();

        $decision = match ($readiness['status']) {
            'fail' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'readiness_status' => $readiness['status'],
            'trusted_proxies_configured' => $readiness['trusted_proxies_configured'],
            'health_endpoint_enabled' => $readiness['health_endpoint_enabled'],
            'expect_forwarded_headers' => $readiness['expect_forwarded_headers'],
            'warnings' => $readiness['warnings'],
        ];
    }
}
