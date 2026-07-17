<?php

namespace App\Modules\Satusehat\Models;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — one UAT scenario result within a run.
 *
 * PII-safe: operator_name/role are operator identity for accountability, never
 * patient data; steps/results describe synthetic scenarios only.
 */
class SatusehatUatScenario extends Model
{
    protected $table = 'trx_satusehat_uat_scenarios';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PASS = 'pass';

    public const OUTCOME_FAIL = 'fail';

    public const OUTCOME_BLOCKED = 'blocked';

    protected $fillable = [
        'uat_run_id',
        'scenario_code',
        'role',
        'branch_id',
        'precondition',
        'steps',
        'expected_result',
        'actual_result',
        'outcome',
        'finding_severity',
        'evidence_reference',
        'operator_name',
        'operator_role',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'uat_run_id' => 'integer',
            'branch_id' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SatusehatUatRun::class, 'uat_run_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
