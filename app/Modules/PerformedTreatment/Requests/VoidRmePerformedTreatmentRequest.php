<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Requests;

use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use Illuminate\Foundation\Http\FormRequest;

/** Rule 219 — void (never edit, never delete) one performed treatment, with a reason. */
class VoidRmePerformedTreatmentRequest extends FormRequest
{
    use EnsuresPerformedTreatmentAdmission;

    protected function prepareForValidation(): void
    {
        $this->ensurePerformedTreatmentAdmission();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:'.RmePerformedTreatment::VOID_REASON_MAX],
        ];
    }

    public function messages(): array
    {
        return [
            'void_reason.*' => 'Alasan pembatalan tindakan wajib diisi (maksimal '.RmePerformedTreatment::VOID_REASON_MAX.' karakter).',
        ];
    }
}
