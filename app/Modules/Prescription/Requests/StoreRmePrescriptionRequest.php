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
        // DMS-RME-REVISION-PROGRAM-8B / D6 — `prescribed_by_name` and
        // `patient_name_snapshot` are deliberately NOT accepted: the printed
        // dentist and patient identity is derived server-side from the visit
        // (RmePrescriptionService), so a submitted value has nowhere to land.
        return [
            'prescription_date' => ['required', 'date'],
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
