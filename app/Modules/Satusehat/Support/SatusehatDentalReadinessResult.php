<?php

namespace App\Modules\Satusehat\Support;

use App\Modules\Satusehat\Models\SatusehatCandidate;

/**
 * Outcome of a dental readiness evaluation. Reasons are structured, PII-free
 * (no name/NIK/raw note). `facts` is the deterministic dental fingerprint input
 * for SatusehatSourceHasher; `coverage` is the PII-free UI/audit summary.
 */
final class SatusehatDentalReadinessResult
{
    /**
     * @param  list<array{code:string, severity:string, message:string}>  $reasons
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public readonly string $status,
        public readonly array $reasons,
        public readonly array $facts,
        public readonly array $coverage,
    ) {}

    public function isReady(): bool
    {
        return $this->status === SatusehatCandidate::DENTAL_READY;
    }
}
