<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * LEGACY-RME-PDF-1A.
 *
 * @extends Factory<LegacyRmeImport>
 */
class LegacyRmeImportFactory extends Factory
{
    protected $model = LegacyRmeImport::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'patient_id' => Patient::factory(),
            'origin_branch_id' => Branch::factory(),
            // A historical date by default; tests that exercise the date rules
            // always pass an explicit selected_rme_date.
            'selected_rme_date' => now()->subYears(5)->toDateString(),
            'earliest_native_rme_date_snapshot' => null,
            'original_filename' => 'rm-lama.pdf',
            'source_disk' => null,
            'source_pdf_path' => null,
            'source_pdf_sha256' => null,
            'normalized_content_hash' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'page_count' => null,
            'dpi' => null,
            'status' => LegacyRmeImport::STATUS_DRAFT,
        ];
    }

    public function readyForReview(): static
    {
        return $this->state(fn () => [
            'status' => LegacyRmeImport::STATUS_READY_FOR_REVIEW,
            'source_disk' => 'local',
            'source_pdf_path' => 'rme-legacy/example/source.pdf',
            'source_pdf_sha256' => hash('sha256', 'legacy-rme-fixture'),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'page_count' => 2,
            'dpi' => 200,
            'uploaded_at' => now()->subMinutes(10),
        ]);
    }

    public function reviewed(): static
    {
        return $this->readyForReview()->state(fn () => [
            'status' => LegacyRmeImport::STATUS_REVIEWED,
            'reviewed_at' => now()->subMinutes(5),
        ]);
    }

    public function published(): static
    {
        return $this->reviewed()->state(fn () => [
            'status' => LegacyRmeImport::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => LegacyRmeImport::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }
}
