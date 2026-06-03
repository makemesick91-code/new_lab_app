<?php

namespace App\Modules\QualityControl\Models;

use App\Models\User;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use Database\Factories\QualityControlFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QualityControl extends Model
{
    use HasFactory, SoftDeletes;

    public const ENTITY_TYPE = 'trx_lab_quality_controls';

    public const RESULT_PASSED = 'PASSED';

    public const RESULT_REJECTED = 'REJECTED';

    public const RESULT_REVISION = 'REVISION';

    public const RESULTS = ['PASSED', 'REJECTED', 'REVISION'];

    protected $table = 'trx_lab_quality_controls';

    protected $fillable = [
        'lab_order_id',
        'inspected_by',
        'result',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(QualityControlChecklist::class, 'quality_control_id');
    }

    public function remakeRequests(): HasMany
    {
        return $this->hasMany(RemakeRequest::class, 'quality_control_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'entity');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'entity');
    }

    protected static function newFactory(): QualityControlFactory
    {
        return QualityControlFactory::new();
    }
}
