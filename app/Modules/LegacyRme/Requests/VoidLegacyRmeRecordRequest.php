<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use App\Modules\LegacyRme\Services\LegacyRmeVoidService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY-RME-PDF-1D — the VOID form boundary.
 *
 * Authorization is deliberately NOT decided here. The controller resolves the
 * record through the caller's server-resolved branch scope first (so an
 * out-of-scope id is a 404, not a 403 that would confirm the id exists) and
 * only then authorizes the policy ability. Returning true here keeps that
 * ordering intact instead of leaking existence through the form layer.
 *
 * The reason floor is repeated in LegacyRmeVoidService: a FormRequest only
 * guards the HTTP door, and the service must stay safe for any caller.
 */
class VoidLegacyRmeRecordRequest extends FormRequest
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
            'void_reason' => [
                'required',
                'string',
                'min:'.LegacyRmeVoidService::MIN_REASON_LENGTH,
                'max:'.LegacyRmeVoidService::MAX_REASON_LENGTH,
            ],
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
                LegacyRmeVoidService::MIN_REASON_LENGTH,
            ),
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('void_reason'));
    }
}
