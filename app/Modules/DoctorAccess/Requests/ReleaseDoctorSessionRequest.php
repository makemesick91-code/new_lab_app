<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the approver's manual session
 * release (ruling P17), so an on-site Supervisor RME can clear a colleague's
 * stuck lease without an SSH session.
 *
 * ── THIS ACTION ENDS A LOGIN SESSION. IT ENDS NOTHING ELSE. ───────────────
 *
 * It must never revoke a device, a `DoctorDeviceAuthorization` or a WebAuthn
 * credential, and `DoctorSessionReleaseService` writes to none of those tables.
 * WebAuthn revocation is irreversible — the only write to `revoked_at` in the
 * registration service is `=> now()` — so a support action taken to unstick a
 * doctor at 08:00 would permanently destroy the credential on their tablet. The
 * doctor logs back in; they do not re-enrol their device.
 *
 * ── ONE FIELD, AND IT IS REQUIRED ─────────────────────────────────────────
 *
 * Ending somebody else's working session is exactly the kind of action whose
 * trail is read months later by a person trying to explain what happened. The
 * reason is written to `sys_audit_logs` under this action's own name, alongside
 * the doctor id and whether they were online. Nothing about the OTHER actor's
 * device, IP address, user agent or session id is recorded or shown — the
 * operator learns that a session existed and was cleared, not what it was
 * running on.
 *
 * WHICH DOCTOR is not a field here: it comes from the route binding, so a payload
 * cannot redirect the release at a different account.
 */
class ReleaseDoctorSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'min:'.$this->reasonMin(),
                'max:'.$this->reasonMax(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan mengakhiri sesi wajib diisi.',
            'reason.min' => 'Jelaskan alasan mengakhiri sesi minimal '.$this->reasonMin().' karakter.',
            'reason.max' => 'Alasan mengakhiri sesi maksimal '.$this->reasonMax().' karakter.',
        ];
    }

    private function reasonMin(): int
    {
        return max(1, (int) config('doctor_access.reason.min_length', 10));
    }

    private function reasonMax(): int
    {
        return max(1, (int) config('doctor_access.reason.max_length', 1000));
    }
}
