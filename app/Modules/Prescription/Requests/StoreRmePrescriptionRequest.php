<?php

namespace App\Modules\Prescription\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRmePrescriptionRequest extends FormRequest
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
            'prescribed_by_name' => ['required', 'string', 'max:255'],
            'prescription_date' => ['required', 'date'],
            'patient_name_snapshot' => ['required', 'string', 'max:255'],
            'patient_age_snapshot' => ['nullable', 'string', 'max:50'],
            'allergy_note' => ['nullable', 'string', 'max:2000'],
            'pregnant_or_breastfeeding' => ['nullable', 'string', 'max:255'],
            'renal_function_issue' => ['nullable', 'string', 'max:255'],
            'prescription_canvas_data' => ['required', 'string'],
            'doctor_signature_canvas_data' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
