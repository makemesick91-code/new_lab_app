<?php

namespace App\Modules\Delivery\Requests;

class ReassignCourierRequest extends AssignCourierRequest
{
    public function rules(): array
    {
        return [
            'courier_id' => ['required', 'exists:users,id'],
            'notes' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'courier_id.required' => 'Kurir wajib dipilih.',
            'courier_id.exists' => 'Kurir tidak valid.',
            'notes.required' => 'Catatan pergantian kurir wajib diisi.',
        ];
    }
}
