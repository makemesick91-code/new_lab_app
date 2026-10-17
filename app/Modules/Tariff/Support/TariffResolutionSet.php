<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

/**
 * The resolutions of one branch / one clinical date, keyed by treatment id.
 * A free-text line (no treatment) always reads NO_TREATMENT.
 */
final class TariffResolutionSet
{
    /**
     * @param  array<int, TariffResolution>  $byTreatment
     */
    public function __construct(private readonly array $byTreatment) {}

    public function for(?int $treatmentId): TariffResolution
    {
        if ($treatmentId === null || $treatmentId <= 0) {
            return TariffResolution::unresolved(TariffResolution::NO_TREATMENT, null);
        }

        return $this->byTreatment[$treatmentId]
            ?? TariffResolution::unresolved(TariffResolution::TREATMENT_INACTIVE, $treatmentId);
    }

    /** @return array<int, TariffResolution> */
    public function all(): array
    {
        return $this->byTreatment;
    }
}
