<?php

namespace App\Modules\LabOrder\Models;

use Database\Factories\ExternalLabFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * LAB-WORKFLOW-V2 — external lab (vendor) master data.
 */
class ExternalLab extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_external_labs';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(LabExternalDispatch::class, 'external_lab_id');
    }

    protected static function newFactory(): ExternalLabFactory
    {
        return ExternalLabFactory::new();
    }
}
