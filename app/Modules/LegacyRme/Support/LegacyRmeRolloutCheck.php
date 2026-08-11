<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-ROLL-2 — one readiness finding.
 *
 * Immutable by construction so a check cannot be "upgraded" to GO after the
 * fact. The context array is intended for operator-facing evidence and must
 * therefore stay free of clinical content: no patient identifier, no document
 * path, no file bytes. Checks report configuration and infrastructure facts.
 */
final class LegacyRmeRolloutCheck
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $context = [],
        public readonly ?string $remediation = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function go(string $id, string $summary, array $context = []): self
    {
        return new self($id, 'GO', $summary, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function watch(string $id, string $summary, array $context = [], ?string $remediation = null): self
    {
        return new self($id, 'WATCH', $summary, $context, $remediation);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fail(string $id, string $summary, array $context = [], ?string $remediation = null): self
    {
        return new self($id, 'FAIL', $summary, $context, $remediation);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function unknown(string $id, string $summary, array $context = [], ?string $remediation = null): self
    {
        return new self($id, 'UNKNOWN', $summary, $context, $remediation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'summary' => $this->summary,
            'context' => $this->context,
            'remediation' => $this->remediation,
        ];
    }
}
