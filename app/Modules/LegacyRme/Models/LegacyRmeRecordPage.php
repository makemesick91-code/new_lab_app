<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use Database\Factories\LegacyRmeRecordPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LEGACY-RME-PDF-1A — a published page of a legacy RME record.
 *
 * Clinical evidence: immutable and never hard-deleted (the parent FK is
 * RESTRICT and the table has no soft delete).
 */
class LegacyRmeRecordPage extends Model
{
    use HasFactory;

    protected $table = 'trx_rme_legacy_record_pages';

    protected $fillable = [
        'rme_legacy_record_id',
        'page_number',
        'width',
        'height',
        'dpi',
        'rotation',
        'background_disk',
        'background_path',
        'background_sha256',
        'thumbnail_path',
        'normalized_page_hash',
    ];

    protected static function newFactory(): LegacyRmeRecordPageFactory
    {
        return LegacyRmeRecordPageFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rme_legacy_record_id' => 'integer',
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'dpi' => 'integer',
            'rotation' => 'integer',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeRecord::class, 'rme_legacy_record_id');
    }
}
