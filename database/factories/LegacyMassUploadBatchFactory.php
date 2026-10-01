<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LegacyMassUploadBatch>
 */
class LegacyMassUploadBatchFactory extends Factory
{
    protected $model = LegacyMassUploadBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'import_type' => LegacyImportType::LEGACY_RME,
            'status' => LegacyMassUploadBatchStatus::UPLOADED,
            'created_by' => User::factory(),
            'confirmed_by' => null,
            'origin_branch_id' => null,
            'package_original_name' => 'arsip-legacy.zip',
            'package_disk' => 'legacy_mass_upload_private',
            'package_path' => 'legacy-mass-upload/'.$uuid.'/package.zip',
            // Deterministic placeholder digest. Tests that care about the real
            // digest set it explicitly; a random hex here would make an
            // assertion about "the same archive" impossible to write.
            'package_sha256' => str_repeat('a', 64),
            'package_bytes' => 1024,
            'manifest_sha256' => null,
            'total_items' => 0,
            'eligible_items' => 0,
            'warning_items' => 0,
            'blocked_items' => 0,
            'error_items' => 0,
            'dispatched_items' => 0,
            'failed_items' => 0,
        ];
    }

    public function odontogram(): self
    {
        return $this->state(fn (): array => [
            'import_type' => LegacyImportType::LEGACY_ODONTOGRAM,
        ]);
    }

    public function preflightReady(int $eligible = 1, int $blocked = 0): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadBatchStatus::PREFLIGHT_READY,
            'total_items' => $eligible + $blocked,
            'eligible_items' => $eligible,
            'blocked_items' => $blocked,
            'preflight_completed_at' => now(),
        ]);
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadBatchStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function dispatching(): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadBatchStatus::DISPATCHING,
            'confirmed_at' => now(),
            'dispatch_started_at' => now(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => LegacyMassUploadBatchStatus::COMPLETED,
            'confirmed_at' => now(),
            'dispatch_started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
