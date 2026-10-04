<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Models;

use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishItemStatus;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE publish attempt against ONE document — PR2 §4.
 *
 * §4 forbids silently skipping a refused item, so every selected document gets
 * its own row carrying what the server decided, a stable reason code, and the
 * archive record the attempt produced OR observed.
 *
 * `created_record` is the honest distinction between "we published it" and "it
 * was already filed". The canonical publish returns an existing record with
 * created:false, and a report that counted those as fresh publications would
 * overstate what the run did.
 *
 * @property int $id
 * @property int $batch_publish_run_id
 * @property string $import_type
 * @property int|null $rme_legacy_import_id
 * @property int|null $odontogram_legacy_import_id
 * @property int|null $patient_id
 * @property string|null $source_sha256
 * @property string $status
 * @property string|null $reason_code
 * @property string|null $reason_message
 * @property int|null $rme_legacy_record_id
 * @property int|null $odontogram_legacy_record_id
 * @property bool $created_record
 */
class LegacyBatchPublishItem extends Model
{
    protected $table = 'stg_legacy_batch_publish_items';

    protected $fillable = [
        'batch_publish_run_id',
        'import_type',
        'rme_legacy_import_id',
        'odontogram_legacy_import_id',
        'patient_id',
        'source_sha256',
        'status',
        'reason_code',
        'reason_message',
        'rme_legacy_record_id',
        'odontogram_legacy_record_id',
        'created_record',
        'attempted_at',
        'published_at',
    ];

    protected $casts = [
        'batch_publish_run_id' => 'integer',
        'rme_legacy_import_id' => 'integer',
        'odontogram_legacy_import_id' => 'integer',
        'patient_id' => 'integer',
        'rme_legacy_record_id' => 'integer',
        'odontogram_legacy_record_id' => 'integer',
        'created_record' => 'boolean',
        'attempted_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(LegacyBatchPublishRun::class, 'batch_publish_run_id');
    }

    public function rmeImport(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeImport::class, 'rme_legacy_import_id');
    }

    public function odontogramImport(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramImport::class, 'odontogram_legacy_import_id');
    }

    public function rmeRecord(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeRecord::class, 'rme_legacy_record_id');
    }

    public function odontogramRecord(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramRecord::class, 'odontogram_legacy_record_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * The canonical import id this attempt is about, whichever column holds it.
     *
     * Single accessor so no caller re-derives the discriminator — that is how a
     * type-confusion bug would get in.
     */
    public function importId(): ?int
    {
        $id = $this->import_type === LegacyImportType::LEGACY_RME
            ? $this->rme_legacy_import_id
            : $this->odontogram_legacy_import_id;

        return $id !== null ? (int) $id : null;
    }

    public function recordId(): ?int
    {
        $id = $this->import_type === LegacyImportType::LEGACY_RME
            ? $this->rme_legacy_record_id
            : $this->odontogram_legacy_record_id;

        return $id !== null ? (int) $id : null;
    }

    public function isPublished(): bool
    {
        return $this->status === LegacyBatchPublishItemStatus::PUBLISHED;
    }

    public function isRefused(): bool
    {
        return $this->status === LegacyBatchPublishItemStatus::REFUSED;
    }

    public function statusLabel(): string
    {
        return LegacyBatchPublishItemStatus::label($this->status);
    }

    public function reasonLabel(): string
    {
        return LegacyBatchPublishReason::label($this->reason_code);
    }
}
