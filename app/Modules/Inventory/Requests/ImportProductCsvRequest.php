<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductCsvRequest extends FormRequest
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
                'max:2048',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'csv_file.required' => 'File CSV wajib diunggah.',
            'csv_file.mimes' => 'File harus berformat CSV atau TXT.',
            'csv_file.max' => 'Ukuran file CSV maksimal 2 MB.',
        ];
    }
}
