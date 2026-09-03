<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceProofRequest extends FormRequest
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
            'nonce' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            // Base64 DER ECDSA signature. Bounded; the real check is the
            // cryptographic verification in DoctorDeviceProofService.
            'signature' => ['required', 'string', 'max:2000'],
        ];
    }
}
