<?php

namespace App\Modules\Clinic\Models;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clinic extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_clinics';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'province',
        'postal_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class, 'clinic_id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'clinic_id');
    }

    protected static function newFactory(): ClinicFactory
    {
        return ClinicFactory::new();
    }
}
