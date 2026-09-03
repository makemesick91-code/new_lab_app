<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * Ask for a nonce to sign. The fingerprint says WHICH key is being challenged;
 * it is not a credential and grants nothing on its own.
 */
class DoctorAppLoginChallengeRequest extends FormRequest
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
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
