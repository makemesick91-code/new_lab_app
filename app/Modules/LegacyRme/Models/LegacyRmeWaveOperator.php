<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\LegacyRmeWaveOperatorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * LEGACY-RME-PDF-ROLL-4 — an operator cleared to migrate one branch in one wave.
 *
 * An assignment NARROWS; it never grants. It cannot supply a permission the
 * user lacks, cannot admit a branch ROLL-3 refused, and cannot change the
 * RM-derived branch a document lands in.
 *
 * @property int $id
 * @property int $wave_id
 * @property int $user_id
 * @property int $branch_id
 * @property string $branch_code
 * @property Carbon|null $revoked_at
 */
class LegacyRmeWaveOperator extends Model
{
    use HasFactory;

    protected $table = 'ops_rme_legacy_wave_operators';

    protected $fillable = [
        'wave_id',
        'user_id',
        'branch_id',
        'branch_code',
        'assigned_by',
        'assigned_at',
        'revoked_by',
        'revoked_at',
    ];

    protected static function newFactory(): LegacyRmeWaveOperatorFactory
    {
        return LegacyRmeWaveOperatorFactory::new();
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Assignments that are in force right now.
     *
     * Revocation is soft, so "assigned" always means "row exists AND has not
     * been revoked". Every authorization read goes through this scope so the
     * `revoked_at IS NULL` half can never be forgotten at one call site.
     *
     * @param  Builder<LegacyRmeWaveOperator>  $query
     * @return Builder<LegacyRmeWaveOperator>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<LegacyRmeMigrationWave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeMigrationWave::class, 'wave_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
