<?php

declare(strict_types=1);

use App\Modules\Tariff\Support\TariffAuthorityConfig;

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — branch tariff authority (owner D21)
|--------------------------------------------------------------------------
|
| D21 (owner-approved, recorded in docs/decisions/rme-billing-correction-owner-decisions.md):
| authoritative prices come from the approved treatment tariff master PER
| BRANCH; a branch without complete tariffs may keep its legacy billing
| workflow temporarily under documented, auditable transitional controls.
|
| MODES (App\Modules\Tariff\Support\TariffPricingMode)
|
|   TRANSITIONAL     the default for EVERY branch. The legacy cashier workflow
|                    is unchanged in what it accepts, but every new invoice
|                    line records its price provenance (tariff matched / no
|                    tariff / deviates from tariff / free text) so the
|                    transition is visible and auditable — never mistaken for
|                    verified tariff pricing.
|
|   STRICT_ENFORCED  only for a branch named here EXPLICITLY. The server
|                    resolves the price from the branch tariff; a mismatched
|                    price, a discount, a free-text line, a missing tariff, a
|                    zero tariff and any Rp0 line or total are refused.
|
| Nothing becomes strict because it has some tariffs. Readiness
| (`rme:tariff-authority-readiness`) is reported separately and never flips a
| mode by itself.
|
| The strict branch list is read from the environment HERE, at config-build
| time, so it survives `config:cache`; the exception switch is deliberately
| NOT environment-driven. Nothing in app/ calls env().
*/

return [

    /*
    | Canonical branch codes in strict mode, e.g. "TLK1". Tokens are trimmed,
    | upper-cased and canonicalised through BranchCodeAlias (a deprecated code
    | such as TKM1 names TLK1). Matching is exact-token. Empty = no strict branch.
    */
    'strict_branch_codes' => TariffAuthorityConfig::parseBranchCodes(env('RME_TARIFF_STRICT_BRANCH_CODES', '')),

    /*
    | Price / discount EXCEPTIONS in strict mode.
    |
    | D21 approves that an override or a discount outside policy needs specific
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
