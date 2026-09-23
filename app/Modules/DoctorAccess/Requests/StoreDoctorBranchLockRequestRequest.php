<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — filing an initial assignment or a
 * permanent transfer.
 *
 * WHAT THE CLIENT MAY SEND: a destination, a reason, and — only when filing on
 * somebody else's behalf — a doctor. That is the entire surface.
 *
 * `request_type`, `source_branch_id`, `status`, `requester_user_id`,
 * `decided_by_user_id` and `applied_at` are all derived or decided server-side.
 * None has a rule here and none has a `$fillable` entry on the model, so a
 * forged value has nowhere to land. `request_type` in particular is DERIVED from
 * the doctor's live lock at filing time and RE-DERIVED under a row lock at
 * approval time; a client-supplied one is never read.
 *
 * ── THE `exists` RULES ARE A USABILITY FILTER, NOT THE BOUNDARY ───────────
 *
 * They spare the operator a round trip. The approval service re-validates the
 * destination for active + RME under a lock at DECISION time, because a branch
 * that was eligible when the request was filed may not be when it is approved,
 * and it re-reads the doctor under `FOR UPDATE` for the same reason.
 *
 * ── THE IDOR RULE, WHICH THIS CLASS CANNOT ENFORCE ────────────────────────
 *
 * `doctor_id` is READ ONLY when the actor holds `manage_doctor_branch_locks`.
 * For a doctor filing for themself it is IGNORED and replaced with their own
 * linked doctor id, resolved from `mst_doctors.user_id`. That substitution
 * happens in the controller, because a validation rule cannot express 'trust
 * this field only for this actor' — stating it here is documentation, not
 * enforcement, and the pin belongs in the test suite.
 */
class StoreDoctorBranchLockRequestRequest extends FormRequest
{
    /** The controller authorises through the policy, as its siblings do. */
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
            // Nullable: absent means 'for myself'. Present is honoured only for
            // an actor who may file on a doctor's behalf — see the class note.
            'doctor_id' => [
                'nullable',
                'integer',
                Rule::exists('mst_doctors', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true),
            ],
            'destination_branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')
                    ->where('is_active', true)
                    ->where('is_rme_enabled', true),
            ],
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
            'doctor_id.exists' => 'Dokter tidak ditemukan atau tidak aktif.',
            'destination_branch_id.required' => 'Pilih cabang tujuan.',
            'destination_branch_id.integer' => 'Pilih cabang tujuan.',
            'destination_branch_id.exists' => 'Cabang tujuan harus cabang RME yang aktif.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Jelaskan alasan minimal '.$this->reasonMin().' karakter.',
            'reason.max' => 'Alasan maksimal '.$this->reasonMax().' karakter.',
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
