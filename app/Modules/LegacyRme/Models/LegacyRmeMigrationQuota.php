<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Modules\Branch\Models\Branch;
use Database\Factories\LegacyRmeMigrationQuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * LEGACY-RME-PDF-ROLL-4 — one day's quota bucket for one branch in one wave.
 *
 * The row exists so it can be LOCKED. Counting staging rows would be derivable
 * but unlockable: two concurrent uploads both read the same count and both
 * insert, because the row that should have blocked the second one is the one
 * neither has written yet. A counter row is written first, so `FOR UPDATE`
 * serialises the decision.
 *
 * Incremented inside the same transaction as the staging row it counts, so a
 * rolled-back import releases its quota automatically.
 *
 * @property int $id
 * @property int $wave_id
 * @property int $branch_id
 * @property Carbon $quota_date
 * @property int $consumed
 */
class LegacyRmeMigrationQuota extends Model
{
    use HasFactory;

    protected $table = 'ops_rme_legacy_migration_quotas';

    protected $fillable = [
        'wave_id',
        'branch_id',
        'quota_date',
        'consumed',
    ];

    protected static function newFactory(): LegacyRmeMigrationQuotaFactory
    {
        return LegacyRmeMigrationQuotaFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quota_date' => 'date',
            'consumed' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LegacyRmeMigrationWave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeMigrationWave::class, 'wave_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
