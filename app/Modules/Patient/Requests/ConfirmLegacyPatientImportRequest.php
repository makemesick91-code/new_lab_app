<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Patient\Services\PatientService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
 *
 * The explicit confirmation step. Authorization: the route's
 * `permission:manage patients` group, plus — since rule 244 §4 — the paper-card
 * permission on the commit route, here and in the service.
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
    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4; plan default 4,
     * revises part of rule 168) — committing a batch stores typed Nomor RM, so it
     * needs the paper-card permission (Supervisor RME; Super Admin through '*').
     * Staging, review and cancel stay with `manage patients`. The route carries
     * the same permission and LegacyPatientImportService::commit() re-asserts it.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(PatientService::PAPER_CARD_PERMISSION);
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
