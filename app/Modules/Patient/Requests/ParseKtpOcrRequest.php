<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\KtpCameraOcrPilotGate;
use App\Modules\Patient\Services\KtpOcrParser;
use App\Modules\Patient\Services\KtpOcrSuggestionService;
use App\Modules\Patient\Support\KtpOcrConsent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — bounds the OCR text the browser
 * sends back. The text is untrusted input: it is length-capped here, parsed by
 * {@see KtpOcrParser} (reconciled by {@see KtpOcrSuggestionService} when a
 * per-field read is sent too), and never persisted.
 * Authorization mirrors patient creation, exactly like the KTP temp upload.
 *
 * PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — OCR processing requires the
 * KTP holder's consent (pilot decision D7). The request must carry `consent`
 * accepted AND the `consent_version` of the wording currently on screen
 * ({@see KtpOcrConsent}); anything else is refused before the text is parsed.
 * The pilot gate still runs first, so an operator outside the pilot gets the
 * same 404 with or without a consent field.
 */
class ParseKtpOcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Patient::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // A switched-off capability — or an operator outside the supervised
        // pilot (PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT) — answers as if
        // the endpoint did not exist. The gate resolves the branch server-side.
        abort_unless(
            app(KtpCameraOcrPilotGate::class)->allows($this->user(), $this),
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxLines = (int) config('scanner.ocr.max_lines', 60);
        $maxLength = (int) config('scanner.ocr.max_line_length', 200);

        return [
            'lines' => ['present', 'array', 'max:'.$maxLines],
            'lines.*' => ['array:text,confidence'],
            'lines.*.text' => ['present', 'nullable', 'string', 'max:'.$maxLength],
            'lines.*.confidence' => ['nullable', 'numeric', 'between:0,100'],
            // REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — the optional per-field
            // read. Only known field regions; text and confidence only; the
            // label each value is parsed under is chosen by the server.
            'fields' => ['sometimes', 'nullable', 'array:'.implode(',', array_keys(KtpOcrSuggestionService::FIELD_LABELS))],
            'fields.*' => ['array:text,confidence'],
            'fields.*.text' => ['present', 'nullable', 'string', 'max:'.$maxLength],
            'fields.*.confidence' => ['nullable', 'numeric', 'between:0,100'],
            // D7 — the operator states that the KTP holder agreed to the
            // approved wording. An outdated or unknown wording is refused.
            'consent' => ['accepted'],
            'consent_version' => ['required', 'string', 'max:64', Rule::in(KtpOcrConsent::acceptableVersions())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent.accepted' => 'Pembacaan otomatis memerlukan persetujuan pemilik KTP.',
            'consent_version.required' => 'Pembacaan otomatis memerlukan persetujuan pemilik KTP.',
            'consent_version.in' => 'Teks persetujuan sudah berubah. Muat ulang halaman lalu minta persetujuan kembali.',
            'consent_version.string' => 'Teks persetujuan sudah berubah. Muat ulang halaman lalu minta persetujuan kembali.',
            'consent_version.max' => 'Teks persetujuan sudah berubah. Muat ulang halaman lalu minta persetujuan kembali.',
        ];
    }
}
