# FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — branch tariff authority, D10 payment block, D21 correction gate

**Status: foundation release.** Owner decisions D1, D7, D10 and D21 are recorded; the
branch tariff authority, server-side pricing with recorded provenance, the D10
pending-correction payment block and the D21(4) correction gate are implemented.
**Nothing is activated:** every branch is TRANSITIONAL (no official tariff list
exists for the only branch that bills), price exceptions are switched off, the
two exception permissions and the three correction permissions are granted to no
role, and `FEATURE_RME_INVOICE_CORRECTION` stays OFF.

Rule: `.cursor/rules/192-rme-branch-tariff-authority-financial-pricing.mdc`.
Decision register: `docs/decisions/rme-billing-correction-owner-decisions.md`.

## 1. Baseline (measured 2026-10-17 WITA, read-only)

| Item | Value |
|---|---|
| Integration branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base SHA (branch tip) | `eff4c36b` (docs-only on top of the runtime) |
| Production runtime | `50751554` = tag `activation-rme-invoice-correction-prepayment-1-payment-version-prerequisite-go` |
| `FEATURE_RME_INVOICE_CORRECTION` on production | not set → OFF |
| Corrections on production | 0 (any status) |
| Invoices / payments | 7 invoices (5 PAID, 1 PARTIAL, 1 UNPAID), Σ grand_total Rp 7.548.000; 8 payments, Σ Rp 5.548.000 |
| Roles / permissions | `role_has_permissions` 508, `permissions` 181, `model_has_permissions` 0 |

## 2. Owner decisions

Recorded in the decision register with three separate labels — owner policy
approved, repository policy recorded, technical enforcement deployed — never merged.
The approval was supplied with the task brief as task-supplied decision evidence;
no approval date, signature or reference was invented.

| Code | Policy | This release |
|---|---|---|
| D1 | Kasir submits; Admin Klinik approves in its branch; Super Admin across branches; never one's own | Enforcement already existed and is now pinned as the owner contract (test). **Role grants NOT applied** — activation ceremony. D1a (who applies) open. |
| D7 | v0 preserved, immutable v1…; references original, date, reason; nothing overwritten | Already deployed (rule 190 §3). No change. |
| D10 | Active correction ⇒ no payment; resume after REJECTED/CANCELLED/STALE/APPLIED with revalidation | **Implemented** on all three payment paths. |
| D21 | Branch tariff master is price authority; exceptions need authorization; transitional legacy billing for untariffed branches; no correction where the tariff cannot validate | **Implemented** as branch tariff authority; every branch TRANSITIONAL; exceptions built, OFF. |

D21 sub-decisions D21a–D21i (transition duration, exception approver, maximum
discount, maximum deviation, thresholds, maximum override, intrinsically-free
treatments, official tariff data, and which date selects the tariff — default the
invoice date) are PENDING; the code takes the fail-closed
default for each.

## 3. Branch tariff inventory (production, read-only)

Active treatments (global master): **13**. Tariffs exist only in TLK1.

| Branch | Mode | Readiness | Tariffed / active | Missing |
|---|---|---|---|---|
| TLK1 | TRANSITIONAL | TARIFF_INCOMPLETE | 11 / 13 | TRT-FREESC, TRT-SC99 |
| LDK2 | TRANSITIONAL | NO_TARIFFS | 0 / 13 | all |
| ATG3 | TRANSITIONAL | NO_TARIFFS | 0 / 13 | all |
| SPN4 | TRANSITIONAL | NO_TARIFFS | 0 / 13 | all |

- No duplicate active tariff, no tariff on an inactive treatment, no zero tariff,
  no future-only tariff. TLK1 prices range Rp 50.000 – Rp 3.500.000, all effective 2026-01-01.
- **Historically billed (all SPN4):** 8 lines — TRT-CONS ×1, TRT-EXTR ×3, TRT-FDEN ×1,
  TRT-PDEN ×1, TRT-SC99 ×1, free text ×1; **none** has a SPN4 tariff. 0 discounted
  lines, 1 zero-price line, 0 negative lines.
- **Invoice 36** (SPN4, UNPAID, Rp0, one TRT-CONS line, 2026-10-08) is a historical
  Rp0 invoice. It is left untouched (no rewrite of history); it is the D21g / D22
  question, not this sprint's.
- `TRT-FREESC` "Scalling Voucher Gratis" is intrinsically free: under strict pricing
  it can never be billed (Rp0 is refused) and TLK1 can never become TARIFF_READY
  while it is active — sub-decision D21g.
- Because the treatment master is global, "billable in a branch" = every active
  treatment; there is no per-branch billable list to measure against.

