<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-WORKFLOW-V2 — append-only model analysis decision.
 */
class LabModelAnalysis extends Model
{
    use HasFactory;

    public const DECISION_INTERNAL = 'INTERNAL';

    public const DECISION_EXTERNAL = 'EXTERNAL';

    public const DECISIONS = [
        self::DECISION_INTERNAL,
        self::DECISION_EXTERNAL,
    ];

    protected $table = 'trx_lab_model_analyses';

    protected $fillable = [
        'lab_order_id',
        'decision',
        'reason',
        'notes',
        'external_lab_id',
        'analyzed_by',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'analyzed_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function externalLab(): BelongsTo
    {
        return $this->belongsTo(ExternalLab::class, 'external_lab_id');
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }
}
