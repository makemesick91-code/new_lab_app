<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — "Selesai Tanpa Biaya".
 *
 * Carries NOTHING the server decides: no amount, no category, no branch, no
 * status. The cashier only acknowledges that the patient is not charged; the
 * service decides eligibility on the locked rows. The controller authorizes
 * (policy closeZeroValue), so authorize() here only requires a session.
 */
class CloseRmeZeroValueInvoiceRequest extends FormRequest
{
    /**
     * While the capability is off the route must not even reveal its validation
     * rules: 404 before anything else, like the controller.
     */
    protected function prepareForValidation(): void
    {
        abort_unless(app(RmeZeroValueClosureFeatureGuard::class)->enabled(), 404);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acknowledged' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'acknowledged.accepted' => 'Centang konfirmasi bahwa pasien tidak dikenakan biaya untuk kunjungan ini.',
        ];
    }
}
