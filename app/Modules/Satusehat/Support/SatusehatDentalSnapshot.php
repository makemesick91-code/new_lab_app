<?php

namespace App\Modules\Satusehat\Support;

/**
 * Immutable, PII-free structured snapshot of a visit's dental (odontogram) data.
 *
 * `facts` is the deterministic fingerprint input consumed by SatusehatSourceHasher
 * (free-text is included only as a sha256, never raw). `coverage` is the PII-free
 * summary persisted to trx_satusehat_candidates.dental_coverage_snapshot and
 * rendered in the review UI. Neither contains a name, NIK, or raw clinical note.
 */
final class SatusehatDentalSnapshot
{
    /**
     * @param  list<array{number:string, status:string, mapped:bool, mapping_confidence:?string}>  $teeth
     * @param  array{D:int, M:int, F:int}  $dmft
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public readonly bool $odontogramPresent,
        public readonly array $teeth,
        public readonly array $dmft,
        public readonly bool $hasOtherConditions,
        public readonly array $facts,
        public readonly array $coverage,
    ) {}

    public function toothCount(): int
    {
        return count($this->teeth);
    }
}