**Consequence:** strict pricing cannot be enabled for any branch today without
refusing legitimate billing. The official SPN4 price list (D21h) is the blocker,
and it was not invented.

## 4. Architecture

```
HTTP (cashier form) → CreateRmeInvoiceRequest (shape) → RmeInvoiceService::create()
   → lock visit → RmeInvoicePricingService::price()           ← the price authority
        → BranchTariffAuthorityService::modeFor() / resolveMany()
             → TariffRepositoryInterface (inForceForTreatments, treatmentUsability)
   → items written with provenance → totals in cents → RME_INVOICE_CREATED (+ pricing summary)
   strict refusal → rollback → RME_INVOICE_PRICING_REFUSED (after rollback) → field error

RmePaymentService (pay / allocateControlPayment / allocateVisitPayment)
   → lock invoice → assertFormVersion (rule 191) → assertNoActiveCorrection (D10)
   blocked → whole batch rolled back → RME_PAYMENT_REFUSED_ACTIVE_CORRECTION → `payment_blocked`

RmeInvoiceCorrectionService::submit / approve / apply
   → lock invoice → proposal builder → tariffAuthorityViolation() (D21(4))
   → approve/apply: staleReason() adds TARIFF_AUTHORITY_CHANGED
```

No parallel pricing system: the existing Sprint 19 `Tariff` module (branch-scoped
`mst_tariffs`) is the source; `TariffBoundaryService` is left as it was.

## 5. Transitional vs strict

| | TRANSITIONAL (default, every branch) | STRICT_ENFORCED (explicit config only) |
|---|---|---|
| Cashier price | accepted | must equal the tariff |
| Discount | accepted (≤ line gross) | refused unless an authorized exception |
| Free-text line | accepted | refused |
| Treatment without tariff | accepted | refused |
| Zero tariff / Rp0 line or total | accepted (legacy behaviour kept) | refused |
| Provenance | recorded per line | recorded per line |
| Claims tariff verification | never | yes, for matched lines |

All modes: integer cents, discount ≤ gross (new — negative lines were accepted
before; production held none), overflow refused, totals recomputed.

Transitional is not a long-term override policy: it is the documented, auditable
state of a branch whose official tariffs do not exist yet, visible on the billing
form ("Mode tarif transisi"), on every line ("Transisi — …"), and in
`rme:tariff-authority-readiness`.

## 6. Price / discount exceptions

Built, switched OFF. Requires all of: `price_exceptions_enabled` (a source-controlled
literal `false` — no environment override, so opening it is a reviewed code change),
the specific permission (`override_rme_tariff_price` / `authorize_rme_tariff_discount`),
and a 10–500 character reason. Granted to no role; the config switch keeps them
closed for Super Admin too. An authorized exception never creates an Rp0 line or
total and is recorded with who and why (audit carries the reason length only).

**Not safe to switch on as built:** the account that creates the invoice is the
account that authorizes its own exception — there is no maker/checker step. D21b
(the exception approver) must be decided and a separate approval step built first.

## 7. D10 — pending correction blocks payment

Checked under the invoice row lock on every payment path for every invoice the
batch would settle; whole-batch rollback; audited after rollback; surfaced on field
`payment_blocked` with "Tagihan sedang dalam proses koreksi. Pembayaran dapat
dilanjutkan setelah proses koreksi selesai." The FormRequest repeats it before the
amount cap. Payment and invoice pages explain the block and disable the action.

