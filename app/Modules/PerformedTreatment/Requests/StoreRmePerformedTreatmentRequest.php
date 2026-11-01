<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Requests;

use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule 219 — record one performed treatment. Shape only: the service decides
 * selectability, teeth, quantity bounds, duplicates and authority. The visit,
 * patient, branch, status and actor are never read from the request.
 */
class StoreRmePerformedTreatmentRequest extends FormRequest
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
            'treatment_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.RmePerformedTreatment::MAX_QUANTITY],
            'tooth_numbers' => ['nullable', 'array', 'max:52'],
            'tooth_numbers.*' => ['string', 'size:2'],
            'submission_token' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'treatment_id.required' => 'Pilih tindakan yang dilakukan.',
            'quantity.*' => 'Jumlah tindakan harus berupa bilangan bulat antara 1 dan '.RmePerformedTreatment::MAX_QUANTITY.'.',
            'tooth_numbers.*' => 'Nomor gigi tidak valid.',
            'submission_token.*' => 'Formulir tidak valid. Muat ulang halaman lalu coba lagi.',
        ];
    }

    /** @return array{treatment_id: int, quantity: int, tooth_numbers: list<string>, submission_token: string} */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'treatment_id' => (int) $data['treatment_id'],
            'quantity' => (int) $data['quantity'],
            'tooth_numbers' => array_values((array) ($data['tooth_numbers'] ?? [])),
            'submission_token' => (string) $data['submission_token'],
        ];
    }
}
