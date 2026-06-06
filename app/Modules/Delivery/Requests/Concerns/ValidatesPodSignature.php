<?php

namespace App\Modules\Delivery\Requests\Concerns;

trait ValidatesPodSignature
{
    /**
     * @return array<string, mixed>
     */
    protected function podSignatureRules(): array
    {
        return [
            'receiver_name' => ['required', 'string', 'max:150'],
            'receiver_signature_data' => ['required', 'string', 'max:500000', $this->validSignatureData(...)],
            'received_at' => ['required', 'date'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],
            'receiver_photo' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function podSignatureMessages(): array
    {
        return [
            'receiver_name.required' => 'Nama penerima wajib diisi.',
            'receiver_signature_data.required' => 'Tanda tangan penerima wajib diisi.',
            'received_at.required' => 'Waktu penerimaan wajib diisi.',
        ];
    }

    private function validSignatureData(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $value)) {
            $fail('Format tanda tangan tidak valid.');

            return;
        }

        $base64 = substr($value, strlen('data:image/png;base64,'));
        if (strlen($base64) < 100) {
            $fail('Tanda tangan penerima wajib diisi.');
        }
    }
}
