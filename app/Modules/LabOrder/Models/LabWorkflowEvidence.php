<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\LabWorkflowEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * LAB-WORKFLOW-V2 — typed workflow evidence metadata.
 *
 * Explicit document types (never inferred from filename). Binary lives on the
 * PRIVATE local disk; this row stores metadata + sha256 checksum. Evidence is
 * audit material — the application never hard-deletes it.
 */
class LabWorkflowEvidence extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_SPK_PHOTO = 'SPK_PHOTO';

    public const TYPE_MODEL_PHOTO_BRANCH = 'MODEL_PHOTO_BRANCH';

    public const TYPE_PICKUP_PHOTO = 'PICKUP_PHOTO';

    public const TYPE_PRE_DELIVERY_HANDOVER_PHOTO = 'PRE_DELIVERY_HANDOVER_PHOTO';

    public const TYPE_COURIER_SIGNATURE = 'COURIER_SIGNATURE';

    public const TYPE_RECIPIENT_SIGNATURE = 'RECIPIENT_SIGNATURE';

    public const TYPE_DELIVERY_LOCATION_PHOTO = 'DELIVERY_LOCATION_PHOTO';

    public const TYPES = [
        self::TYPE_SPK_PHOTO,
        self::TYPE_MODEL_PHOTO_BRANCH,
        self::TYPE_PICKUP_PHOTO,
        self::TYPE_PRE_DELIVERY_HANDOVER_PHOTO,
        self::TYPE_COURIER_SIGNATURE,
        self::TYPE_RECIPIENT_SIGNATURE,
        self::TYPE_DELIVERY_LOCATION_PHOTO,
    ];

    protected $table = 'trx_lab_workflow_evidence';

    protected $fillable = [
        'lab_order_id',
        'branch_id',
        'type',
        'file_path',
        'mime_type',
        'file_size',
        'checksum',
        'uploaded_by',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function newFactory(): LabWorkflowEvidenceFactory
    {
        return LabWorkflowEvidenceFactory::new();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_SPK_PHOTO => 'Foto SPK',
            self::TYPE_MODEL_PHOTO_BRANCH => 'Foto Model (Cabang)',
            self::TYPE_PICKUP_PHOTO => 'Foto Pickup',
            self::TYPE_PRE_DELIVERY_HANDOVER_PHOTO => 'Foto Serah Terima Lab → Kurir',
            self::TYPE_COURIER_SIGNATURE => 'Tanda Tangan Kurir',
            self::TYPE_RECIPIENT_SIGNATURE => 'Tanda Tangan Penerima',
            self::TYPE_DELIVERY_LOCATION_PHOTO => 'Foto Bukti Serah Terima',
            default => $this->type,
        };
    }
}
