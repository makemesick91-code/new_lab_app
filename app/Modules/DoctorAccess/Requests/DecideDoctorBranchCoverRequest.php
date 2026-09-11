<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — a decision on a pending cover, or
 * the withdrawal of one.
 *
 * Approve, reject and cancel are separate endpoints, so the decision is carried
 * by the ROUTE and there is no `status` a client could set.
 *
 * ── WHY `cancellation_reason` IS NULLABLE HERE AND REQUIRED IN THE SERVICE ──
 *
 * The three endpoints share this class, and a conditional rule keyed off the
 * route would make the requirement true for the HTTP path only. Cancellation is
 * also reachable from a console context, and a cancellation nobody can explain
 * is not one — an active cover being withdrawn changes a working doctor's
 * effective branch and ends their session.
 *
 * So `DoctorBranchCoverApprovalService::cancel()` enforces the reason itself,
 * against the same `doctor_access.reason.min_length` this class would have
 * used. One rule, one place, and the two surfaces cannot diverge.
 *
 * ── `acknowledge_online_impact` IS AN ACKNOWLEDGEMENT, NOT AN AUTHORIZATION ──
 *
 * Identical to its sibling for lock requests: the checkbox is UI, and the
 * boundary is the service re-reading the doctor's live presence inside the
 * approval transaction. Tampering with this field only skips a warning the
 * approver has already been shown; it never skips a check and never prevents the
 * eviction. Whether the doctor WAS online, and whether this was sent, is
 * recorded in the `sys_audit_logs` payload — this table has no
 * `doctor_online_at_decision` column and this sprint adds no migration for one.
 */
class DecideDoctorBranchCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision_note' => $this->decidingRejection()
                ? ['required', 'string', 'min:'.$this->reasonMin(), 'max:1000']
                : ['nullable', 'string', 'max:1000'],
            'acknowledge_online_impact' => ['nullable', 'boolean'],
            'cancellation_reason' => [
                'nullable',
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
            'decision_note.required' => 'Alasan penolakan wajib diisi.',
            'decision_note.min' => 'Jelaskan alasan penolakan minimal '.$this->reasonMin().' karakter.',
            'decision_note.max' => 'Catatan keputusan maksimal 1000 karakter.',
            'acknowledge_online_impact.boolean' => 'Konfirmasi dampak sesi tidak dapat dibaca.',
            'cancellation_reason.min' => 'Jelaskan alasan pembatalan minimal '.$this->reasonMin().' karakter.',
            'cancellation_reason.max' => 'Alasan pembatalan maksimal '.$this->reasonMax().' karakter.',
        ];
    }

    /**
     * THE NOTE IS REQUIRED ON THE REJECT ROUTE AND OPTIONAL ON THE APPROVE ONE.
     *
     * Same reasoning as `cancellation_reason` two paragraphs up, and the same
     * division of labour: `DoctorBranchCoverApprovalService::reject()` is the
     * boundary and requires the reason for every caller, console included. This
     * route-keyed rule exists only so an approver who leaves the box empty is
     * told so on the form, and tampering with it skips nothing.
     */
    private function decidingRejection(): bool
    {
        return $this->routeIs('*doctor-branch-covers.reject');
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
