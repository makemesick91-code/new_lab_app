<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY-RME-PDF-ROLL-4 — change one branch's quota, plan or state.
 */
class UpdateLegacyRmeWaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('legacy_rme_operations.quota.max_declarable_daily', 500);
        $min = (int) config('legacy_rme_operations.min_reason_length', 10);

        return [
            'action' => ['required', 'string', 'in:quota,pause,resume,drain,cancel,complete'],

            // Nullable = inherit the wave default. Explicitly NOT the same as 0.
            'daily_quota' => ['nullable', 'integer', 'min:0', 'max:'.max(1, $max)],

            // Left NULL when nobody counted the archive. A guessed denominator
            // would make every completion percentage a fiction.
            'planned_document_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'reason' => [
                'required_if:action,pause,drain,cancel,complete',
                'nullable',
                'string',
                'min:'.max(1, $min),
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan wajib diisi minimal :min karakter.',
            'reason.required_if' => 'Alasan wajib diisi untuk tindakan ini.',
        ];
    }
}
