<?php

namespace Database\Seeders;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 Phase 3 — default treatment tariffs for the branch BranchContext
 * resolves when the seeder runs.
 * Idempotent: updateOrCreate keyed by (branch_id, treatment_id, effective_date).
 * Pricing master data only — creates no billing/invoice/payment/RME records.
 *
 * DEVELOPMENT DEFAULTS, NOT AN OFFICIAL TARIFF LIST (FEATURE-RME-BRANCH-TARIFF-
 * PROVISIONING-1, rule 193). These prices were never approved by the owner
 * (D21h). Production TLK1's 11 tariffs are exactly these values, created in one
 * second on 2026-06-10 with no audit row; they carry no approval provenance and
 * read UNVERIFIED in rme:tariff-authority-readiness. Never run this seeder to
 * "provision" a branch, and never copy these values to another branch.
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
