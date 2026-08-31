<?php

namespace App\Modules\Doctor\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
 *
 * Shape validation only. Whether the pair is *eligible* (active account, holds
 * the Doctor role, neither side already linked) is decided by
 * DoctorAccountLinkService under a row lock, because those checks and the write
 * must observe the same state.
 */
class LinkDoctorAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'confirm_relink' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Pilih akun login yang akan dihubungkan.',
            'user_id.exists' => 'Akun pengguna tidak ditemukan.',
        ];
    }

    public function linkedUserId(): int
    {
        return (int) $this->validated()['user_id'];
    }

    public function confirmsRelink(): bool
    {
        return $this->boolean('confirm_relink');
    }
}
