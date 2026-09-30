<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyMassUploadItem>
 */
class LegacyMassUploadItemFactory extends Factory
{
    protected $model = LegacyMassUploadItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $row = 0;
        $row++;

        return [
            'mass_upload_batch_id' => LegacyMassUploadBatch::factory(),
            // Sequential rather than random: the staging table has a unique
            // index on (batch_id, row_number) and a random value would collide
            // as soon as a test made a handful of items.
            'row_number' => $row,
            'manifest_medical_record_number' => 'RM-'.str_pad((string) $row, 6, '0', STR_PAD_LEFT),
            'manifest_file_name' => 'dokumen-'.$row.'.pdf',
            'manifest_selected_date' => '2018-03-05',
            'manifest_latest_date' => null,
            'normalized_source_rm' => null,
            'resolved_patient_id' => null,
            'resolved_branch_id' => null,
            'document_entry_path' => null,
            'document_sha256' => null,
            'document_bytes' => null,
            'status' => LegacyMassUploadItemStatus::PENDING,
            'reason_code' => null,
            'reason_message' => null,
            'rme_legacy_import_id' => null,
            'odontogram_legacy_import_id' => null,
            'dispatched_at' => null,
        ];
    }

    public function eligible(): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadItemStatus::ELIGIBLE,
            'reason_code' => null,
            'reason_message' => null,
        ]);
    }

    public function blocked(string $reasonCode = LegacyMassUploadReason::ALREADY_PUBLISHED): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadItemStatus::BLOCKED,
            'reason_code' => $reasonCode,
            'reason_message' => LegacyMassUploadReason::message($reasonCode),
        ]);
    }

    public function dispatched(int $rmeImportId): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadItemStatus::DISPATCHED,
            'rme_legacy_import_id' => $rmeImportId,
            'dispatched_at' => now(),
        ]);
    }
}
