<?php

namespace App\Modules\Technician\Models;

use App\Models\User;
use Database\Factories\TechnicianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technician extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_technicians';

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'phone',
        'email',
        'specialization',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): TechnicianFactory
    {
        return TechnicianFactory::new();
    }
}
