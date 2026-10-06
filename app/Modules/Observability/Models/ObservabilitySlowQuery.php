<?php

namespace App\Modules\Observability\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — one slow database query.
 *
 * Stores normalized SQL only; bindings are never persisted.
 */
class ObservabilitySlowQuery extends Model
{
    protected $table = 'sys_obs_slow_queries';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'duration_ms' => 'integer',
    ];
}
