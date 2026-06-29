<?php

namespace App\Modules\Doctor\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDoctorRequest extends FormRequest
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
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
            'code' => ['required', 'string', 'max:50', 'unique:mst_doctors,code'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_ids.required' => 'Minimal satu Cabang Praktik wajib dipilih.',
            'branch_ids.min' => 'Minimal satu Cabang Praktik wajib dipilih.',
            'branch_ids.*.exists' => 'Setiap cabang harus cabang RME aktif.',
        ];
    }
}
