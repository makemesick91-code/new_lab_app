<?php

namespace App\Modules\QualityControl\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadQcEvidenceRequest extends FormRequest
{
    /** QC evidence categories (sprint_5_technical_design.md §12). */
    public const CATEGORIES = ['QC_PHOTO', 'QC_NOTE', 'QC_EVIDENCE', 'QC_REJECTION_EVIDENCE'];

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
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,stl'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Kategori evidence wajib dipilih.',
            'file.required' => 'File evidence wajib diunggah.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
            'file.extensions' => 'Tipe file tidak didukung. Gunakan jpg, jpeg, png, pdf, atau stl.',
        ];
    }
}
