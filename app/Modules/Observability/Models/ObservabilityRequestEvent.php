<?php

namespace App\Modules\Observability\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — one recorded HTTP request.
 *
 * Telemetry, not a clinical record: written once in terminate(), never
 * updated, deleted only by the bounded retention prune.
 */
class ObservabilityRequestEvent extends Model
{
    protected $table = 'sys_obs_request_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_error' => 'boolean',
        'is_slow' => 'boolean',
        'is_timeout' => 'boolean',
        'trace_summary' => 'array',
        'duration_ms' => 'integer',
        'db_time_ms' => 'integer',
        'query_count' => 'integer',
        'cache_hits' => 'integer',
        'cache_misses' => 'integer',
        'status_code' => 'integer',
        'response_status' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
