<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RME price authority — owner D21, REVISED 2026-10-20 (manual cashier pricing)
|--------------------------------------------------------------------------
|
| CURRENT POLICY (REVISION-RME-MANUAL-PRICING-AUTHORITY-1, recorded in
| docs/decisions/rme-billing-correction-owner-decisions.md): there is NO
| mandatory official treatment tariff. The authorized cashier types the price
| of every line of the ORIGINAL invoice; prices may differ between branches and
| between patients. That price becomes the immutable financial baseline of the
| issued invoice; afterwards it changes only through the maker-checker invoice
| correction workflow. The tariff master is OPTIONAL reference data and is
| never required to create or correct an invoice.
|
| MODES (App\Modules\Tariff\Support\TariffPricingMode)
|
|   MANUAL           EVERY branch. The cashier price is accepted after server
|                    validation (integer cents, discount within its line,
|                    overflow, totals recomputed, a named treatment active) and
|                    every new line records MANUAL provenance.
|
|   STRICT_ENFORCED  LEGACY_SUPERSEDED (FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1,
|                    whose "prices come from the branch tariff master" D21 the
|                    owner has superseded). The code is retained, but the branch
|                    list below is SOURCE-CONTROLLED and EMPTY with NO environment
|                    override (the `global_permitted` precedent): re-imposing a
|                    mandatory tariff on a cashier must never be one host edit
|                    away. Reinstating it needs a new owner decision superseding
|                    the revised D21 AND a reviewed change of this file.
|
| No value in this file is read from the environment; nothing in app/ reads one either.
*/

return [

    /*
    | LEGACY_SUPERSEDED — canonical branch codes in strict tariff mode.
    | Source-controlled and empty: no branch is strict, and no environment
    | variable can make one strict (the former RME_TARIFF_STRICT_BRANCH_CODES
    | key is deliberately no longer read).
    */
    'strict_branch_codes' => [],

    /*
    | LEGACY_SUPERSEDED — price / discount EXCEPTIONS of the strict tariff mode.
    | They only ever existed to authorize a deviation FROM a mandatory tariff;
    | under manual pricing (revised D21) there is no tariff to deviate from, so
    | D21b/D21d/D21e/D21f are NOT_APPLICABLE. The switch stays OFF and inert.
    |
    | Historical text: D21 approved that an override or a discount outside policy needs specific
    | authorization, but the exception approver (D21b), the maximum discount
    | (D21c), the maximum deviation (D21d) and the thresholds (D21e/f) are NOT
    | decided. Until they are, exceptions are DISABLED for everyone — Super Admin
    | included — and strict mode accepts only the tariff price with no discount.
    |
    | SOURCE-CONTROLLED, with no environment override (the `global_permitted`
    | precedent): a switch that widens what a cashier may charge must not be
    | one host edit away. Opening it is a reviewed code change, and must not
    | happen before a maker/checker step exists — as built, the account that
    | creates the invoice is the account that authorizes its own exception
    | (D21b decides who may approve instead).
    */
    'price_exceptions_enabled' => false,

    /*
    | Bounds of the written reason an authorized exception must carry.
    */
    'exception_reason_min' => 10,
    'exception_reason_max' => 500,

];
