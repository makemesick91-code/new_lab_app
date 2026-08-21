<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FIX-04b — retract a published legacy odontogram record.
 *
 * The reason is required and has a minimum length on purpose: a void is the
 * permanent, terminal record of why a piece of clinical evidence was withdrawn,
 * and "salah" tells a colleague reading the trail next year nothing at all.
 *
 * The bounds mirror LegacyOdontogramVoidService, which re-asserts them — the
 * service is reachable from a future CLI or another caller that never passes
 * through this request.
 */
class VoidLegacyOdontogramRecordRequest extends FormRequest
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
        $min = max(1, (int) config('legacy_odontogram.void.min_reason_length', 10));
        $max = max($min, (int) config('legacy_odontogram.void.max_reason_length', 500));

        return [
            'void_reason' => ['required', 'string', 'min:'.$min, 'max:'.$max],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'void_reason.required' => 'Alasan pembatalan wajib diisi.',
            'void_reason.min' => sprintf(
                'Alasan pembatalan wajib diisi minimal %d karakter.',
                max(1, (int) config('legacy_odontogram.void.min_reason_length', 10)),
            ),
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('void_reason'));
    }
}
