<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unauthenticated by design: the caller is an Android install that has no
 * account. Nothing here grants trust — it only opens a pairing request that an
 * administrator must approve.
 */
class DeviceEnrollmentRequest extends FormRequest
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
            // Base64 DER X.509 SubjectPublicKeyInfo. Bounded so a caller cannot
            // post megabytes; the service does the real cryptographic validation.
            'public_key' => ['required', 'string', 'min:80', 'max:4000'],
            'key_algorithm' => ['required', 'string', 'max:40'],
            // Advisory metadata only — never an authority.
            'platform' => ['nullable', 'string', 'max:40'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'os_version' => ['nullable', 'string', 'max:60'],
            'app_version' => ['nullable', 'string', 'max:60'],
        ];
    }
}
