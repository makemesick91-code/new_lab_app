<?php

namespace App\Modules\RmeOnlineContext\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartPerawatOnlineContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Pilih cabang RME untuk mulai bertugas.',
            'branch_id.exists' => 'Cabang yang dipilih harus cabang RME aktif.',
        ];
    }
}
