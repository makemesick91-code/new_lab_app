<?php

namespace App\Modules\ClinicRoom\Models;

use App\Modules\Branch\Models\Branch;
use Database\Factories\ClinicRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicRoom extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TREATMENT_ROOM = 'treatment_room';

    public const TYPE_CONSULTATION_ROOM = 'consultation_room';

    public const TYPE_XRAY_ROOM = 'xray_room';

    public const TYPE_STERILIZATION_ROOM = 'sterilization_room';

    public const TYPE_LAB_ROOM = 'lab_room';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_TREATMENT_ROOM,
        self::TYPE_CONSULTATION_ROOM,
        self::TYPE_XRAY_ROOM,
        self::TYPE_STERILIZATION_ROOM,
        self::TYPE_LAB_ROOM,
        self::TYPE_OTHER,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_MAINTENANCE,
    ];

    protected $table = 'mst_clinic_rooms';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'type',
        'status',
        'description',
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

    protected static function newFactory(): ClinicRoomFactory
    {
        return ClinicRoomFactory::new();
    }
}
