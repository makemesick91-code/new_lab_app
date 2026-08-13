<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use Database\Factories\LegacyRmeWaveBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LEGACY-RME-PDF-ROLL-4 — one branch enrolled in one migration wave.
 *
 * The canonical Branch model is App\Modules\Branch\Models\Branch (never
 * App\Models\Branch — the two are a known trap in this repository).
 *
 * @property int $id
 * @property int $wave_id
 * @property int $branch_id
 * @property string $branch_code
 * @property string $status
 * @property int|null $daily_quota
 * @property int|null $planned_document_count
 */
class LegacyRmeWaveBranch extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = LegacyRmeWaveBranchStatus::PLANNED;

    public const STATUS_ACTIVE = LegacyRmeWaveBranchStatus::ACTIVE;

    public const STATUS_PAUSED = LegacyRmeWaveBranchStatus::PAUSED;

    public const STATUS_DRAINING = LegacyRmeWaveBranchStatus::DRAINING;

    public const STATUS_COMPLETED = LegacyRmeWaveBranchStatus::COMPLETED;

    public const STATUS_CANCELLED = LegacyRmeWaveBranchStatus::CANCELLED;

    protected $table = 'ops_rme_legacy_wave_branches';

    protected $fillable = [
        'wave_id',
        'branch_id',
        'branch_code',
        'status',
        'daily_quota',
        'planned_document_count',
        'completed_by',
        'completed_at',
        'completion_note',
        'reconciliation_snapshot',
        'status_reason',
    ];

    protected static function newFactory(): LegacyRmeWaveBranchFactory
    {
        return LegacyRmeWaveBranchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'daily_quota' => 'integer',
            'planned_document_count' => 'integer',
            'reconciliation_snapshot' => 'array',
            'completed_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isActive(): bool
    {
        return $this->status === LegacyRmeWaveBranchStatus::ACTIVE;
    }

    public function isTerminal(): bool
    {
        return LegacyRmeWaveBranchStatus::isTerminal($this->status);
    }

    /**
     * The daily ceiling that applies to this branch, or NULL for "no ceiling
     * declared".
     *
     * Resolution order — branch override, then wave default, then the config
     * default — so the most specific declaration wins. NULL is preserved all
     * the way through rather than collapsing to 0, because 0 is a ceiling that
     * admits nothing and NULL is the absence of one.
     */
    public function effectiveDailyQuota(?LegacyRmeMigrationWave $wave = null): ?int
    {
        if ($this->daily_quota !== null) {
            return (int) $this->daily_quota;
        }

        $wave ??= $this->wave;

        if ($wave?->per_branch_daily_quota !== null) {
            return (int) $wave->per_branch_daily_quota;
        }

        $configured = config('legacy_rme_operations.quota.default_branch_daily');

        return $configured === null ? null : (int) $configured;
    }
}
