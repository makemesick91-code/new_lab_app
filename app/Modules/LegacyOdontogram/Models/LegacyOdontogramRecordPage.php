<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIX-04b — a published page of a legacy odontogram record.
 *
 * Clinical evidence: immutable and never hard-deleted (the parent FK is
 * RESTRICT and the table has no soft delete). `image_path` addresses a file on
 * a PRIVATE disk and is only ever resolved inside the storage service — it is
 * never rendered and never handed to a client.
 */
class LegacyOdontogramRecordPage extends Model
{
    protected $table = 'trx_odontogram_legacy_record_pages';

    protected $fillable = [
        'odontogram_legacy_record_id',
        'page_number',
        'width',
        'height',
        'dpi',
        'rotation',
        'image_disk',
        'image_path',
        'image_sha256',
        'thumbnail_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'odontogram_legacy_record_id' => 'integer',
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'dpi' => 'integer',
            'rotation' => 'integer',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramRecord::class, 'odontogram_legacy_record_id');
    }
}
