<?php

declare(strict_types=1);

namespace App\Support\Devflow;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * DEVFLOW-1 — Sprint manifest value object.
 *
 * Loads a sprint manifest (YAML or JSON) into a typed, read-only accessor.
 * Never executes anything; never trusts a value beyond parsing. Validation of
 * the parsed data is the job of {@see SprintManifestValidator}.
 */
final class SprintManifest
{
    /** @param array<string,mixed> $data */
    private function __construct(
        public readonly array $data,
        public readonly ?string $path,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, ?string $path = null): self
    {
        return new self($data, $path);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Sprint manifest not found or unreadable: {$path}");
        }

        $raw = (string) file_get_contents($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $parsed = $ext === 'json' ? json_decode($raw, true) : self::parseYaml($raw);

        if (! is_array($parsed)) {
            throw new RuntimeException("Sprint manifest did not parse to a map: {$path}");
        }

        return new self($parsed, $path);
    }

    /**
     * Parse the manifest YAML. Prefers Symfony YAML when the dev dependency is
     * present (local/CI); falls back to a dependency-free parser for the
     * controlled flat manifest format so the tooling also works on production
     * hosts where symfony/yaml is not installed (composer --no-dev).
     *
     * @return array<string,mixed>
     */
    private static function parseYaml(string $raw): array
    {
        if (class_exists(Yaml::class)) {
            $parsed = Yaml::parse($raw);

            return is_array($parsed) ? $parsed : [];
        }

        return self::parseSimpleYaml($raw);
    }

    /**
     * Minimal parser for the DEVFLOW manifest shape only:
     *   key: scalar        (string | bool | int)
     *   key: []            (empty list)
     *   key:               (block list)
     *     - item
     * Comments (`#`) and blank lines are ignored. Quoted scalars are unwrapped.
     *
     * @return array<string,mixed>
     */
    private static function parseSimpleYaml(string $raw): array
    {
        $result = [];
        $currentListKey = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            // List item under the most recent "key:" block.
            if (preg_match('/^\s+-\s+(.*)$/', $line, $m) === 1 && $currentListKey !== null) {
                $result[$currentListKey][] = self::castScalar(self::stripComment($m[1]));

                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $key = $m[1];
            $value = self::stripComment($m[2]);

            if ($value === '') {
                $result[$key] = [];
                $currentListKey = $key;

                continue;
            }

            $currentListKey = null;
            $result[$key] = $value === '[]' ? [] : self::castScalar($value);
        }

        return $result;
    }

    private static function stripComment(string $value): string
    {
        // Strip an inline comment only when the value is not quoted.
        $trimmed = trim($value);
        if ($trimmed !== '' && ($trimmed[0] === '"' || $trimmed[0] === "'")) {
            return $trimmed;
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $trimmed));
    }

    private static function castScalar(string $value): mixed
    {
        $value = trim($value);

        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function id(): ?string
    {
        return $this->stringOrNull('id');
    }

    public function type(): ?string
    {
        return $this->stringOrNull('type');
    }

    public function module(): ?string
    {
        return $this->stringOrNull('module');
    }

    public function baseBranch(): ?string
    {
        return $this->stringOrNull('base_branch');
    }

    public function goTag(): ?string
    {
        return $this->stringOrNull('go_tag');
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->data[$key] ?? false);
    }

    /** @return list<string> */
    public function testProfiles(): array
    {
        $value = $this->data['test_profiles'] ?? [];

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * Resolve the profile definition for this manifest's type from
     * config/sprint_profiles.php, or null when the type is unknown.
     *
     * @return array<string,mixed>|null
     */
    public function profile(): ?array
    {
        $type = $this->type();

        if ($type === null) {
            return null;
        }

        return config("sprint_profiles.types.{$type}");
    }

    /**
     * Whether a deploy is required for THIS manifest: the type demands it AND
     * (for conditional types) the guarding flag is set.
     */
    public function deployRequired(): bool
    {
        $profile = $this->profile();

        if ($profile === null) {
            return (bool) ($this->data['deploy_required'] ?? false);
        }

        if (($profile['deploy_required'] ?? false) !== true) {
            return false;
        }

        $conditional = $profile['deploy_conditional_on'] ?? null;

        if (is_string($conditional) && $conditional !== '') {
            return $this->flag($conditional) && (bool) ($this->data['deploy_required'] ?? true);
        }

        return (bool) ($this->data['deploy_required'] ?? true);
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
