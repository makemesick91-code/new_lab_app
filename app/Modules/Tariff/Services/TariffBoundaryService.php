<?php

namespace App\Modules\Tariff\Services;

use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use Illuminate\Support\Carbon;

/**
 * Branch-specific tariff resolution — no cross-branch fallback.
 */
class TariffBoundaryService
{
    public function __construct(
        private readonly TariffRepositoryInterface $tariffs,
    ) {}

    public function resolveActiveTariff(int $branchId, int $treatmentId, ?Carbon $onDate = null): ?Tariff
    {
        return $this->tariffs->findActiveForTreatment($branchId, $treatmentId, $onDate);
    }

    public function resolveActivePrice(int $branchId, int $treatmentId, ?Carbon $onDate = null): ?float
    {
        $tariff = $this->resolveActiveTariff($branchId, $treatmentId, $onDate);

        return $tariff === null ? null : (float) $tariff->price;
    }
}
