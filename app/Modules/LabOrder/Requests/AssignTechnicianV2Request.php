<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** LAB-WORKFLOW-V2 — technician assignment on the internal path. */
class AssignTechnicianV2Request extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + service guards
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'technician_id' => ['required', 'integer', 'exists:mst_technicians,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
