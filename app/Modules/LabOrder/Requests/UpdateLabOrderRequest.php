<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabOrderRequest extends FormRequest
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
            'medical_record_number' => ['nullable', 'string', 'max:100'],
            'order_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:order_date'],
            'priority' => ['required', Rule::in(LabOrder::PRIORITIES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:trx_lab_order_items,id'],
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
            'items.required' => 'Minimal satu item order wajib aktif.',
            'items.min' => 'Minimal satu item order wajib aktif.',
        ];
    }
}
