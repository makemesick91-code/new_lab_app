<?php

namespace App\Modules\RmeOnlineContext\Requests;

use App\Modules\RmeOnlineContext\Requests\Concerns\ConfirmsFirstDailyBranchSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — Kasir selects the branch it is
 * currently working in, using the same branch-only online context mechanism as
 * Admin Klinik and Perawat. The cashier workspace and every payment mutation
 * are scoped to that selection server-side.
 */
class StartKasirOnlineContextRequest extends FormRequest
{
    // D5 — the first branch choice of the clinical day is a commitment;
    // it must be confirmed before the lock is written.
    use ConfirmsFirstDailyBranchSelection;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Pilih cabang RME tempat Anda bertugas sebagai kasir.',
            'branch_id.exists' => 'Cabang yang dipilih harus cabang RME aktif.',
        ];
    }
}
