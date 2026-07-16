<?php

namespace App\Modules\Satusehat\Support;

use App\Modules\Satusehat\Models\SatusehatCandidate;

/**
 * Outcome of a readiness evaluation. Reasons are structured, machine + human
 * readable, and PII-free (no NIK). `facts` is the deterministic clinical
 * fingerprint input consumed by SatusehatSourceHasher.
 */
final class SatusehatReadinessResult
{
    /**
     * @param  list<array{code: string, severity: string, message: string}>  $reasons
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public readonly string $status,
        public readonly array $reasons,
        public readonly array $facts,
    ) {}

    public function isReady(): bool
    {
        return $this->status === SatusehatCandidate::READINESS_READY;
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
