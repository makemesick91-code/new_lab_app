<?php

namespace App\Modules\QualityControl\Models;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RemakeRequest extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = ['OPEN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];

    public const REASONS = [
        'FIT_ISSUE',
        'MARGIN_ISSUE',
        'OCCLUSION_ISSUE',
        'SHADE_MISMATCH',
        'CONTACT_POINT_ISSUE',
        'SURFACE_FINISH_ISSUE',
        'POLISHING_ISSUE',
        'PACKAGING_ISSUE',
        'OTHER',
    ];

    protected $table = 'trx_lab_remake_requests';

    protected $fillable = [
        'lab_order_id',
        'quality_control_id',
        'requested_by',
        'reason',
        'notes',
        'status',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function qualityControl(): BelongsTo
    {
        return $this->belongsTo(QualityControl::class, 'quality_control_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
