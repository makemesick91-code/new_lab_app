<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — hermetic incident-drill run log.
 *
 * Records a no-network operational drill outcome. Never touches production or
 * sends external traffic. Scalar / PII-free.
 */
class SatusehatIncidentDrillRun extends Model
{
    protected $table = 'trx_satusehat_incident_drill_runs';

    public const UPDATED_AT = null;

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PASS = 'pass';

    public const OUTCOME_FAIL = 'fail';

    protected $fillable = [
        'environment',
        'drill_code',
        'title',
        'trigger',
        'expected_state',
        'actual_state',
        'outcome',
        'diagnostic_command',
        'escalation_owner',
        'rollback',
        'evidence_reference',
        'executed_by',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'executed_by' => 'integer',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
