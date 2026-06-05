<?php

namespace App\Modules\Delivery\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkDeliveredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_name' => ['required', 'string', 'max:150'],
            'signature' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf'],
            'receiver_photo' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png'],
            'received_at' => ['required', 'date'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receiver_name.required' => 'Nama penerima wajib diisi.',
            'signature.required' => 'Tanda tangan penerima wajib diunggah.',
            'receiver_photo.required' => 'Foto penerima wajib diunggah.',
            'received_at.required' => 'Waktu penerimaan wajib diisi.',
        ];
    }
}
