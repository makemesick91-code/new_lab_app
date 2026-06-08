<?php

namespace App\Modules\Treatment\Models;

use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Treatment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_treatments';

    protected $fillable = [
        'treatment_category_id',
        'code',
        'name',
        'description',
        'default_duration_minutes',
        'requires_doctor',
        'requires_room',
        'requires_lab',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'treatment_category_id' => 'integer',
            'default_duration_minutes' => 'integer',
            'requires_doctor' => 'boolean',
            'requires_room' => 'boolean',
            'requires_lab' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TreatmentCategory::class, 'treatment_category_id');
    }

    protected static function newFactory(): TreatmentFactory
    {
        return TreatmentFactory::new();
    }
}
