<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205, finding F3) — what the
 * payment page DISPLAYED, as declared back by the submitted form, and whether the
 * current (locked) state still matches it.
 *
 * A payment form declares two things, rendered from the current rows on every
 * page load and never restored from old input:
 *
 *  - `payment_plan` — which kind of payment the page offered:
 *      single       one invoice;
 *      receivables  the current invoice plus OPTIONAL previous receivables the
 *                   cashier may tick (Sprint 62.2);
 *      control      a Kontrol invoice whose outstanding carry-over origins are
 *                   collected first, oldest-first (Sprint 27);
 *      control_only a Kontrol invoice paid on its own while every origin is
 *                   disputed (rule 204);
 *  - `invoice_balances[<id>]` — the remaining balance displayed for every
 *    invoice the page showed for this payment.
 *
 * The payment service compares both UNDER the row locks, after the version and
 * hold checks, and refuses — whole batch, nothing recorded — when they no longer
 * hold. That is what replaces the pre-lock summary of F3: the cashier settles the
 * balances they saw, or nothing.
 *
 * Pure functions only. Used verbatim by the payment FormRequest (early, unlocked,
 * for a clear message) and by RmePaymentService (authoritative, under the locks),
 * so the two can never word or decide the same refusal differently.
 */
final class RmePaymentDeclaredView
{
    public const PLAN_FIELD = 'payment_plan';

    public const BALANCES_FIELD = 'invoice_balances';

    public const PLAN_SINGLE = 'single';

    public const PLAN_RECEIVABLES = 'receivables';

    public const PLAN_CONTROL = 'control';

    public const PLAN_CONTROL_ONLY = 'control_only';

    public const PLANS = [self::PLAN_SINGLE, self::PLAN_RECEIVABLES, self::PLAN_CONTROL, self::PLAN_CONTROL_ONLY];

    /**
     * The plan a payment page offers — the SAME rule the payment controller
     * renders with and the test helper builds forms with.
     */
    public static function planFor(bool $hasCarryOver, bool $hasOutstandingReceivables): string
    {
        if ($hasCarryOver) {
            return self::PLAN_CONTROL;
        }

        return $hasOutstandingReceivables ? self::PLAN_RECEIVABLES : self::PLAN_SINGLE;
    }

    /** How a remaining balance is rendered into the form (two decimals, no grouping). */
    public static function render(float $remaining): string
    {
        return number_format(round($remaining, 2), 2, '.', '');
    }

    /** @param array<string, mixed> $data */
    public static function plan(array $data): ?string
    {
        $plan = $data[self::PLAN_FIELD] ?? null;

        return is_string($plan) && in_array($plan, self::PLANS, true) ? $plan : null;
    }

    /**
     * Declared remaining balance in cents per invoice id, or null when the form
     * carries none or carries anything the server never renders.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>|null
     */
    public static function balances(array $data): ?array
    {
        $declared = $data[self::BALANCES_FIELD] ?? null;

        if (! is_array($declared) || $declared === []) {
            return null;
        }

        $out = [];
        foreach ($declared as $id => $value) {
            $idIsWhole = is_int($id) ? $id > 0 : (is_string($id) && ctype_digit($id) && (int) $id > 0);
            $cents = RmePaymentIntent::cents($value);

            if (! $idIsWhole || $cents === null || $cents < 0) {
                return null;
            }

            $out[(int) $id] = $cents;
        }

        return $out;
    }

