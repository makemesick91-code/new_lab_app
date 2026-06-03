<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'doctor_id' => ['required', 'integer', 'exists:mst_doctors,id'],
            'patient_id' => ['required', 'integer', 'exists:mst_patients,id'],
            'order_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:order_date'],
            'priority' => ['required', Rule::in(LabOrder::PRIORITIES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.lab_service_id' => ['required', 'integer', 'exists:mst_lab_services,id'],
            'items.*.tooth_number' => ['nullable', 'string', 'max:20'],
            'items.*.shade_color_text' => ['nullable', 'string', 'max:100'],
            'items.*.material_text' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'numeric', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clinic_id.required' => 'Clinic wajib dipilih.',
            'doctor_id.required' => 'Doctor wajib dipilih.',
            'patient_id.required' => 'Patient wajib dipilih.',
            'due_date.required' => 'Due date wajib diisi.',
            'items.required' => 'Minimal satu item order wajib diisi.',
            'items.min' => 'Minimal satu item order wajib diisi.',
            'items.*.lab_service_id.required' => 'Lab service wajib dipilih pada setiap item.',
            'items.*.quantity.min' => 'Quantity harus lebih dari 0.',
        ];
    }
}
