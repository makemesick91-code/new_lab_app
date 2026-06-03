<?php

namespace App\Modules\Doctor\Models;

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Patient\Models\Patient;
use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_doctors';

    protected $fillable = [
        'clinic_id',
        'code',
        'name',
        'phone',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'doctor_id');
    }

    protected static function newFactory(): DoctorFactory
    {
        return DoctorFactory::new();
    }
}
