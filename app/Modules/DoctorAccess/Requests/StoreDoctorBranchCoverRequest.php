<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use App\Modules\DoctorAccess\Support\DoctorBranchCoverPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — filing a temporary branch cover.
 *
 * `doctor_id` IS REQUIRED HERE, unlike its sibling for lock requests. A cover is
 * always filed on somebody else's behalf: section Q forbids a doctor
 * self-creating cover, so there is no 'for myself' case for the field to be
 * absent in. The enforced version of that rule is in the service, which refuses
 * `doctor->user_id === actor->id` — the policy cannot make the guarantee alone,
 * because the subject is identified by `mst_doctors.user_id` and not by the
 * actor.
 *
 * ── THE PERIOD RULES HERE ARE A USABILITY FILTER, NOT THE BOUNDARY ────────
 *
 * `after:starts_at` compares two strings in PHP's default timezone. That is
 * enough to catch a transposed pair while the operator is still typing, and it
 * is not authority for anything.
 *
 * THE AUTHORITATIVE PERIOD ARITHMETIC IS ELSEWHERE, and deliberately so:
 * {@see DoctorBranchCoverPeriod} interprets both values in the CLINIC's
 * canonical timezone — never `date_default_timezone_get()`, never the hard-coded
 * UTC application timezone, never a request header — normalises them to UTC
 * instants for storage, and checks the minimum and maximum duration. The
 * approval service runs all of it AGAIN under the doctor's row lock, because a
 * window that validated at 09:00 must not authorise a write at 09:05, and
 * because lowering `cover.max_days` must invalidate requests already sitting in
 * the queue.
 *
 * There is also no rule here for `status`, `source_home_branch_id`,
 * `requester_user_id` or any decision or cancellation stamp. None is fillable on
 * the model, and `source_home_branch_id` is read from the doctor's live home
 * lock, so a forged value has nowhere to land.
 */
class StoreDoctorBranchCoverRequest extends FormRequest
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
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('mst_doctors', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true),
            ],
            'target_branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')
                    ->where('is_active', true)
                    ->where('is_rme_enabled', true),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
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
            'doctor_id.required' => 'Pilih dokter.',
            'doctor_id.exists' => 'Dokter tidak ditemukan atau tidak aktif.',
            'target_branch_id.required' => 'Pilih cabang cover.',
            'target_branch_id.exists' => 'Cabang cover harus cabang RME yang aktif.',
            'starts_at.required' => 'Isi waktu mulai cover.',
            'starts_at.date' => 'Format waktu mulai tidak dapat dibaca.',
            'ends_at.required' => 'Isi waktu selesai cover.',
            'ends_at.date' => 'Format waktu selesai tidak dapat dibaca.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            'reason.required' => 'Alasan cover wajib diisi.',
            'reason.min' => 'Jelaskan alasan cover minimal '.$this->reasonMin().' karakter.',
            'reason.max' => 'Alasan cover maksimal '.$this->reasonMax().' karakter.',
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
