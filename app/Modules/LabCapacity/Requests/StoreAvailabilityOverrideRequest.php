<?php

namespace App\Modules\LabCapacity\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAvailabilityOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage_lab_technician_capacity');
    }

    public function rules(): array
    {
        $categories = config('lab_technician_capacity.availability_reason_categories');

        return [
            'technician_id' => ['required', 'integer', Rule::exists('mst_technicians', 'id')->whereNull('deleted_at')],
            'override_date' => ['required', 'date'],
            'capacity_override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'capacity_reduction' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reason_category' => ['required', Rule::in($categories)],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('capacity_override') === null && $this->input('capacity_reduction') === null) {
                $validator->errors()->add('capacity_reduction', 'Isi salah satu: kapasitas absolut atau pengurangan kapasitas.');
            }
        });
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
