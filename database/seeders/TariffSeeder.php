<?php

namespace Database\Seeders;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 Phase 3 — default treatment tariffs for the active/default (MAIN) branch.
 * Idempotent: updateOrCreate keyed by (branch_id, treatment_id, effective_date).
 * Pricing master data only — creates no billing/invoice/payment/RME records.
 */
class TariffSeeder extends Seeder
{
    /**
     * Default prices keyed by treatment code (see TreatmentSeeder).
     *
     * @var array<string, int>
     */
    public const PRICES = [
        'TRT-CONS' => 50000,
        'TRT-SCAL' => 250000,
        'TRT-FILL' => 300000,
        'TRT-EXTR' => 250000,
        'TRT-RCT' => 750000,
        'TRT-PDEN' => 1500000,
        'TRT-FDEN' => 3000000,
        'TRT-CRWN' => 2500000,
        'TRT-BRDG' => 3500000,
        'TRT-DENT' => 1500000,
        'TRT-RETN' => 1000000,
    ];

    public const EFFECTIVE_DATE = '2026-01-01';

    public function run(): void
    {
        $branchId = app(BranchContext::class)->requireId();

        $treatmentIds = Treatment::whereIn('code', array_keys(self::PRICES))->pluck('id', 'code');

        foreach (self::PRICES as $code => $price) {
            $treatmentId = $treatmentIds[$code] ?? null;

            if ($treatmentId === null) {
                continue;
            }

            Tariff::updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'treatment_id' => $treatmentId,
                    'effective_date' => self::EFFECTIVE_DATE,
                ],
                [
                    'price' => $price,
                    'is_active' => true,
                ],
            );
        }
    }
}
