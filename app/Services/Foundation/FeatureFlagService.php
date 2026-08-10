<?php

namespace App\Services\Foundation;

use InvalidArgumentException;

/**
 * NSF-9 — Read-only feature flag registry.
 *
 * Flags are config-driven (config/feature_flags.php) with a safe env override
 * per flag. This service never mutates state and never enables a flag by
 * itself — callers must explicitly branch on enabled() to change behavior.
 */
class FeatureFlagService
{
    /** Metadata every flag definition MUST provide. */
    private const REQUIRED_METADATA = [
        'name',
        'description',
        'default',
        'env_key',
        'owner',
        'risk_level',
        'rollout_status',
        'dependencies',
        'rollback_action',
    ];

    /** Risk levels that are considered "risky future infra" and MUST default false. */
    private const RISKY_LEVELS = ['high', 'critical'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return collect(config('feature_flags.flags', []))
            ->map(fn (array $definition, string $key) => $this->hydrate($key, $definition))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $this->assertKnown($key);

        return $this->hydrate($key, $this->definitions()[$key]);
    }

    public function enabled(string $key): bool
    {
        $flag = $this->get($key);

        return (bool) $flag['enabled'];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(string $key): array
    {
        return $this->get($key);
    }

    public function assertKnown(string $key): void
    {
        if (! isset($this->definitions()[$key]) || ! is_array($this->definitions()[$key])) {
            throw new InvalidArgumentException("Unknown feature flag: {$key}");
        }
    }

    /**
     * Flag keys themselves contain dots (e.g. "foundation.cache.redis_readiness"),
     * which breaks Laravel's dot-notation config path traversal for a nested
     * lookup like config("feature_flags.flags.{$key}"). Read the raw
     * "feature_flags.flags" array once and index into it directly instead.
     *
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return config('feature_flags.flags', []);
    }

    /**
     * Flags whose current effective value is enabled AND whose risk level is
     * high/critical — these are the ones release safety must scrutinize.
     *
     * @return list<string>
     */
    public function riskyEnabledFlags(): array
    {
        return collect($this->all())
            ->filter(fn (array $flag) => $flag['enabled'] && in_array($flag['risk_level'], self::RISKY_LEVELS, true))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Validate the whole registry for governance safety.
     *
     * @return array<string, mixed>
     */
    public function validateGovernance(): array
    {
        $flags = $this->all();
        $checks = [];

        $missingMetadata = [];
        foreach ($flags as $key => $flag) {
            $gaps = array_filter(
                self::REQUIRED_METADATA,
                fn (string $field) => ! array_key_exists($field, $flag) || $flag[$field] === null || $flag[$field] === ''
            );
            if ($gaps !== []) {
                $missingMetadata[] = sprintf('%s(%s)', $key, implode(',', $gaps));
            }
        }
        $checks[] = $missingMetadata === []
            ? $this->pass('FLAG-METADATA-COMPLETE', 'All feature flags define required metadata.')
            : $this->warn('FLAG-METADATA-COMPLETE', 'Flags missing required metadata: '.implode('; ', $missingMetadata));

        $unsafeDefaults = collect($flags)
            ->filter(fn (array $flag) => in_array($flag['risk_level'], self::RISKY_LEVELS, true) && $flag['default'] === true)
            ->keys()
            ->all();
        $checks[] = $unsafeDefaults === []
            ? $this->pass('FLAG-RISKY-DEFAULT-OFF', 'Risky (high/critical) flags all default false.')
            : $this->fail('FLAG-RISKY-DEFAULT-OFF', 'Unsafe risky flag default detected: '.implode(', ', $unsafeDefaults));

        $riskyEnabled = $this->riskyEnabledFlags();
        $checks[] = $riskyEnabled === []
            ? $this->pass('FLAG-RISKY-NOT-ENABLED', 'No risky future infra flag is currently enabled.')
            : $this->warn('FLAG-RISKY-NOT-ENABLED', 'Risky flags currently enabled via env override: '.implode(', ', $riskyEnabled));

        // LEGACY-RME-PDF-ROLL-1 — a declared env_key that was never captured at
        // config-BUILD time is an INERT override: it works while config is
        // uncached and silently stops working the moment a deployment runs
        // config:cache. That is a broken promise, so it fails the registry.
        $uncaptured = collect($flags)
            ->filter(fn (array $flag) => $flag['env_key'] !== '' && $flag['env_captured'] === false)
            ->keys()
            ->all();
        $checks[] = $uncaptured === []
            ? $this->pass('FLAG-ENV-CAPTURE', 'Every flag declaring an env_key captures its override at config-build time (config:cache safe).')
            : $this->fail('FLAG-ENV-CAPTURE', 'Flags declare an env_key with no config-build-time env_value capture, so the override is ignored under config:cache: '.implode(', ', $uncaptured));

        $invalidOverrides = collect($flags)
            ->filter(fn (array $flag) => $flag['env_resolution'] === 'invalid_fallback_default')
            ->keys()
            ->all();
        $checks[] = $invalidOverrides === []
            ? $this->pass('FLAG-ENV-VALUE-VALID', 'All configured feature flag overrides parse as booleans.')
            : $this->fail('FLAG-ENV-VALUE-VALID', 'Feature flag overrides are not parseable booleans and fell back to the declared default: '.implode(', ', $invalidOverrides));

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'total_flags' => count($flags),
            'risky_enabled_flags' => $riskyEnabled,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function hydrate(string $key, array $definition): array
    {
        $default = (bool) ($definition['default'] ?? false);
        $envKey = (string) ($definition['env_key'] ?? '');

        $resolution = $this->resolveOverride($definition, $envKey, $default);

        return [
            'key' => $key,
            'name' => (string) ($definition['name'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'default' => $default,
            'env_key' => $envKey,
            'owner' => (string) ($definition['owner'] ?? ''),
            'risk_level' => (string) ($definition['risk_level'] ?? 'unknown'),
            'rollout_status' => (string) ($definition['rollout_status'] ?? 'unknown'),
            'expires_at' => $definition['expires_at'] ?? null,
            'review_target' => $definition['review_target'] ?? null,
            'dependencies' => array_values((array) ($definition['dependencies'] ?? [])),
            'rollback_action' => (string) ($definition['rollback_action'] ?? ''),
            // LEGACY-RME-PDF-ROLL-1 runtime-override evidence. `env_value` is a
            // normalized bool/null/'invalid' — never the raw environment string,
            // so a misconfigured value can never be echoed into evidence JSON.
            'env_captured' => $resolution['captured'],
            'env_value' => $resolution['value'],
            'env_resolution' => $resolution['source'],
            'enabled' => $resolution['enabled'],
        ];
    }

    /**
     * LEGACY-RME-PDF-ROLL-1 — the one place a flag's effective value is decided.
     *
     * Resolution order:
     *  1. `env_value` captured in the config file at config-BUILD time. This is
     *     the only form that survives `config:cache`: once config is cached
     *     Laravel skips loading the environment file altogether, so a runtime
     *     env() call returns null and a pure runtime override is silently lost.
     *  2. Runtime env($envKey), which still works on uncached (local/test)
     *     environments and keeps the historical behaviour for definitions that
     *     were built by hand without a capture.
     *  3. The declared default.
     *
     * Fail-closed rules:
     *  - An unset OR blank override is "not configured" and yields the declared
     *     default. Blank is deliberately not read as false: for a default-true
     *     safety flag, falling to the default keeps the safety gate ON.
     *  - An unparseable override yields the declared default too, and is
     *     reported as `invalid` so governance can FAIL on it. It is never read
     *     as the enabled interpretation, so a typo can never switch a risky
     *     default-off capability on.
     *
     * @param  array<string, mixed>  $definition
     * @return array{captured: bool, value: bool|string|null, source: string, enabled: bool}
     */
    private function resolveOverride(array $definition, string $envKey, bool $default): array
    {
        $captured = array_key_exists('env_value', $definition);

        $raw = $definition['env_value'] ?? null;
        if ($raw === null && $envKey !== '') {
            $raw = env($envKey);
        }

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return ['captured' => $captured, 'value' => null, 'source' => 'default', 'enabled' => $default];
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            return [
                'captured' => $captured,
                'value' => 'invalid',
                'source' => 'invalid_fallback_default',
                'enabled' => $default,
            ];
        }

        return ['captured' => $captured, 'value' => $parsed, 'source' => 'env', 'enabled' => $parsed];
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'message' => $message];
    }
}
