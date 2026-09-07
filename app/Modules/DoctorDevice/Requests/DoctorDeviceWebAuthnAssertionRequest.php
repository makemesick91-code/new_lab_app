<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — shape validation for an assertion response.
 *
 * `authorize()` returns true because there is deliberately no authenticated
 * user at this point: the password step has been torn down on purpose, and the
 * only thing that may complete this request is a valid signature. Authorization
 * here is the assertion itself.
 */
class DoctorDeviceWebAuthnAssertionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1400'],
            'credential.rawId' => ['required', 'string', 'max:1400'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string', 'max:8192'],
            'credential.response.authenticatorData' => ['required', 'string', 'max:8192'],
            'credential.response.signature' => ['required', 'string', 'max:8192'],
            'credential.response.userHandle' => ['nullable', 'string', 'max:1400'],
        ];
    }

    /** @return array<string, mixed> */
    public function credentialPayload(): array
    {
        /** @var array<string, mixed> $credential */
        $credential = $this->validated()['credential'];

        return $credential;
    }
}
