<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — revoking a credential.
 *
 * A reason is mandatory and is stored. Revocation is irreversible by design, so
 * the record of WHY is the only thing that will explain the decision later.
 */
class DoctorDeviceWebAuthnRevokeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
