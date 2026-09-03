<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceChallengeRequest extends FormRequest
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
            // SHA-256 hex of the device's own public key. Identifies which key
            // to challenge; it is NOT a credential and grants nothing on its own.
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
