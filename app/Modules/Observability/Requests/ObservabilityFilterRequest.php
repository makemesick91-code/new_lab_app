<?php

namespace App\Modules\Observability\Requests;

use App\Modules\Observability\Support\LatencyCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — every console filter, validated.
 *
 * Filters are bounded identifiers only: a route-name PREFIX restricted to
 * route-name characters, a 40-hex fingerprint, integer ids, dates. There is
 * no free-text search over stored messages — the console can never become a
 * way to grep for a patient.
 */
class ObservabilityFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already requires the permission; this is the second layer.
        return (bool) $this->user()?->can((string) config('developer_console.permission', 'view_developer_console'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'integer', Rule::in(array_map('intval', (array) config('observability_console.error_statuses', [])))],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'user' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'route' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'category' => ['nullable', Rule::in(LatencyCategory::ALL)],
            'min_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'fingerprint' => ['nullable', 'string', 'regex:/^[a-f0-9]{40}$/'],
        ];
    }

    /**
     * Validated filters with dates turned into clinical-day (WITA) bounds,
     * expressed in the application timezone the telemetry is stored in.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $data = $this->validated();
        $tz = (string) config('clinical.timezone', 'Asia/Makassar');
        $appTz = (string) config('app.timezone', 'UTC');

        if (! empty($data['from'])) {
            $data['from'] = Carbon::createFromFormat('!Y-m-d', $data['from'], $tz)->startOfDay()->setTimezone($appTz);
        }
        if (! empty($data['to'])) {
            $data['to'] = Carbon::createFromFormat('!Y-m-d', $data['to'], $tz)->endOfDay()->setTimezone($appTz);
        }

        return $data;
    }
}
