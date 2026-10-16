<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the runtime switch for the
 * invoice correction MUTATION capability.
 *
 * The flag `rme.invoice_correction` defaults to OFF and stays OFF until the owner
 * decides who may request, approve and apply a correction (D1), whether a pending
 * correction blocks payment (D10), the price authority the proposal is checked
 * against (D21) and the receipt-versioning policy (D7). Every mutation entry point
 * — submit, approve, reject, cancel, apply — asserts it here, in the SERVICE, so a
 * command, a job or a future controller cannot reach the capability by a route
 * that forgot to check.
 *
 * WHAT IT DOES NOT GATE. Reading an existing correction, its history and the
 * frozen document versions is NOT flag-gated: once a correction has been applied
 * the frozen original is the only true copy of that document, and switching the
 * capability off (an emergency stop for new mutations) must never hide financial
 * history. Read is governed by permission and branch scope alone.
 *
 * Flag keys contain dots, so they are resolved through FeatureFlagService —
 * config('feature_flags.flags.rme.invoice_correction') would read the dot as
 * nesting and silently return null.
 */
final class RmeInvoiceCorrectionFeatureGuard
{
    public const FLAG = 'rme.invoice_correction';

    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }

    /**
     * @throws ValidationException
     */
    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'rme_invoice_correction' => 'Fitur koreksi tagihan RME belum diaktifkan.',
            ]);
        }
    }
}
