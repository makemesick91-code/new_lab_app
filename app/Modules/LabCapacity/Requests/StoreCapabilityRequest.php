<?php

namespace App\Modules\LabCapacity\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage_lab_technician_capacity');
    }

    public function rules(): array
    {
        return [
            'technician_id' => ['required', 'integer', Rule::exists('mst_technicians', 'id')->whereNull('deleted_at')],
            'lab_service_id' => ['required', 'integer', Rule::exists('mst_lab_services', 'id')],
            'is_eligible' => ['nullable', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
