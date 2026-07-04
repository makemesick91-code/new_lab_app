<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * CACHE-1 — Read-only cache governance validator.
 *
 * Validates config/cache_governance.php completeness, allowed/denied categories,
 * branch/global key policy, feature flags, and optional Redis probe.
 *
 * Emits GO / WATCH / FAIL:
 *  - GO    : governance valid; Redis probe not required or passed.
 *  - WATCH : Redis probe requested but Redis unavailable while runtime disabled.
 *  - FAIL  : config incomplete, denied category allowed, risky category missing
 *            invalidation, Redis runtime enabled but probe fails, secrets exposed.
 */
class CacheGovernanceService
{
    /** @var list<string> */
    private const INVALIDATION_REQUIRED_FIELDS = [
        'trigger',
        'scope',
        'affected_key_pattern',
        'fallback',
        'owner',
        'tests_required',
    ];

    /** @var list<string> */
    private const CATEGORY_REQUIRED_FIELDS = [
        'scope',
        'ttl_seconds',
        'allowed_store',
        'requires_invalidation',
        'invalidation_events',
        'pii_allowed',
        'branch_scope_required',
    ];

    /**
     * @return array<string, mixed>
     */
    public function collect(bool $includeRedisProbe = false): array
    {
        $config = config('cache_governance');

        if (! is_array($config) || $config === []) {
            return $this->finalize([], [
                $this->fail('CACHE-GOV-CONFIG-EXISTS', 'config/cache_governance.php is missing or empty.'),
            ], $includeRedisProbe, null);
        }

        $checks = [];
        $checks[] = $this->pass('CACHE-GOV-CONFIG-EXISTS', 'cache_governance config present and non-empty.');

        $metadata = (array) ($config['metadata'] ?? []);
        $checks[] = ($metadata['sprint'] ?? '') === 'CACHE-1' && ($metadata['status'] ?? '') === 'implemented'
            ? $this->pass('CACHE-GOV-METADATA', 'CACHE-1 metadata present with implemented status.')
            : $this->fail('CACHE-GOV-METADATA', 'CACHE-1 metadata missing or incomplete.');

        $globalRules = (array) ($config['global_rules'] ?? []);
        $requiredGlobalRules = [
            'no_pii_in_cache_keys',
            'no_pii_in_cache_values',
            'no_secrets_in_cache',
            'branch_scoped_keys_required',
            'global_key_allowlist_required',
            'invalidation_required_before_runtime_cache',
            'risky_cache_requires_feature_flag',
            'critical_mutable_data_cache_denied_by_default',
        ];
        $missingRules = array_filter(
            $requiredGlobalRules,
            fn (string $rule) => ! ($globalRules[$rule] ?? false)
        );
        $checks[] = $missingRules === []
            ? $this->pass('CACHE-GOV-GLOBAL-RULES', 'All global cache safety rules are enabled.')
            : $this->fail('CACHE-GOV-GLOBAL-RULES', 'Missing/disabled global rules: '.implode(', ', $missingRules));

        $keyNaming = (array) ($config['key_naming'] ?? []);
        $checks[] = ($keyNaming['prefix'] ?? '') === 'daengtisiams'
            ? $this->pass('CACHE-GOV-KEY-PREFIX', 'Cache key prefix is daengtisiams.')
            : $this->fail('CACHE-GOV-KEY-PREFIX', 'Cache key prefix must be daengtisiams.');

        $forbiddenIds = (array) ($keyNaming['forbidden_raw_identifiers'] ?? []);
        $checks[] = $forbiddenIds !== []
            ? $this->pass('CACHE-GOV-KEY-PII-BAN', 'PII/secrets banned from raw cache key identifiers.')
            : $this->fail('CACHE-GOV-KEY-PII-BAN', 'Forbidden raw identifiers list is empty.');

        $allowed = (array) ($config['allowed_cache_categories'] ?? []);
        $denied = (array) ($config['denied_cache_categories'] ?? []);
        $allowlist = (array) ($config['global_key_allowlist'] ?? []);

        $checks[] = $denied !== []
            ? $this->pass('CACHE-GOV-DENIED-CATEGORIES', count($denied).' denied cache categories documented.')
            : $this->fail('CACHE-GOV-DENIED-CATEGORIES', 'No denied cache categories defined.');

        $allowedViolations = [];
        foreach ($allowed as $key => $category) {
            if (! is_array($category)) {
                $allowedViolations[] = "{$key}(invalid)";

                continue;
            }

            $gaps = array_filter(
                self::CATEGORY_REQUIRED_FIELDS,
                fn (string $field) => ! array_key_exists($field, $category)
            );
            if ($gaps !== []) {
                $allowedViolations[] = sprintf('%s(%s)', $key, implode(',', $gaps));
            }

            if (($category['pii_allowed'] ?? true) !== false) {
                $allowedViolations[] = "{$key}(pii_allowed_not_false)";
            }

            if (($category['requires_invalidation'] ?? false) === true) {
                $inv = (array) ($category['invalidation'] ?? []);
                $invGaps = array_filter(
                    self::INVALIDATION_REQUIRED_FIELDS,
                    fn (string $field) => ! array_key_exists($field, $inv) || $inv[$field] === null || $inv[$field] === ''
                );
                if ($invGaps !== []) {
                    $allowedViolations[] = sprintf('%s(invalidation:%s)', $key, implode(',', $invGaps));
                }
            }
        }
        $checks[] = $allowedViolations === []
            ? $this->pass('CACHE-GOV-ALLOWED-CATEGORIES', count($allowed).' allowed categories complete with invalidation policy.')
            : $this->fail('CACHE-GOV-ALLOWED-CATEGORIES', 'Allowed category violations: '.implode('; ', $allowedViolations));

        $branchViolations = [];
        $globalViolations = [];
        foreach ($allowed as $key => $category) {
            if (! is_array($category)) {
                continue;
            }

            $scope = (string) ($category['scope'] ?? '');
            $branchRequired = (bool) ($category['branch_scope_required'] ?? false);

            if ($scope === 'branch' && ! $branchRequired) {
                $branchViolations[] = "{$key}(branch_scope_required_false)";
            }

            if ($scope === 'global' && ! in_array($key, $allowlist, true)) {
                $globalViolations[] = $key;
            }
        }
        $checks[] = $branchViolations === []
            ? $this->pass('CACHE-GOV-BRANCH-SCOPE', 'Branch-scoped categories require branch key segment.')
            : $this->fail('CACHE-GOV-BRANCH-SCOPE', 'Branch scope violations: '.implode('; ', $branchViolations));

        $checks[] = $globalViolations === []
            ? $this->pass('CACHE-GOV-GLOBAL-ALLOWLIST', 'Global categories are explicitly allowlisted.')
            : $this->fail('CACHE-GOV-GLOBAL-ALLOWLIST', 'Global categories not allowlisted: '.implode(', ', $globalViolations));

        $overlap = array_intersect(array_keys($allowed), array_keys($denied));
        $checks[] = $overlap === []
            ? $this->pass('CACHE-GOV-NO-DENIED-ALLOWED-OVERLAP', 'No denied category appears in allowed list.')
            : $this->fail('CACHE-GOV-NO-DENIED-ALLOWED-OVERLAP', 'Denied categories incorrectly allowed: '.implode(', ', $overlap));

        $redis = (array) ($config['redis_readiness'] ?? []);
        $checks[] = ($redis['default_status'] ?? '') === 'readiness_only' && ($redis['production_default_enabled'] ?? true) === false
            ? $this->pass('CACHE-GOV-REDIS-READINESS-ONLY', 'Redis is readiness-only; production default disabled.')
            : $this->fail('CACHE-GOV-REDIS-READINESS-ONLY', 'Redis readiness policy must be readiness_only with production_default_enabled=false.');

        $invalidationPolicy = (array) ($config['invalidation_policy'] ?? []);
        $checks[] = isset($invalidationPolicy['emergency_full_cache_clear'])
            ? $this->pass('CACHE-GOV-INVALIDATION-EMERGENCY', 'Emergency full cache clear policy documented.')
            : $this->fail('CACHE-GOV-INVALIDATION-EMERGENCY', 'Emergency full cache clear policy missing.');

        $flagKeys = (array) ($config['feature_flags'] ?? []);
        $flags = app(FeatureFlagService::class);
        $flagViolations = [];
        foreach ($flagKeys as $flagKey) {
            try {
                $flags->assertKnown((string) $flagKey);
            } catch (Throwable) {
                $flagViolations[] = (string) $flagKey;
            }
        }
        $checks[] = $flagViolations === []
            ? $this->pass('CACHE-GOV-FEATURE-FLAGS', 'Required cache feature flags exist in registry.')
            : $this->fail('CACHE-GOV-FEATURE-FLAGS', 'Missing cache feature flags: '.implode(', ', $flagViolations));

        $neverPrint = (array) ($redis['env_keys_never_print'] ?? []);
        $checks[] = in_array('REDIS_PASSWORD', $neverPrint, true) && in_array('APP_KEY', $neverPrint, true)
            ? $this->pass('CACHE-GOV-SECRETS-NEVER-PRINT', 'Secret env keys are configured never to print.')
            : $this->fail('CACHE-GOV-SECRETS-NEVER-PRINT', 'env_keys_never_print must include REDIS_PASSWORD and APP_KEY.');

        $redisRuntimeEnabled = $this->isRedisRuntimeEnabled($flags);
        $redisProbe = null;

        if ($includeRedisProbe) {
            $redisProbe = $this->probeRedis($redis, $redisRuntimeEnabled);
            $checks[] = match ($redisProbe['decision']) {
                'GO' => $this->pass('CACHE-GOV-REDIS-PROBE', $redisProbe['message']),
                'WATCH' => $this->warn('CACHE-GOV-REDIS-PROBE', $redisProbe['message']),
                default => $this->fail('CACHE-GOV-REDIS-PROBE', $redisProbe['message']),
            };
        } else {
            $checks[] = $this->pass('CACHE-GOV-REDIS-PROBE-SKIPPED', 'Redis probe skipped (not requested); normal GO does not require Redis server.');
        }

        if ($redisRuntimeEnabled && ! $includeRedisProbe) {
            $checks[] = $this->warn(
                'CACHE-GOV-REDIS-RUNTIME-PROBE-RECOMMENDED',
                'Redis runtime is enabled — run with --include-redis-probe before production enablement.'
            );
        }

        return $this->finalize($config, $checks, $includeRedisProbe, $redisProbe);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $redisProbe
     * @return array<string, mixed>
     */
    private function finalize(array $config, array $checks, bool $includeRedisProbe, ?array $redisProbe): array
    {
        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        $allowed = (array) ($config['allowed_cache_categories'] ?? []);
        $denied = (array) ($config['denied_cache_categories'] ?? []);

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'CACHE-1',
            'environment' => (string) config('app.env'),
            'cache_store' => (string) config('cache.default'),
            'redis_runtime_enabled' => $this->isRedisRuntimeEnabled(app(FeatureFlagService::class)),
            'redis_probe_requested' => $includeRedisProbe,
            'redis_probe' => $redisProbe,
            'metadata' => $config['metadata'] ?? [],
            'global_rules' => $config['global_rules'] ?? [],
            'key_naming' => [
                'prefix' => $config['key_naming']['prefix'] ?? null,
                'forbidden_raw_identifiers' => $config['key_naming']['forbidden_raw_identifiers'] ?? [],
            ],
            'allowed_categories' => array_map(fn (array $cat, string $key) => [
                'key' => $key,
                'scope' => $cat['scope'] ?? null,
                'ttl_seconds' => $cat['ttl_seconds'] ?? null,
                'allowed_store' => $cat['allowed_store'] ?? null,
                'requires_invalidation' => $cat['requires_invalidation'] ?? null,
                'pii_allowed' => $cat['pii_allowed'] ?? null,
                'branch_scope_required' => $cat['branch_scope_required'] ?? null,
                'feature_flag' => $cat['feature_flag'] ?? null,
            ], $allowed, array_keys($allowed)),
            'denied_categories' => array_map(fn (array $cat, string $key) => [
                'key' => $key,
                'reason' => $cat['reason'] ?? null,
                'owner' => $cat['owner'] ?? null,
            ], $denied, array_keys($denied)),
            'redis_readiness' => [
                'default_status' => $config['redis_readiness']['default_status'] ?? null,
                'production_default_enabled' => $config['redis_readiness']['production_default_enabled'] ?? null,
                'env_keys_allowed' => $config['redis_readiness']['env_keys_allowed'] ?? [],
            ],
            'invalidation_policy' => $config['invalidation_policy'] ?? [],
            'feature_flags' => $config['feature_flags'] ?? [],
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function isRedisRuntimeEnabled(FeatureFlagService $flags): bool
    {
        $store = strtolower((string) config('cache.default'));

        return $store === 'redis' || $flags->enabled('foundation.cache.redis_readiness');
    }

    /**
     * @param  array<string, mixed>  $redisConfig
     * @return array{decision: string, message: string, probe_key: string|null, wrote: bool, read_back: bool}
     */
    private function probeRedis(array $redisConfig, bool $redisRuntimeEnabled): array
    {
        $env = (string) config('app.env');
        $template = (string) ($redisConfig['probe_key_template'] ?? 'daengtisiams:{env}:foundation:cache_governance:probe');
        $probeKey = str_replace('{env}', $env, $template);
        $ttl = (int) ($redisConfig['probe_ttl_seconds'] ?? 30);
        $testValue = 'cache-governance-probe-'.now()->timestamp;

        try {
            Cache::put($probeKey, $testValue, $ttl);
            $readBack = Cache::get($probeKey);
            Cache::forget($probeKey);

            if ($readBack === $testValue) {
                return [
                    'decision' => 'GO',
                    'message' => 'Redis probe write/read/delete succeeded.',
                    'probe_key' => $probeKey,
                    'wrote' => true,
                    'read_back' => true,
                ];
            }

            $message = 'Redis probe write succeeded but read-back mismatch.';
            if ($redisRuntimeEnabled) {
                return ['decision' => 'FAIL', 'message' => $message, 'probe_key' => $probeKey, 'wrote' => true, 'read_back' => false];
            }

            return ['decision' => 'WATCH', 'message' => $message.' Redis runtime not enabled — readiness only.', 'probe_key' => $probeKey, 'wrote' => true, 'read_back' => false];
        } catch (Throwable $e) {
            $message = 'Redis probe failed: '.$e->getMessage();
            if ($redisRuntimeEnabled) {
                return ['decision' => 'FAIL', 'message' => $message, 'probe_key' => $probeKey, 'wrote' => false, 'read_back' => false];
            }

            return ['decision' => 'WATCH', 'message' => $message.' Redis runtime not enabled — readiness only.', 'probe_key' => $probeKey, 'wrote' => false, 'read_back' => false];
        }
    }

    /**
     * @return array{check_id: string, status: string, blocking: bool, message: string}
     */
    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    /**
     * @return array{check_id: string, status: string, blocking: bool, message: string}
     */
    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    /**
     * @return array{check_id: string, status: string, blocking: bool, message: string}
     */
    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
