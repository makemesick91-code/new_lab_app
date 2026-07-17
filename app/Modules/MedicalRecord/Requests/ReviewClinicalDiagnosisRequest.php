<?php

namespace App\Modules\MedicalRecord\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** SATUSEHAT-4B — approve/reject a terminology entry (reasoned). */
class ReviewClinicalDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // review_clinical_terminology middleware gates the route
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
