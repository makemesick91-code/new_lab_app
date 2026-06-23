<?php

namespace App\Modules\ClinicVisit\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clinic_room_id' => ['required', 'integer', Rule::exists('mst_clinic_rooms', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'clinic_room_id.required' => 'Pilih ruangan untuk pasien.',
            'clinic_room_id.exists' => 'Ruangan yang dipilih tidak valid.',
        ];
    }
}
