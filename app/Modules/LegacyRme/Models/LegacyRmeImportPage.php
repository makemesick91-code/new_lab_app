<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use Database\Factories\LegacyRmeImportPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LEGACY-RME-PDF-1A — one staged page of a legacy RME document.
 *
 * Rendering columns stay nullable: page rasterization is produced by the
 * follow-up sprint, never in 1A.
 */
class LegacyRmeImportPage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = LegacyRmeImportPageStatus::PENDING;

    public const STATUS_PROCESSING = LegacyRmeImportPageStatus::PROCESSING;

    public const STATUS_READY = LegacyRmeImportPageStatus::READY;

    public const STATUS_FAILED = LegacyRmeImportPageStatus::FAILED;

    public const STATUS_PUBLISHED = LegacyRmeImportPageStatus::PUBLISHED;

    public const STATUS_CANCELLED = LegacyRmeImportPageStatus::CANCELLED;

    /** @var list<string> */
    public const STATUSES = LegacyRmeImportPageStatus::ALL;

    protected $table = 'stg_rme_legacy_import_pages';

    protected $fillable = [
        'legacy_import_id',
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
        'status',
    ];

    protected static function newFactory(): LegacyRmeImportPageFactory
    {
        return LegacyRmeImportPageFactory::new();
    }

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
        return $this->belongsTo(LegacyRmeImport::class, 'legacy_import_id');
    }

    public function isPublishable(): bool
    {
        return LegacyRmeImportPageStatus::isPublishable($this->status);
    }
}
