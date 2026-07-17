<?php

namespace App\Modules\MedicalRecord\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClinicalDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware gates the route
    }

    public function rules(): array
    {
        return [
            'code_system' => ['nullable', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:40'],
            'display' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'source_version' => ['nullable', 'string', 'max:100'],
            'aliases' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
