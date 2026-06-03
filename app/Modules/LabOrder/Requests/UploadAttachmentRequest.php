<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadAttachmentRequest extends FormRequest
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
            'category' => ['required', Rule::in(Attachment::CATEGORIES)],
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,stl'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Kategori attachment wajib dipilih.',
            'file.required' => 'File wajib diunggah.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
            'file.extensions' => 'Tipe file tidak didukung. Gunakan jpg, jpeg, png, pdf, atau stl.',
        ];
    }
}
