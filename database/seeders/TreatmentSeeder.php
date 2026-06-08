<?php

namespace Database\Seeders;

use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 — default treatments (global master data).
 * Idempotent: updateOrCreate keyed by code, never truncates or deletes.
 * Master-data only: requires_lab is a flag, no lab/billing/RME records are created.
 */
class TreatmentSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, category: string, requires_lab: bool}>
     */
    public const TREATMENTS = [
        ['code' => 'TRT-CONS', 'name' => 'Konsultasi Dokter', 'category' => 'consultation', 'requires_lab' => false],
        ['code' => 'TRT-SCAL', 'name' => 'Scaling', 'category' => 'preventive', 'requires_lab' => false],
        ['code' => 'TRT-FILL', 'name' => 'Tambal Gigi', 'category' => 'restoration', 'requires_lab' => false],
        ['code' => 'TRT-EXTR', 'name' => 'Cabut Gigi', 'category' => 'extraction', 'requires_lab' => false],
        ['code' => 'TRT-RCT', 'name' => 'Perawatan Saluran Akar', 'category' => 'endodontic', 'requires_lab' => false],
        ['code' => 'TRT-PDEN', 'name' => 'Gigi Palsu Sebagian', 'category' => 'prosthodontic', 'requires_lab' => true],
        ['code' => 'TRT-FDEN', 'name' => 'Gigi Palsu Full', 'category' => 'prosthodontic', 'requires_lab' => true],
        ['code' => 'TRT-CRWN', 'name' => 'Crown', 'category' => 'prosthodontic', 'requires_lab' => true],
        ['code' => 'TRT-BRDG', 'name' => 'Bridge', 'category' => 'prosthodontic', 'requires_lab' => true],
        ['code' => 'TRT-DENT', 'name' => 'Denture', 'category' => 'prosthodontic', 'requires_lab' => true],
        ['code' => 'TRT-RETN', 'name' => 'Retainer', 'category' => 'orthodontic', 'requires_lab' => true],
    ];

    public function run(): void
    {
        $categoryIds = TreatmentCategory::pluck('id', 'code');

        foreach (self::TREATMENTS as $treatment) {
            $categoryId = $categoryIds[$treatment['category']] ?? null;

            if ($categoryId === null) {
                continue;
            }

            Treatment::updateOrCreate(
                ['code' => $treatment['code']],
                [
                    'treatment_category_id' => $categoryId,
                    'name' => $treatment['name'],
                    'requires_doctor' => true,
                    'requires_room' => true,
                    'requires_lab' => $treatment['requires_lab'],
                    'is_active' => true,
                ],
            );
        }
    }
}
