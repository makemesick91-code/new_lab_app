<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** LAB-WORKFLOW-V2 — external lab master data creation. */
class StoreExternalLabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware

    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:mst_external_labs,name'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
