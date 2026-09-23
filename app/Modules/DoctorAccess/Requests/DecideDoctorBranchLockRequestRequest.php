<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — a decision on a pending
 * assignment or transfer request.
 *
 * THE DECISION ITSELF IS CARRIED BY THE ROUTE, NOT BY A FIELD. Approve and
 * reject are separate endpoints, so there is no `status` a client could set and
 * no branch in the service keyed off request input. The only things sent here
 * are a note and an acknowledgement.
 *
 * ── `acknowledge_online_impact` IS AN ACKNOWLEDGEMENT, NOT AN AUTHORIZATION ──
 *
 * The approve form shows the subject doctor's live presence and, when they are
 * ONLINE, requires this box to be ticked with the warning that ending their
 * session may lose unsaved handwriting RM or odontogram input.
 *
 * The checkbox is UI. The BOUNDARY is
 * `DoctorAccessSubjectGuard::assertOnlineImpactAcknowledged()`, which re-reads
 * presence INSIDE the approval transaction and refuses when the doctor is online
 * and this was not sent. Two consequences follow, and both are the point:
 *
 *  - A screen rendered ten minutes ago cannot approve past a doctor who has come
 *    online since it was drawn, because presence is read now and not carried in
 *    from the form.
 *  - Tampering with this field only lets an approver skip a warning they have
 *    already been shown. It never skips a check, and it cannot make the
 *    eviction not happen.
 *
 * It is nullable rather than required because the same endpoint decides requests
 * for doctors who are offline, where there is nothing to acknowledge and
 * demanding a tick would train approvers to tick reflexively.
 *
 * The fact recorded at decision time — whether the doctor WAS online, and
 * whether this was sent — is persisted in the `sys_audit_logs` payload. There is
 * no `doctor_online_at_decision` column on this table and this sprint adds no
 * migration for one.
 */
class DecideDoctorBranchLockRequestRequest extends FormRequest
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
            'decision_note' => $this->decidingRejection()
                ? ['required', 'string', 'min:'.$this->reasonMin(), 'max:1000']
                : ['nullable', 'string', 'max:1000'],
            'acknowledge_online_impact' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision_note.required' => 'Alasan penolakan wajib diisi.',
            'decision_note.min' => 'Jelaskan alasan penolakan minimal '.$this->reasonMin().' karakter.',
            'decision_note.max' => 'Catatan keputusan maksimal 1000 karakter.',
            'acknowledge_online_impact.boolean' => 'Konfirmasi dampak sesi tidak dapat dibaca.',
        ];
    }

    /**
     * THE NOTE IS REQUIRED ON THE REJECT ROUTE AND OPTIONAL ON THE APPROVE ONE.
     *
     * Approve and reject share this class because the DECISION is carried by the
     * route, so the requirement has to be keyed off the route too. That is not
     * the divergence its cover-workflow sibling warns against: the BOUNDARY is
     * `DoctorBranchLockApprovalService::reject()`, which requires the reason for
     * every caller including a console one, against this same
     * `doctor_access.reason.min_length`. This rule exists only so an
     * approver who leaves the box empty is told so on the form instead of
     * receiving the service's refusal, and tampering with it skips nothing.
     */
    private function decidingRejection(): bool
    {
        return $this->routeIs('*doctor-branch-locks.reject');
    }

    private function reasonMin(): int
    {
        return max(1, (int) config('doctor_access.reason.min_length', 10));
    }
}
