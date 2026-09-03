<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * A doctor login attempt from the Clinic App: credentials PLUS a signature over
 * a nonce this server issued.
 *
 * Note what is NOT here, and cannot be added: any client-asserted device
 * identity. There is no `device_id`, no `installation_uuid`, no
 * `X-Daengtisia-App` flag. The only thing that identifies the hardware is the
 * signature, and the only thing that says which key to verify it against is the
 * nonce's own server-side record.
 */
class DoctorAppLoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'nonce' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            // Base64 DER ECDSA signature. Bounded so a huge body cannot be used
            // to make the verifier do unbounded work.
            'signature' => ['required', 'string', 'min:16', 'max:1024'],
        ];
    }
}
