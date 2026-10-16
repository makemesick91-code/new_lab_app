<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the note on a decision
 * (approve: optional; reject / cancel: required — re-checked in the service).
 */
class DecideRmeInvoiceCorrectionRequest extends FormRequest
{
    /**
     * Validation runs BEFORE the controller, so without this a malformed payload
     * would answer a validation error while the capability is off — confirming
     * an unfinished endpoint exists. Off means 404 here too, like the controller.
     */
    protected function prepareForValidation(): void
    {
        abort_unless(app(RmeInvoiceCorrectionFeatureGuard::class)->enabled(), 404);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->routeIs('rme.invoice-corrections.reject', 'rme.invoice-corrections.cancel');

        return [
            'note' => array_merge(
                $required ? ['required', 'min:'.RmeInvoiceCorrectionService::REASON_MIN] : ['nullable'],
                ['string', 'max:'.RmeInvoiceCorrectionService::REASON_MAX],
            ),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Alasan wajib diisi.',
            'note.min' => 'Alasan minimal '.RmeInvoiceCorrectionService::REASON_MIN.' karakter.',
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated()['note'] ?? null;

        return is_string($note) ? $note : null;
    }
}
