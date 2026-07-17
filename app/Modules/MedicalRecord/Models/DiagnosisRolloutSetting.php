<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4B — explicit, branch-scoped structured diagnosis rollout mode.
 * Branches without a row fall back to the safe config default. There is no
 * global enforcement row by design.
 */
class DiagnosisRolloutSetting extends Model
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_INFORMATIONAL = 'informational';

    public const MODE_WARNING = 'warning';

    public const MODE_PILOT_ENFORCED = 'pilot_enforced';

    public const MODES = [
        self::MODE_DISABLED,
        self::MODE_INFORMATIONAL,
        self::MODE_WARNING,
        self::MODE_PILOT_ENFORCED,
    ];

    /** Modes that may act as the implicit default for unconfigured branches. */
    public const NON_BLOCKING_MODES = [
        self::MODE_DISABLED,
        self::MODE_INFORMATIONAL,
        self::MODE_WARNING,
    ];

    protected $table = 'mst_diagnosis_rollout_settings';

    protected $fillable = [
        'branch_id',
        'mode',
        'reason',
        'configured_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }
}
