<?php

namespace App\Modules\Production\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignTechnicianRequest extends FormRequest
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
            'technician_id' => ['required', 'integer', 'exists:mst_technicians,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
