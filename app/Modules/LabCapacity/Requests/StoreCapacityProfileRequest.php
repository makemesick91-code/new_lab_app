<?php

namespace App\Modules\LabCapacity\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCapacityProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage_lab_technician_capacity');
    }

    public function rules(): array
    {
        $units = config('lab_technician_capacity.allowed_planning_units');

        return [
            'technician_id' => ['required', 'integer', Rule::exists('mst_technicians', 'id')->whereNull('deleted_at')],
            'planning_unit' => ['required', Rule::in($units)],
            'daily_capacity' => ['required', 'numeric', 'min:0', 'max:100000'],
            'working_days' => ['nullable', 'array'],
            'working_days.*' => ['integer', 'between:1,7'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
