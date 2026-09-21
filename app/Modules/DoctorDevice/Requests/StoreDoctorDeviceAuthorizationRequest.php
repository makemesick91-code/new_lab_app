<?php

namespace App\Modules\DoctorDevice\Requests;

use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 step 4 — file authorization
 * requests for one or more doctors on this tablet.
 *
 * The ONLY new mutation this workflow adds. Everything it produces is a PENDING
 * request decided later in Approval Device Dokter; see
 * {@see DoctorDeviceAuthorizationPolicy::requestForDevice} for why filing and
 * deciding share a permission and why that does not let the wizard approve
 * itself.
 *
 * `exists` is a sanity check, not the authorization. A doctor id that survives
 * validation is still re-read by the service inside a transaction before
 * anything is written.
 */
class StoreDoctorDeviceAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('requestForDevice', DoctorDeviceAuthorization::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doctor_ids' => ['required', 'array', 'min:1', 'max:50'],
            'doctor_ids.*' => ['integer', 'distinct', 'exists:mst_doctors,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doctor_ids.required' => 'Pilih minimal satu dokter.',
            'doctor_ids.*.exists' => 'Dokter yang dipilih tidak ditemukan.',
        ];
    }

    /**
     * @return list<int>
     */
    public function doctorIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            (array) $this->validated()['doctor_ids'],
        )));

        return $ids;
    }
}
