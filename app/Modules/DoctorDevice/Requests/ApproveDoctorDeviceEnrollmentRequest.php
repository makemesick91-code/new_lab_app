<?php

namespace App\Modules\DoctorDevice\Requests;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a pairing is a device-management action, so it carries the same
 * authority as disable/revoke — Super Admin only in practice.
 */
class ApproveDoctorDeviceEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DoctorDevice::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Which registry device this hardware becomes. The service
            // re-validates that the device is ACTIVE and not already bound to
            // another key, so this id alone decides nothing.
            'doctor_device_id' => ['required', 'integer', 'exists:mst_doctor_devices,id'],
        ];
    }
}
