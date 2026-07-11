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

        $parsed = $ext === 'json'
            ? json_decode($raw, true)
            : Yaml::parse($raw);

        if (! is_array($parsed)) {
            throw new RuntimeException("Sprint manifest did not parse to a map: {$path}");
        }

        return new self($parsed, $path);
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
