<?php

namespace App\Modules\Patient\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
 *
 * Cancelling a staged batch. Authorization is the route's
 * `permission:manage patients` group.
 *
 * The reason is OPTIONAL on purpose. Legacy Patient import has no business
 * concept of a cancellation reason, and a mandatory free-text field whose honest
 * answer is usually "the file had errors" would be filled with placeholders —
 * which is worse than an empty column, because it looks like evidence.
 */
class CancelLegacyPatientImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cancel_reason.max' => 'Alasan pembatalan maksimal 500 karakter.',
        ];
    }
}