    /**
     * The submitted receivable ids that the page actually DISPLAYED (declared a
     * balance for) — the cashier's real selection. An id the page never showed is
     * not a selection the cashier made; it is dropped exactly as before (the
     * Sprint 62.2 IDOR boundary), never paid.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function displayedSelection(array $data, int $routeInvoiceId): array
    {
        $balances = self::balances($data) ?? [];
        $ids = [];

        foreach ((array) ($data['selected_receivable_ids'] ?? []) as $value) {
            $id = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);

            if ($id > 0 && $id !== $routeInvoiceId && array_key_exists($id, $balances)) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The invoice's remaining balance, now, against the one the page displayed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function balanceConflict(array $data, RmeInvoice $invoice): ?RmeInvoiceBalanceConflict
    {
        $declared = self::balances($data);
        $id = (int) $invoice->id;
        $current = (int) RmePaymentIntent::cents($invoice->remainingAmount());

        if ($declared === null || ! array_key_exists($id, $declared)) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::MISSING, $id, null, $current);
        }

        if ($declared[$id] !== $current) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::CHANGED, $id, $declared[$id], $current);
        }

        return null;
    }

    /**
     * The single-invoice path. Reached for a page that offered one invoice, or the
     * receivables page with nothing ticked. A page that offered Kontrol carry-over,
     * or one whose ticked receivables are no longer collectable, must never land
     * here: the money it described is not this invoice's alone.
     *
     * @param  array<string, mixed>  $data
     */
    public static function singlePlanConflict(array $data, int $routeInvoiceId): ?RmeInvoiceBalanceConflict
    {
        $plan = self::plan($data);

        if ($plan === null) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::MISSING, $routeInvoiceId);
        }

        if ($plan === self::PLAN_SINGLE) {
            return null;
        }

        if ($plan === self::PLAN_RECEIVABLES && self::displayedSelection($data, $routeInvoiceId) === []) {
            return null;
        }

        return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::PLAN_CHANGED, $routeInvoiceId);
    }

    /**
     * The receivables path: every receivable the cashier ticked (and the page
     * displayed) must still be collectable now.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $collectableSelectedIds  the ids the server would settle
     */
    public static function receivablesPlanConflict(array $data, int $routeInvoiceId, array $collectableSelectedIds): ?RmeInvoiceBalanceConflict
    {
        $plan = self::plan($data);

        if ($plan === null) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::MISSING, $routeInvoiceId);
        }

        if ($plan !== self::PLAN_RECEIVABLES) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::PLAN_CHANGED, $routeInvoiceId);
        }

        $collectable = array_map('intval', $collectableSelectedIds);
        foreach (self::displayedSelection($data, $routeInvoiceId) as $id) {
            if (! in_array($id, $collectable, true)) {
                return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::PLAN_CHANGED, $id);
            }
        }

        return null;
    }

    /**
     * The Kontrol path: the outstanding origins the server would collect first
     * must be EXACTLY the origins the page displayed — none vanished (settled
     * elsewhere, so the money would move onto the Kontrol invoice) and none
     * appeared (collected without the cashier having seen it).
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $originIds
     */
    public static function controlPlanConflict(array $data, int $routeInvoiceId, array $originIds): ?RmeInvoiceBalanceConflict
    {
        $plan = self::plan($data);
        $balances = self::balances($data);

        if ($plan === null || $balances === null) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::MISSING, $routeInvoiceId);
        }

        if ($plan !== self::PLAN_CONTROL) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::PLAN_CHANGED, $routeInvoiceId);
        }

        $displayed = [];
        foreach ($balances as $id => $cents) {
            if ($id !== $routeInvoiceId && $cents > 0) {
                $displayed[] = $id;
            }
        }
        sort($displayed);

        $current = array_values(array_unique(array_map('intval', $originIds)));
        sort($current);

        return $displayed === $current
            ? null
            : new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::PLAN_CHANGED, $routeInvoiceId);
    }

    /**
     * The Kontrol-only path (rule 204): reachable only from its own explicitly
     * confirmed form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function controlOnlyPlanConflict(array $data, int $routeInvoiceId): ?RmeInvoiceBalanceConflict
    {
        $plan = self::plan($data);

        if ($plan === null) {
            return new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::MISSING, $routeInvoiceId);
        }

        return $plan === self::PLAN_CONTROL_ONLY
            ? null
            : new RmeInvoiceBalanceConflict(RmeInvoiceBalanceConflict::PLAN_CHANGED, $routeInvoiceId);
    }
}
