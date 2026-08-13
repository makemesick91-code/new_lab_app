<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Models\User;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Database\Factories\LegacyRmeMigrationWaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LEGACY-RME-PDF-ROLL-4 — the operational record of one migration wave.
 *
 * NOT AN AUTHORIZATION RECORD. This row mirrors ROLL-3's config approval so the
 * two can be compared; it never replaces it. Authority to migrate a branch
 * remains the config allowlist plus the owner's approval reference, both of
 * which are deploy-time state outside this application's write path.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property string|null $approval_reference
 * @property list<string>|null $approved_branch_codes
 * @property int|null $daily_quota
 * @property int|null $per_branch_daily_quota
 */
class LegacyRmeMigrationWave extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = LegacyRmeWaveStatus::DRAFT;

    public const STATUS_APPROVED = LegacyRmeWaveStatus::APPROVED;

    public const STATUS_ACTIVE = LegacyRmeWaveStatus::ACTIVE;

    public const STATUS_PAUSED = LegacyRmeWaveStatus::PAUSED;

    public const STATUS_DRAINING = LegacyRmeWaveStatus::DRAINING;

    public const STATUS_COMPLETED = LegacyRmeWaveStatus::COMPLETED;

    public const STATUS_CANCELLED = LegacyRmeWaveStatus::CANCELLED;

    protected $table = 'ops_rme_legacy_migration_waves';

    protected $fillable = [
        'code',
        'name',
        'status',
        'approval_reference',
        'approved_branch_codes',
        'planned_start_date',
        'planned_end_date',
        'daily_quota',
        'per_branch_daily_quota',
        'created_by',
        'approved_by',
        'approved_at',
        'activated_by',
        'activated_at',
        'paused_by',
        'paused_at',
        'pause_reason',
        'completed_by',
        'completed_at',
        'completion_note',
    ];

    /**
     * Module models must declare their factory explicitly: Laravel resolves
     * `Database\Factories\Modules\LegacyRme\Models\...Factory` from the
     * namespace, and every factory in this repository lives directly under
     * `Database\Factories`.
     */
    protected static function newFactory(): LegacyRmeMigrationWaveFactory
    {
        return LegacyRmeMigrationWaveFactory::new();
    }

    protected function casts(): array
    {
        return [
            'approved_branch_codes' => 'array',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'daily_quota' => 'integer',
            'per_branch_daily_quota' => 'integer',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LegacyRmeWaveBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(LegacyRmeWaveBranch::class, 'wave_id');
    }

    /**
     * @return HasMany<LegacyRmeWaveOperator, $this>
     */
    public function operators(): HasMany
    {
        return $this->hasMany(LegacyRmeWaveOperator::class, 'wave_id');
    }

    /**
     * @return HasMany<LegacyRmeMigrationQuota, $this>
     */
    public function quotas(): HasMany
    {
        return $this->hasMany(LegacyRmeMigrationQuota::class, 'wave_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return $this->status === LegacyRmeWaveStatus::ACTIVE;
    }

    public function isTerminal(): bool
    {
        return LegacyRmeWaveStatus::isTerminal($this->status);
    }

    /**
     * The approved branch set as canonical upper-case tokens.
     *
     * Re-normalized on read: the column is JSON, and a hand-edited or
     * legacy-shaped value must never be compared against config in a different
     * case from the one it was written in.
     *
     * @return list<string>
     */
    public function approvedBranchCodeTokens(): array
    {
        $codes = $this->approved_branch_codes;

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($code): string => strtoupper(trim((string) $code)), $codes),
            static fn (string $code): bool => $code !== '',
        )));
    }
}
