<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — shape validation for an attestation response.
 *
 * This checks that the payload LOOKS like a credential, nothing more. Whether
 * it IS one is decided by the library's cryptographic verification, and no
 * amount of validation here could substitute for that. The rules exist so a
 * malformed body produces a 422 instead of an exception inside the verifier.
 */
class DoctorDeviceWebAuthnRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The policy check lives in the controller, against the device this
        // credential is being attached to. Authorizing here without that device
        // would be authorizing the wrong thing.
        return $this->user() !== null;
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
            'credential.response.attestationObject' => ['required', 'string', 'max:32768'],
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
