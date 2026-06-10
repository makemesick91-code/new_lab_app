<?php

namespace App\Modules\ClinicVisit\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clinic_room_id' => ['sometimes', 'nullable', 'integer', Rule::exists('mst_clinic_rooms', 'id')],
            'chief_complaint' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'doctor_id' => ['sometimes', 'integer', Rule::exists('mst_doctors', 'id')],
            'initial_treatment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('mst_treatments', 'id')->where('is_active', true)],
            'initial_service_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
