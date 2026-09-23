<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — filing an emergency grant.
 *
 * The bounds quoted here are the SAME config values the service re-asserts
 * inside its transaction, so the operator is told exactly the bound the server
 * enforces rather than a friendlier approximation of it. This request is the
 * convenience layer; the service is the boundary.
 */
class StoreDoctorBreakGlassGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grant_doctor_break_glass_access') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The admitted ACCOUNT. Never a doctor_id: the gate narrows on the
            // user, and the two id spaces have collided here before.
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => [
                'required',
                'string',
                'min:'.(int) config('doctor_access.reason.min_length', 10),
                'max:'.(int) config('doctor_access.reason.max_length', 1000),
            ],
            'hours' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('doctor_access.break_glass.max_hours', 12),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $max = (int) config('doctor_access.break_glass.max_hours', 12);
        $min = (int) config('doctor_access.reason.min_length', 10);

        return [
            'user_id.required' => 'Pilih dokter yang akan diberi akses darurat.',
            'reason.required' => 'Alasan akses darurat wajib diisi.',
            'reason.min' => "Alasan akses darurat minimal {$min} karakter — sebutkan kejadiannya, bukan sekadar 'darurat'.",
            'hours.required' => 'Tentukan durasi akses darurat.',
            'hours.max' => "Durasi akses darurat maksimal {$max} jam.",
        ];
    }
}
