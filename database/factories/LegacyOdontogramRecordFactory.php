<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * FIX-04b — a PUBLISHED legacy odontogram record.
 *
 * The default state is PUBLISHED because that is the only state a record is
 * ever created in; VOID is reached through the service, never built directly,
 * so a fixture cannot fabricate a retraction that never had a reason.
 *
 * @extends Factory<LegacyOdontogramRecord>
 */
class LegacyOdontogramRecordFactory extends Factory
{
    protected $model = LegacyOdontogramRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'patient_id' => Patient::factory(),
            'branch_id' => Branch::factory(),
            'source_branch_code' => null,
            'source_medical_record_number' => null,
            'odontogram_date' => now()->subYears(5)->toDateString(),
            'title' => null,
            'description' => null,
            'source_disk' => 'legacy_odontogram_private',
            'source_pdf_path' => 'odontogram-legacy/imports/p0/fixture/source/source.pdf',
            'source_pdf_sha256' => str_repeat('a', 64),
            'page_count' => 0,
            'status' => LegacyOdontogramRecordStatus::PUBLISHED,
            'source_import_id' => null,
            'published_at' => now(),
        ];
    }
}
