<?php

namespace App\Modules\LabService\Models;

use Database\Factories\LabServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabService extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_lab_services';

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'turnaround_days',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'turnaround_days' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): LabServiceFactory
    {
        return LabServiceFactory::new();
    }
}
