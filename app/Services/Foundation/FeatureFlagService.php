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

        // Env override resolution order:
        //  1. `env_value` captured in the config file at config-BUILD time —
        //     the only form that survives `config:cache` (runtime env() reads
        //     nothing once config is cached, so cached deployments would
        //     silently ignore a pure runtime env override).
        //  2. Runtime env($envKey) for uncached (local/test) environments.
        //  3. The declared default.
        $override = $definition['env_value'] ?? null;
        if ($override === null && $envKey !== '') {
            $override = env($envKey);
        }

        $enabled = $override !== null
            ? filter_var($override, FILTER_VALIDATE_BOOLEAN)
            : $default;

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
            'enabled' => $enabled,
        ];
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
