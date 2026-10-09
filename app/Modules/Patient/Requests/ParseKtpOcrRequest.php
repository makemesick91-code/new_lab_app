<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\KtpCameraOcrPilotGate;
use App\Modules\Patient\Services\KtpOcrParser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — bounds the OCR text the browser
 * sends back. The text is untrusted input: it is length-capped here, parsed by
 * {@see KtpOcrParser}, and never persisted.
 * Authorization mirrors patient creation, exactly like the KTP temp upload.
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
        ];
    }
}
