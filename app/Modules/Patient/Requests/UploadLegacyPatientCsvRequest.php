<?php

namespace App\Modules\Patient\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import upload request.
 * Authorization is enforced by the route `permission:manage patients` group.
 */
class UploadLegacyPatientCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'csv_file.required' => 'File CSV wajib diunggah.',
            'csv_file.mimes' => 'File harus berformat CSV atau TXT.',
            'csv_file.max' => 'Ukuran file CSV maksimal 5 MB.',
        ];
    }
}
