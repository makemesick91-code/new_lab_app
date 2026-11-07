<?php

namespace App\Modules\Prescription\Requests;

use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
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
            // DMS-RME-REVISION-PROGRAM-8C (P8R-S5, rule 228 §10) — bounded at the
            // edge; the decoder applies its own limit for callers that skip this.
            'prescription_canvas_data' => ['required', 'string', 'max:'.PrescriptionCanvasDecoder::MAX_PAYLOAD_LENGTH],
            // Rule 228 §12 — optional here: only the prescription's own doctor may
            // send it, and the service decides that (it is an identity, not a
            // shape, so it cannot be decided in a request rule).
            'doctor_signature_canvas_data' => ['nullable', 'string', 'max:'.PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prescription_canvas_data.max' => 'Ukuran gambar resep melebihi batas yang diizinkan.',
            'doctor_signature_canvas_data.max' => 'Ukuran gambar tanda tangan melebihi batas yang diizinkan.',
        ];
    }
}
