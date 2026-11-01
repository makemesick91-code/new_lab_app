<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the doctor's structured
 * record of ONE visit, as the read-only input the clinical vs billing cross-check
 * (rule 218) and the cashier projection consume. Pure: it holds rows it was
 * handed and writes nothing.
 *
 * Matching is by STABLE TREATMENT IDENTITY (`treatment_id`) only — never a
 * description, a name or a price (D21: the cashier still prices every line).
 * Whether a performed treatment needs to appear on the bill is decided by the SAME
 * per-line predicate as the Rp0 closure (RmeZeroValueEligibility::lineRefusalCode
 * at Rp0): an approved free service (Konsultasi, or the Kontrol service on a VALID
 * Kontrol) is legitimately absent from a positive bill; anything else performed and
 * absent from the bill is UNBILLED evidence — a detective signal, never a refusal
 * (CB-Q2 stays in force until a blocking contract is approved).
 */
final class RmePerformedTreatmentEvidence
{
    /**
     * @param  Collection<int, RmePerformedTreatment>  $activeRows  active rows, `treatment.category` loaded (soft-deleted master data included)
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $operationalCategory,
        public readonly ?int $declarationVersion,
        public readonly Collection $activeRows,
    ) {}

    public function performedCount(): int
    {
        return $this->activeRows->count();
    }

    /** @return list<int> */
    public function performedTreatmentIds(): array
    {
        $ids = $this->activeRows->pluck('treatment_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $ids;
    }

    /**
     * Treatment ids performed, NOT an approved free service on this visit, and
     * carried by no invoice line.
     *
     * @param  list<int>  $billedTreatmentIds
     * @return list<int>
     */
    public function unbilledTreatmentIds(array $billedTreatmentIds, bool $validControl): array
    {
        $unbilled = [];

        foreach ($this->activeRows as $row) {
            $treatmentId = (int) $row->treatment_id;

            if (in_array($treatmentId, $billedTreatmentIds, true)) {
                continue;
            }

            if (RmeZeroValueEligibility::lineRefusalCode($row->treatment, 0, 0, $validControl) === null) {
                continue;
            }

            $unbilled[$treatmentId] = $treatmentId;
        }

        ksort($unbilled);

        return array_values($unbilled);
    }

    /**
     * The PII-free snapshot recorded with a Rp0 closure (rule 218 v2).
     *
     * @param  list<int>  $billedTreatmentIds
     * @return array<string, mixed>
     */
    public function snapshot(array $billedTreatmentIds, bool $validControl): array
    {
        $unbilled = $this->unbilledTreatmentIds($billedTreatmentIds, $validControl);

        return [
            'state' => $this->state,
            'operational_visit_category' => $this->operationalCategory,
            'declaration_version' => $this->declarationVersion,
            'performed_count' => $this->performedCount(),
            'performed_treatment_ids' => $this->performedTreatmentIds(),
            'unbilled_performed_count' => count($unbilled),
            'unbilled_performed_treatment_ids' => $unbilled,
        ];
    }
}
