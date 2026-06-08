<?php

namespace Database\Seeders;

use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 — default treatment categories (global master data).
 * Idempotent: updateOrCreate keyed by code, never truncates or deletes.
 */
class TreatmentCategorySeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string}>
     */
    public const CATEGORIES = [
        ['code' => 'consultation', 'name' => 'Konsultasi'],
        ['code' => 'preventive', 'name' => 'Preventif'],
        ['code' => 'restoration', 'name' => 'Restorasi'],
        ['code' => 'extraction', 'name' => 'Pencabutan'],
        ['code' => 'endodontic', 'name' => 'Endodontik'],
        ['code' => 'orthodontic', 'name' => 'Ortodontik'],
        ['code' => 'prosthodontic', 'name' => 'Prostodontik'],
        ['code' => 'surgery', 'name' => 'Bedah'],
        ['code' => 'lab', 'name' => 'Laboratorium'],
        ['code' => 'other', 'name' => 'Lainnya'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $category) {
            TreatmentCategory::updateOrCreate(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'sort_order' => $index,
                    'is_active' => true,
                ],
            );
        }
    }
}
