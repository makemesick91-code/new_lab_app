<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;

/**
 * Reads a visit's odontogram (trx_odontograms.tooth_map_payload) into an
 * immutable, deterministic, PII-free SatusehatDentalSnapshot. Side-effect-free
 * and network-silent. The tooth statuses are looked up against the ACTIVE
 * tooth-condition mappings so readiness can report which teeth are mapped.
 *
 * tooth_map_payload shape (see resources/js/app.js odontogramEditor.getPayload):
 *   { "teeth": { "<FDI>": { "status": "caries", "note": "...", ... }, ... } }
 */
class SatusehatDentalSnapshotBuilder
{
    public function __construct(
        private readonly SatusehatMappingRepositoryInterface $mappings,
    ) {}

    public function build(ClinicVisit $visit, string $environment): SatusehatDentalSnapshot
    {
        $odontogram = $this->resolveOdontogram($visit);

        if ($odontogram === null) {
            return new SatusehatDentalSnapshot(
                odontogramPresent: false,
                teeth: [],
                dmft: ['D' => 0, 'M' => 0, 'F' => 0],
                hasOtherConditions: false,
                facts: ['odontogram_present' => false],
                coverage: ['odontogram_present' => false, 'teeth' => [], 'dmft' => ['D' => 0, 'M' => 0, 'F' => 0]],
            );
        }

        $rawTeeth = $odontogram->tooth_map_payload['teeth'] ?? [];
        $rawTeeth = is_array($rawTeeth) ? $rawTeeth : [];

        $teeth = [];
        foreach ($rawTeeth as $number => $tooth) {
            $status = is_array($tooth) ? ($tooth['status'] ?? null) : null;
            if (! is_string($status) || $status === '') {
                continue;
            }
            $mapping = $this->toothConditionMapping($environment, $status);
            $teeth[] = [
                'number' => (string) $number,
                'status' => $status,
                'mapped' => $mapping !== null,
                'mapping_confidence' => $mapping?->mapping_confidence,
            ];
        }

        // Deterministic ordering by FDI number (numeric-aware).
        usort($teeth, fn (array $a, array $b) => (int) $a['number'] <=> (int) $b['number']);

        $dmft = $odontogram->dmftCounts();
        $dmft = ['D' => (int) $dmft['D'], 'M' => (int) $dmft['M'], 'F' => (int) $dmft['F']];

        $otherText = trim((string) ($odontogram->summary_notes ?? '').'|'.(string) ($odontogram->additional_conditions ?? ''));
        $hasOther = $otherText !== '|' && $otherText !== '';

        // Facts: clinically-meaningful only; free-text folded to a hash so drift
        // is detected without ever storing the raw note in the fingerprint.
        $facts = [
            'odontogram_present' => true,
            'odontogram_status' => (string) $odontogram->status,
            'teeth' => array_map(fn (array $t) => ['number' => $t['number'], 'status' => $t['status']], $teeth),
            'dmft' => $dmft,
            'other_conditions_hash' => $hasOther ? hash('sha256', $otherText) : null,
        ];

        // Coverage: PII-free UI/audit summary (no raw notes).
        $coverage = [
            'odontogram_present' => true,
            'tooth_count' => count($teeth),
            'teeth' => $teeth,
            'dmft' => $dmft,
            'has_other_conditions' => $hasOther,
        ];

        return new SatusehatDentalSnapshot(
            odontogramPresent: true,
            teeth: $teeth,
            dmft: $dmft,
            hasOtherConditions: $hasOther,
            facts: $facts,
            coverage: $coverage,
        );
    }

    private function resolveOdontogram(ClinicVisit $visit): ?Odontogram
    {
        return Odontogram::query()
            ->where('clinic_visit_id', $visit->id)
            ->orderByDesc('id')
            ->first();
    }

    private function toothConditionMapping(string $environment, string $status): ?SatusehatCodeMapping
    {
        return $this->mappings->findActive(
            $environment,
            'odontogram_tooth_condition',
            null,
            $status,
            'Observation',
        );
    }
}
