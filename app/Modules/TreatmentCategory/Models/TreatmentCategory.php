<?php

namespace App\Modules\TreatmentCategory\Models;

use App\Modules\Treatment\Models\Treatment;
use Database\Factories\TreatmentCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TreatmentCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_treatment_categories';

    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class);
    }

    protected static function newFactory(): TreatmentCategoryFactory
    {
        return TreatmentCategoryFactory::new();
    }
}
