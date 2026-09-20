<?php

namespace App\Modules\RmeOnlineContext\Requests;

use App\Modules\RmeOnlineContext\Requests\Concerns\ConfirmsFirstDailyBranchSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartAdminClinicOnlineContextRequest extends FormRequest
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
            'branch_id.required' => 'Pilih cabang RME untuk mulai bertugas.',
            'branch_id.exists' => 'Cabang yang dipilih harus cabang RME aktif.',
        ];
    }
}
