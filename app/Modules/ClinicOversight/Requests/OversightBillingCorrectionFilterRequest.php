<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Requests;

use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Validation\Rule;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — filters of the BILLING
 * CORRECTION oversight pages: the rule-187 inclusive WITA date range (default =
 * the clinical today, max 366 days), a branch that only narrows, status, search.
 */
class OversightBillingCorrectionFilterRequest extends OversightDashboardFilterRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['nullable', 'string', Rule::in(RmeInvoiceCorrectionStatus::ALL)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        $route = $this->routeIs('oversight.billing-corrections.patients')
            ? 'oversight.billing-corrections.patients'
            : 'oversight.billing-corrections.index';

        return route($route);
    }
}
