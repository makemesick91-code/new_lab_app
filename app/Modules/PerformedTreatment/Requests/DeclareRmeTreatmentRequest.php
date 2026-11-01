<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Requests;

use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rule 219 / owner DPT-Q1 — the doctor's explicit "Ada Tindakan" / "Tidak Ada
 * Tindakan" answer. "Tidak Ada Tindakan" needs an intentional confirmation: it is
 * a clinical statement and it classifies the visit as operational KONTROL.
 */
class DeclareRmeTreatmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless(app(RmePerformedTreatmentFeatureGuard::class)->enabled(), 404);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'declaration_type' => ['required', 'string', Rule::in(RmeTreatmentDeclaration::TYPES)],
            'confirm_no_treatment' => ['accepted_if:declaration_type,'.RmeTreatmentDeclaration::TYPE_NO_TREATMENT],
        ];
    }

    public function messages(): array
    {
        return [
            'declaration_type.*' => 'Pilih "Ada Tindakan" atau "Tidak Ada Tindakan".',
            'confirm_no_treatment.accepted_if' => 'Centang konfirmasi bahwa tidak ada tindakan medis yang dilakukan pada kunjungan ini.',
        ];
    }

    public function declarationType(): string
    {
        return (string) $this->validated()['declaration_type'];
    }
}
