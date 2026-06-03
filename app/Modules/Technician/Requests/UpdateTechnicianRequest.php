<?php

namespace App\Modules\Technician\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechnicianRequest extends FormRequest
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
        $technicianId = $this->route('technician')?->id;

        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('mst_technicians', 'code')->ignore($technicianId)],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'specialization' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
