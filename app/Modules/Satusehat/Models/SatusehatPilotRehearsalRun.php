<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4C — internal pilot rehearsal run.
 *
 * Credential-independent, network-silent. Terminal result is honestly
 * PILOT_READY_INTERNAL or BLOCKED_EXTERNAL_CREDENTIAL — never submitted/sent.
 */
class SatusehatPilotRehearsalRun extends Model
{
    protected $table = 'trx_satusehat_pilot_rehearsal_runs';

    public const RESULT_PILOT_READY_INTERNAL = 'PILOT_READY_INTERNAL';

    public const RESULT_BLOCKED_EXTERNAL_CREDENTIAL = 'BLOCKED_EXTERNAL_CREDENTIAL';

    public const RESULT_FAILED = 'failed';

    protected $fillable = [
        'environment',
        'branch_id',
        'mode',
        'dry_run',
        'result',
        'final_stage',
        'stage_results',
        'run_by',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'dry_run' => 'boolean',
            'stage_results' => 'array',
            'run_by' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
