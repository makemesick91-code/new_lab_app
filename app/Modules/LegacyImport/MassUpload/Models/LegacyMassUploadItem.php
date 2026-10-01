<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\Patient\Models\Patient;
use Database\Factories\LegacyMassUploadItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manifest line — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
 *
 * The two `*_legacy_import_id` columns are the idempotency marker that makes a
 * batch resumable (§26). Non-null means "this row already produced a canonical
 * import", so a resumed or double-confirmed dispatch skips it instead of
 * creating a second lifecycle for the same patient. That check is cheap and it
 * is the difference between a safe resume and a duplicate clinical record.
 *
 * @property int $id
 * @property int $mass_upload_batch_id
 * @property int $row_number
 * @property string $manifest_medical_record_number
 * @property string $manifest_file_name
 * @property string|null $manifest_selected_date
 * @property string|null $manifest_latest_date
 * @property string|null $normalized_source_rm
 * @property int|null $resolved_patient_id
 * @property int|null $resolved_branch_id
 * @property string|null $document_entry_path
 * @property string|null $document_sha256
 * @property int|null $document_bytes
 * @property string $status
 * @property string|null $reason_code
 * @property string|null $reason_message
 * @property int|null $rme_legacy_import_id
 * @property int|null $odontogram_legacy_import_id
 */
class LegacyMassUploadItem extends Model
{
    use HasFactory;

    protected $table = 'stg_legacy_mass_upload_items';

    protected $fillable = [
        'mass_upload_batch_id',
        'row_number',
        'manifest_medical_record_number',
        'manifest_file_name',
        'manifest_selected_date',
        'manifest_latest_date',
        'normalized_source_rm',
        'resolved_patient_id',
        'resolved_branch_id',
        'document_entry_path',
        'document_sha256',
        'document_bytes',
        'status',
        'reason_code',
        'reason_message',
        'rme_legacy_import_id',
        'odontogram_legacy_import_id',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'resolved_patient_id' => 'integer',
            'resolved_branch_id' => 'integer',
            'document_bytes' => 'integer',
            'rme_legacy_import_id' => 'integer',
            'odontogram_legacy_import_id' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return LegacyMassUploadItemFactory::new();
    }

    /** @return BelongsTo<LegacyMassUploadBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyMassUploadBatch::class, 'mass_upload_batch_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'resolved_patient_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'resolved_branch_id');
    }

    /** @return BelongsTo<LegacyRmeImport, $this> */
    public function rmeImport(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeImport::class, 'rme_legacy_import_id');
    }

    /** @return BelongsTo<LegacyOdontogramImport, $this> */
    public function odontogramImport(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramImport::class, 'odontogram_legacy_import_id');
    }

    /**
     * Has this row already produced a canonical import?
     *
     * Deliberately checks BOTH columns regardless of the batch type. A row is
     * "already created" if anything canonical exists for it; asking only the
     * type-specific column would mean a mislabelled batch could create a second
     * lifecycle, and the cost of the extra comparison is nothing.
     */
    public function hasCreatedLifecycle(): bool
    {
        return $this->rme_legacy_import_id !== null
            || $this->odontogram_legacy_import_id !== null;
    }

    public function createdImportId(): ?int
    {
        return $this->rme_legacy_import_id ?? $this->odontogram_legacy_import_id;
    }

    public function isDispatchable(): bool
    {
        return LegacyMassUploadItemStatus::isDispatchable((string) $this->status);
    }

    public function isBlocked(): bool
    {
        return $this->status === LegacyMassUploadItemStatus::BLOCKED;
    }

    public function statusLabel(): string
    {
        return LegacyMassUploadItemStatus::label((string) $this->status);
    }

    /**
     * Operator-safe explanation.
     *
     * Prefers the message the domain itself produced, because the canonical
     * services write their refusals for an operator to read and those are more
     * specific than anything a generic code lookup can say. Falls back to the
     * code's canned message. Never exposes an exception or a path.
     */
    public function reasonText(): ?string
    {
        if (is_string($this->reason_message) && trim($this->reason_message) !== '') {
            return $this->reason_message;
        }

        if (is_string($this->reason_code) && $this->reason_code !== '') {
            return LegacyMassUploadReason::message($this->reason_code);
        }

        return null;
    }
}
