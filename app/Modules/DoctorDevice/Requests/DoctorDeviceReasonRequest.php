<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Disable and revoke both demand a written reason. Revoke is deliberately
 * high-friction: it is terminal, so the operator must say why on the record.
 */
class DoctorDeviceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLifecycle', $this->route('doctorDevice')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Alasan terlalu singkat.',
        ];
    }
}
