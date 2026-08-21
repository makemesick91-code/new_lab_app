<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Models;

use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportPageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIX-04b — one rasterized page of a staged legacy odontogram chart.
 *
 * Staging only: it is discarded with its batch and is never the evidence a
 * clinician reads. Publishing copies the metadata onto an immutable
 * LegacyOdontogramRecordPage.
 */
class LegacyOdontogramImportPage extends Model
{
    protected $table = 'stg_odontogram_legacy_import_pages';

    protected $fillable = [
        'legacy_import_id',
        'page_number',
        'width',
        'height',
        'dpi',
        'rotation',
        'image_disk',
        'image_path',
        'image_sha256',
        'thumbnail_path',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_import_id' => 'integer',
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'dpi' => 'integer',
            'rotation' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramImport::class, 'legacy_import_id');
    }

    public function isPublishable(): bool
    {
        return LegacyOdontogramImportPageStatus::isPublishable($this->status);
    }
}
