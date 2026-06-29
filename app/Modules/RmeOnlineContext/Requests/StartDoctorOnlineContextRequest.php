<?php

namespace App\Modules\RmeOnlineContext\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartDoctorOnlineContextRequest extends FormRequest
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
            'clinic_room_id' => ['required', 'integer', Rule::exists('mst_clinic_rooms', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Pilih cabang RME untuk mulai online.',
            'branch_id.exists' => 'Cabang yang dipilih harus cabang RME aktif.',
            'clinic_room_id.required' => 'Pilih ruangan perawatan untuk mulai online.',
            'clinic_room_id.exists' => 'Ruangan yang dipilih tidak valid.',
        ];
    }
}
