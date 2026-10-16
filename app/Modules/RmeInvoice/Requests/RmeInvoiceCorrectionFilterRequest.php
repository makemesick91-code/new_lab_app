<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — list filters for the
 * operational correction pages. No branch filter: the branch set is always the
 * actor's server-resolved working scope.
 */
class RmeInvoiceCorrectionFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(RmeInvoiceCorrectionStatus::ALL)],
            'mine' => ['nullable', 'in:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $validated = $this->validated();
        $filters = [];

        if (! empty($validated['status'])) {
            $filters['status'] = $validated['status'];
        }
        if (($validated['mine'] ?? null) === '1') {
            $filters['mine'] = (int) $this->user()->id;
        }
        if (! empty($validated['search'])) {
            $filters['search'] = $validated['search'];
        }

        return $filters;
    }
}
