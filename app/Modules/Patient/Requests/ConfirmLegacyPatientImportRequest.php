<?php

namespace App\Modules\Patient\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
 *
 * The explicit confirmation step. Authorization is enforced by the route's
 * `permission:manage patients` group, as it is for every other action in this
 * workflow.
 *
 * WHY AN ACKNOWLEDGEMENT FIELD AT ALL. Confirmation used to be a bare POST
 * behind a JavaScript `confirm()`, which means the only record that a human
 * looked at the verification result lived in a dialog that leaves no trace and
 * that a crafted request skips entirely. Requiring the field moves "the operator
 * reviewed this" from the browser into the request the server actually
 * authorizes.
 *
 * It is NOT a security control. Importing is still gated server-side by the
 * batch's own state (zero errors, source hash unchanged, revalidation clean),
 * and a determined caller can of course send `acknowledged=1`. What it does is
 * make the import an act of confirmation rather than a side effect of pressing a
 * button, and make an unacknowledged import a validation failure rather than a
 * silent success.
 */
class ConfirmLegacyPatientImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'acknowledged' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'acknowledged.accepted' => 'Centang pernyataan bahwa hasil verifikasi batch sudah diperiksa sebelum mengimpor.',
        ];
    }
}
