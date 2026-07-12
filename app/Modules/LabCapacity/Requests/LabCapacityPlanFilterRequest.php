<?php

namespace App\Modules\LabCapacity\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAB-PROD-3 — Capacity planning filter input.
 *
 * Validates candidate filters only; authorization SCOPE (branch/technician
 * forcing, tier) is enforced server-side in the planning service — request
 * fields here are just candidates.
 */
class LabCapacityPlanFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cfg = config('lab_technician_capacity');
        $horizons = array_map('strval', $cfg['horizons']);
        $horizons[] = 'custom';

        return [
            'horizon' => ['nullable', Rule::in($horizons)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'technician_id' => ['nullable', 'integer', 'min:1'],
            'lab_service_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'sourcing' => ['nullable', Rule::in(['internal', 'external'])],
            'planning_unit' => ['nullable', Rule::in($cfg['allowed_planning_units'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('horizon') === 'custom' && $this->filled('from') && $this->filled('to')) {
                $from = strtotime((string) $this->input('from'));
                $to = strtotime((string) $this->input('to'));
                $max = (int) config('lab_technician_capacity.max_horizon_days', 90);
                if ($from !== false && $to !== false && ($to - $from) / 86400 > $max) {
                    $validator->errors()->add('to', "Rentang kustom maksimum {$max} hari.");
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'horizon' => $this->input('horizon'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            'technician_id' => $this->integer('technician_id') ?: null,
            'lab_service_id' => $this->integer('lab_service_id') ?: null,
            'status' => $this->input('status') ?: null,
            'sourcing' => $this->input('sourcing') ?: null,
            'planning_unit' => $this->input('planning_unit') ?: null,
        ];
    }
}
