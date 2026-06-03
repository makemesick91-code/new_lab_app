<?php

namespace App\Modules\Doctor\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorRequest extends FormRequest
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
        $doctorId = $this->route('doctor')?->id;

        return [
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('mst_doctors', 'code')->ignore($doctorId)],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
