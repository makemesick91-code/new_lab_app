<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * LEGACY-RME-PDF-1A.
 *
 * @extends Factory<LegacyRmeRecord>
 */
class LegacyRmeRecordFactory extends Factory
{
    protected $model = LegacyRmeRecord::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'patient_id' => Patient::factory(),
            'origin_branch_id' => Branch::factory(),
            'rme_date' => now()->subYears(5)->toDateString(),
            'title' => 'Arsip RME lama',
            'description' => null,
            'source_disk' => 'local',
            'source_pdf_path' => 'rme-legacy/example/source.pdf',
            'source_pdf_sha256' => hash('sha256', (string) Str::uuid()),
            'normalized_content_hash' => null,
            'page_count' => 1,
            'status' => LegacyRmeRecord::STATUS_PUBLISHED,
            'source_import_id' => null,
            'published_at' => now(),
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => LegacyRmeRecord::STATUS_VOID,
            'voided_at' => now(),
            'void_reason' => 'Salah pasien, diimpor ulang.',
        ]);
    }
}
