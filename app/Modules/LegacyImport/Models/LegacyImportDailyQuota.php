<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Models;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — one (import type, branch, clinical day) bucket.
 *
 * The row is a lock target first and a counter second. Nothing outside
 * LegacyImportDailyQuotaService may increment it: an increment taken without the row lock that service acquires
 * is exactly the race the table exists to prevent.
 *
 * @property int $id
 * @property string $import_type
 * @property int $branch_id
 * @property string $quota_date
 * @property int $consumed
 */
class LegacyImportDailyQuota extends Model
{
    protected $table = 'ops_legacy_import_daily_quotas';

    protected $fillable = [
        'import_type',
        'branch_id',
        'quota_date',
        'consumed',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'consumed' => 'integer',
        'quota_date' => 'date',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
