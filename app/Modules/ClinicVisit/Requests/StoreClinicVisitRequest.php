<?php

namespace App\Modules\ClinicVisit\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', Rule::exists('mst_clinics', 'id')],
            'patient_id' => ['required', 'integer', Rule::exists('mst_patients', 'id')],
            'doctor_id' => ['required', 'integer', Rule::exists('mst_doctors', 'id')],
            'clinic_room_id' => ['nullable', 'integer', Rule::exists('mst_clinic_rooms', 'id')],
            'chief_complaint' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
