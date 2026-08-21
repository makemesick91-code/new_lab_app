<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * FIX-04b.
 *
 * @extends Factory<LegacyOdontogramImport>
 */
class LegacyOdontogramImportFactory extends Factory
{
    protected $model = LegacyOdontogramImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'patient_id' => Patient::factory(),
            'origin_branch_id' => Branch::factory(),
            'source_branch_code' => null,
            'source_medical_record_number' => null,
            // A historical date by default; tests that exercise the date rules
            // always pass an explicit selected_odontogram_date.
            'selected_odontogram_date' => now()->subYears(5)->toDateString(),
            'earliest_native_odontogram_date_snapshot' => null,
            'original_filename' => 'odontogram-lama.pdf',
            'source_disk' => null,
            'source_pdf_path' => null,
            'source_pdf_sha256' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'page_count' => null,
            'dpi' => null,
            'status' => LegacyOdontogramImportStatus::DRAFT,
        ];
    }
}
