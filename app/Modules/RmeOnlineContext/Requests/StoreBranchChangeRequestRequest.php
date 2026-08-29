<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — filing a working-branch change request.
 *
 * WHAT THE CLIENT MAY SEND: a destination, and a reason. That is the entire
 * surface. `source_branch_id`, `clinical_date`, `role_context`, `status`,
 * `requester_user_id`, `decided_by_user_id` and `applied_at` are all derived or
 * decided server-side; there is no rule for them here and no `$fillable` entry
 * for them on the model, so a forged value has nowhere to land.
 *
 * The `exists` rule is a usability filter, NOT the authorization boundary — it
 * only spares the operator a round trip. The approval service re-validates the
 * destination under a lock at decision time, because a branch that was eligible
 * when the request was filed may not be when it is approved.
 */
class StoreBranchChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination_branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')
                    ->where('is_active', true)
                    ->where('is_rme_enabled', true),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_branch_id.required' => 'Pilih cabang tujuan.',
            'destination_branch_id.exists' => 'Cabang tujuan harus cabang RME yang aktif.',
            'reason.required' => 'Alasan perpindahan wajib diisi.',
            'reason.min' => 'Jelaskan alasan perpindahan minimal 10 karakter.',
        ];
    }
}
