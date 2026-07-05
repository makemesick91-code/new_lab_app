<?php

namespace App\Services\Foundation;

use App\Support\Cache\RedisSharedCacheReadinessService;

/**
 * CACHE-1 — read-only Redis shared cache & session governance rule catalog.
 *
 * Publishes CACHE-R001..R012 into the foundation governance summary and
 * reports the current readiness signal. Informational only — never wired
 * into the blocking combinedDecision, matching the foundation-readiness
 * (not real Redis traffic switch) posture of CACHE-1.
 */
class CacheRedisGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'CACHE-R001',
                'title' => 'Single-node-safe runtime default until Redis is configured',
                'description' => 'The default runtime must stay single-node safe (current cache/session drivers unchanged) until Redis is explicitly configured and readiness is GO.',
            ],
            [
                'id' => 'CACHE-R002',
                'title' => 'No cache/session driver switch without readiness and rollback',
                'description' => 'CACHE_STORE or SESSION_DRIVER must never be switched to redis without first running the readiness command and documenting a rollback plan.',
            ],
            [
                'id' => 'CACHE-R003',
                'title' => 'Non-destructive, prefixed, short-TTL Redis healthchecks only',
                'description' => 'Redis healthchecks must be non-destructive, use a controlled key prefix and short TTL, and must never call FLUSHDB/FLUSHALL or delete a wildcard key.',
            ],
            [
                'id' => 'CACHE-R004',
                'title' => 'No Redis secrets in output, docs, logs, tests, or governance summary',
                'description' => 'Redis passwords/credentials must never appear in command output, documentation, logs, tests, or the governance summary — only a boolean "configured" flag is reported.',
            ],
            [
                'id' => 'CACHE-R005',
                'title' => 'Shared cache/session required for real multi-node; local cache is single-VPS-only',
                'description' => 'Real multi-node deployment requires a shared cache/session strategy; local file/database cache and session remain acceptable only for this single-VPS pilot.',
            ],
            [
                'id' => 'CACHE-R006',
                'title' => 'Distributed locks require a controlled prefix, TTL, and safe release',
                'description' => 'Any distributed-lock feature must use a controlled key prefix, a short TTL, and a safe token-checked release — never an unconditional delete.',
            ],
            [
                'id' => 'CACHE-R007',
                'title' => 'Cache invalidation design required before read-heavy caching for RME/payment/inventory/reports',
                'description' => 'Cache invalidation strategy for RME, payment, inventory stock, and reports must be designed and reviewed before enabling read-heavy caching on those domains.',
            ],
            [
                'id' => 'CACHE-R008',
                'title' => 'Session storage changes require login/logout/CSRF/cashier/RME/branch-context regression',
                'description' => 'Any session storage change must be tested against login/logout, CSRF, cashier/payment, RME finalization, and branch-context flows before rollout.',
            ],
            [
                'id' => 'CACHE-R009',
                'title' => 'Redis outage must fail safe and never double-submit critical writes',
                'description' => 'Redis outage/unavailability must fail safe (readiness reports unavailable/skip) and must never cause a critical transactional write to double-submit.',
            ],
            [
                'id' => 'CACHE-R010',
                'title' => 'Governance summary shows Redis/cache readiness without weakening other chains',
                'description' => 'The foundation governance summary must surface Redis/cache readiness without regressing STORAGE/STATELESS/LB/REPLICA governance chains.',
            ],
            [
                'id' => 'CACHE-R011',
                'title' => 'Redis is never the source of truth for stock, invoice, payment, RM, odontogram, or audit log',
                'description' => 'Redis must never be used as the source of truth for inventory stock, invoices, payments, medical records, odontogram data, or audit logs — PostgreSQL remains authoritative.',
            ],
            [
                'id' => 'CACHE-R012',
                'title' => 'Redis enablement is a separate sprint with canary and explicit rollback',
                'description' => 'Actually enabling Redis for cache/session in production must be done in a separate sprint with a canary rollout, smoke evidence, and an explicit rollback path.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(RedisSharedCacheReadinessService::class)->check();

        $decision = match ($readiness['status']) {
            'fail' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'readiness_status' => $readiness['status'],
            'redis_expected' => $readiness['redis_expected'],
            'cache_store' => $readiness['cache_store'],
            'session_driver' => $readiness['session_driver'],
            'redis_client_available' => $readiness['redis_client_available'],
            'warnings' => $readiness['warnings'],
        ];
    }
}
