<?php

namespace App\Modules\DoctorDevice\Requests;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            //
            // PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — no longer the only
            // way in. On a deployment whose registry is empty there is nothing
            // to pick, which made the FIRST device on any deployment
            // unapprovable. So an approval may instead name a device to create.
            'doctor_device_id' => [
                'nullable',
                'required_without:device_name',
                'integer',
                'exists:mst_doctor_devices,id',
            ],

            // The two administrative facts a public key cannot carry. Not trust
            // inputs: the key decides identity and BranchContext decides where
            // a doctor is working. Everything else about the new device — the
            // key, the fingerprint, the algorithm, the reported model and OS —
            // is taken from the verified enrolment and cannot be supplied here.
            'device_name' => [
                'nullable',
                'required_without:doctor_device_id',
                'string',
                'max:120',
            ],
            'branch_id' => [
                'nullable',
                'required_with:device_name',
                'integer',
                Rule::exists('mst_branches', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doctor_device_id.required_without' => 'Pilih perangkat yang sudah terdaftar, atau isi nama perangkat baru.',
            'device_name.required_without' => 'Pilih perangkat yang sudah terdaftar, atau isi nama perangkat baru.',
            'branch_id.required_with' => 'Perangkat baru harus dimiliki oleh satu cabang.',
        ];
    }

    /** Did the operator ask for a new registry entry rather than an existing one? */
    public function createsNewDevice(): bool
    {
        return $this->filled('device_name') && ! $this->filled('doctor_device_id');
    }
}