Not flag-gated (it refuses nothing while no correction is active); a correction
left active by a flag switched off still blocks — **resolve every active correction
before switching the flag off.** Rule 191 §3 item 10 ("a payment against a pending
correction is accepted") is superseded.

## 8. D21(4) — correction only within tariff authority

Every resulting line (kept, changed, added) must be a treatment billed at exactly
its branch tariff in force on the invoice's clinical date, with no discount;
free-text, missing/zero tariff, deviation and discount are refused. Approve/apply
re-check under the lock (STALE `TARIFF_AUTHORITY_CHANGED`). A kept line is not
re-litigated against today's treatment status. Applied lines carry `TARIFF_MATCHED`
provenance.

## 9. Tariff provisioning

- Master-data tariff changes are now audited (`RME_TARIFF_CREATED|UPDATED|DELETED`).
- `php artisan rme:tariff-template --branch=SPN4` — import-ready CSV, price blank.
- `php artisan rme:tariff-import <csv> --branch=SPN4 --effective-date=YYYY-MM-DD` — dry
  run; then `--apply --confirm-plan=<digest> --actor=<id|email> --reason="…"`.
  Whole-plan refusal on any invalid row or conflict; only creates; idempotent.
- **No production tariff was written by this sprint.**

## 10. Database

One additive migration `2026_10_17_200001_add_price_provenance_to_trx_rme_invoice_items_table`:
nullable `pricing_mode`, `price_source`, `tariff_id` (FK `mst_tariffs`, null on
delete), `tariff_unit_price` (decimal 18,2 = the tariff column), `price_exception_reason`, `price_exception_by` (FK
`users`, null on delete). No backfill; no column altered or dropped.

## 11. RBAC

`PermissionSeeder` + `override_rme_tariff_price`, `authorize_rme_tariff_discount`
(RME group). Granted to no role. **No `RoleSeeder` change.** Finance Klinik and Data
Analyst stay read-only; the laboratory `Finance` role gains nothing.

## 12. Tests

New: `tests/Feature/RmeTariffAuthority/{Resolution,InvoicePricing,Commands,Governance}Test`,
`tests/Feature/RmeInvoiceCorrection/RmeInvoiceCorrection{PaymentBlock,TariffAuthority}Test`,
three D10 races in `RmeInvoiceCorrectionConcurrencyTest` (PostgreSQL 16).

Updated (superseded contract, each annotated in place): the correction fixtures now
provision branch tariffs; two lifecycle tests write a payment row directly (a
writer that bypassed D10) to keep proving STALE:PAYMENT_RECORDED; one rule-191 test
that pinned "payment accepted while a correction is pending" now pins the D10
refusal; four line prices moved onto the tariff. No test was deleted or skipped.

CI: the new files are declared in `config/ci_runner.php`
`critical_gate_mandatory_suites`; token `RmeTariffAuthority` added to both critical
gate variants.

## 12a. Independent security review and mutation testing

**Security review (independent, adversarial): no CRITICAL, no HIGH.** Every
real finding was fixed in this release:

| Sev | Finding | Fix |
|---|---|---|
| MEDIUM | The exception switch was environment-driven, and the invoice creator would authorize its own exception | Switch is now a source-controlled literal `false` (no environment override); docs/rule state it must not open before a D21b maker/checker step exists |
| LOW | Exponent notation (`1.8e17`) passed `numeric` and wrapped in the cents conversion | FormRequest `decimal:0,2` + `max`; the pricing service refuses any non-plain amount for every caller (`INVOICE_AMOUNT_INVALID`) |
| LOW | Tariff import matched treatment codes case-sensitively | Case-insensitive match; codes differing only by case are refused (`CODE_AMBIGUOUS`) |
| LOW | `step="1"` blocked a tariff with cents in the UI | `step="0.01"` |
| LOW | Comment overclaimed the proposal builder's active-treatment check | Comment corrected to what line 127 does |
| INFO | `tariff_unit_price` narrower than `mst_tariffs.price` | Widened to decimal(18,2) (migration not yet deployed) |
| INFO | Template CSV wrote codes without formula neutralisation | `csvSafe` convention applied; the import undoes it |
| INFO | Correction flag description/rollback text stale | Updated; rollback now says resolve active corrections first |
| INFO (pre-existing) | Treatment names inside a JS template literal on the billing form | Options moved to an HTML `<template>` |

Accepted, not changed: a D21(4) refusal at correction submit is a plain
validation error (not audited), matching the other submit-time validation
refusals; tariff date basis recorded as sub-decision D21i (default: invoice date).

**Mutation testing (copy-revert harness, each mutant verified applied):**
23 mutants, **23 killed, 0 survivors.** Round one left two survivors, both real
gaps, both closed with a new test: M09 (strict Rp0 *total* guard — only a direct
pricing-service caller with no lines reaches it) and M16 (D10 on the *current*
invoice of a carry-over visit payment — over HTTP the FormRequest refused first
and hid a missing service check).

## 13. Rollback

Code rollback to the previous tag. The migration's columns are nullable and unread
by older code, so they may stay. Tariff history, invoices, payments, audit rows,
document versions and role assignments are untouched by a rollback.

## 14. Owner follow-up (blocking activation)

1. Official tariff list for SPN4 (and LDK2, ATG3) — D21h. Import with the commands above.
2. TLK1 missing TRT-FREESC and TRT-SC99 — and the Rp0 policy for voucher/free treatments (D21g).
3. Exception approver and limits (D21b–D21f) before `price_exceptions_enabled` is ever turned on.
4. Transition duration (D21a).
5. D1a — who applies an approved correction.
6. Then: strict mode per branch (config + supervised window), RoleSeeder grant (D1),
   flag ON, maker/checker pilot (rule 191 §20).
