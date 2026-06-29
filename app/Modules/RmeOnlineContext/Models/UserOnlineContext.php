<?php

namespace App\Modules\RmeOnlineContext\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Database\Factories\UserOnlineContextFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOnlineContext extends Model
{
    use HasFactory;

    public const ROLE_DOCTOR = 'doctor';

    public const ROLE_ADMIN_CLINIC = 'admin_clinic';

    public const STATUS_ONLINE = 'online';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_OFFLINE = 'offline';

    protected $table = 'trx_user_online_contexts';

    protected $fillable = [
        'user_id',
        'branch_id',
        'clinic_room_id',
        'role_context',
        'status',
        'online_since',
        'last_seen_at',
        'offline_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'clinic_room_id' => 'integer',
            'online_since' => 'datetime',
            'last_seen_at' => 'datetime',
            'offline_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicRoom(): BelongsTo
    {
        return $this->belongsTo(ClinicRoom::class);
    }

    protected static function newFactory(): UserOnlineContextFactory
    {
        return UserOnlineContextFactory::new();
    }
}
