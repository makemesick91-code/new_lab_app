<?php

namespace App\Modules\DoctorDevice\Requests;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only safe metadata is accepted at the boundary. `status`, `identity_state`
 * and `public_key_fingerprint` are absent by design — the service sets those,
 * so a crafted payload has nothing to bind to.
 */
class StoreDoctorDeviceRequest extends FormRequest
{
    /**
     * D11 — FILING a tablet, not trusting one.
     *
     * Deliberately `register` and not `create`: `create` is shared with
     * ApproveDoctorDeviceEnrollmentRequest, which binds an Android public key
     * and produces an immediately ACTIVE device. This form produces a
     * PENDING_APPROVAL row that can log nobody in, so it takes the weaker
     * authority that Supervisor RME holds.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('register', DoctorDevice::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:150'],
            'branch_id' => ['required', 'integer'],
            'platform' => ['nullable', 'string', 'max:40'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'os_version' => ['nullable', 'string', 'max:60'],
            'app_version' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
