# UIX-12 — RME Cashier, Receivable & Follow-Up Polish

**Branch:** `feature/uix-12-rme-cashier-receivable-follow-up-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous required GO:** `uix-11-rme-medical-record-odontogram-print-bundle-polish-go`

## Scope

Twelfth UI/UX sprint — **presentation-only** deepening of the RME financial
operator surfaces (cashier, invoice/payment detail, partial-payment clarity,
receivable list, carry-over, follow-up) on top of the UIX-5 Kasir/Payment
baseline. UIX-5 already tokenized these views onto the design system; UIX-12
standardizes the *financial state* presentation with a shared summary card and
closes small consistency gaps.

**No business-logic change.** No controller/service/repository/query/route/
permission/policy/BranchContext/schema/migration change; no invoice/payment
calculation, invoice-status behavior, partial-payment behavior, remaining-amount
calculation, receivable query/aging logic, carry-over allocation, consent gate,
Lab billing, or visit-completion rule change. Blade + Tailwind + Alpine only; no
new dependency.

## Runtime UI changes

- **New reusable component `x-rme.invoice-summary`**
  (`resources/views/components/rme/invoice-summary.blade.php`) — the canonical
  financial summary card row: **Total Tagihan / Sudah Dibayar / Sisa Tagihan /
  Status Tagihan**. Presentation-only: it reads existing accessors
  (`grand_total`, `paidAmount()`, `remainingAmount()`, `status`) and never
  recomputes or mutates any value. Remaining amount is always visible; the
  status badge maps all five invoice states (`DRAFT`, `UNPAID`, `PARTIAL`,
  `PAID`, `VOID`) — so partial payment is always clearly labeled. Reuses
  `x-ui.badge` + the `ui-card` token surface; `tabular-nums` money.
- **`rme/cashier/show.blade.php`** — inserted `x-rme.invoice-summary` near the
  top; converted the carry-over raw `<table>` to `x-ui.table` with `tabular-nums`.
- **`rme/cashier/payment/create.blade.php`** — inserted `x-rme.invoice-summary`;
  converted the carry-over and "Ringkasan Tagihan" raw `<table>`s to `x-ui.table`.
  The Alpine payment logic, amount `max`/`x-bind`, selectable prior-receivable
  logic, and the consent-gate `x-ui.alert` (field names
  `consent_signed_by_patient` / `consent_signed_by_doctor`) are untouched.
- **`rme/cashier/index.blade.php`** — added the `PARTIAL` label/tone to the
  invoice-status maps (partial-payment clarity; previously fell through to the
  raw status string / neutral tone).
- **`rme/cashier/follow-ups/create.blade.php`** — inserted
  `x-rme.invoice-summary` (replacing the ad-hoc plain-text status/remaining rows
  with the standardized card + status badge). The manual, client-only WhatsApp
  reminder helper is unchanged and never includes KTP/NIK.

## Governance

`ArchitectureUiGovernanceCheckCommand` extended with non-brittle UIX-12 rules:
the `x-rme.invoice-summary` component exists and is free of legacy teal / KTP-NIK;
the invoice detail (`show`) and payment (`payment/create`) surfaces reuse
`x-rme.invoice-summary`; the UIX-12 evidence doc exists (soft). The existing
UIX-5 cashier no-teal / no-gold-CTA / no-KTP scans remain in force.

## Financial / RME rule preservation (explicit no-change)

- Invoice/payment **calculation** unchanged.
- Invoice **status behavior** unchanged: `DRAFT`, `UNPAID`, `PARTIAL`, `PAID`,
  `VOID`.
- **Partial payment behavior** unchanged (any successful cashier payment still
  completes the visit; remaining balance stays active piutang).
- **Remaining amount** calculation unchanged (`remainingAmount()`).
- **Carry-over allocation** unchanged (FIFO in `RmePaymentService`).
- **Consent gate** not bypassed (field names + validation preserved).
- **No Lab `trx_payments`** created from RME.
- **Visit completion** remains cashier/payment-driven; doctor cannot complete a
  visit directly.
- Medical-record / odontogram **finalization** logic unchanged.
- **Room gate (`visit.room`)** not bypassed.
- **No full KTP/NIK** rendered/exported/logged on any financial surface.

## Tests

- New `tests/Feature/Ui/CashierReceivableFollowUpUixTest.php` (8 examples):
  detail/payment render with the summary card, consent gate + field names
  intact, partial-payment label surfaced, full payment still reaches the
  `LUNAS` receipt, component completeness, reuse on detail/payment surfaces,
  no KTP/NIK/gold/teal, governance GO.

## Files changed

- `resources/views/components/rme/invoice-summary.blade.php` (new)
- `resources/views/rme/cashier/index.blade.php`
- `resources/views/rme/cashier/show.blade.php`
- `resources/views/rme/cashier/payment/create.blade.php`
- `resources/views/rme/cashier/follow-ups/create.blade.php`
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php`
- `docs/ui_design_system.md` (UIX-12 financial-summary standard)
- `docs/sprints/uix-12-rme-cashier-receivable-follow-up-polish.md` (this doc)
- `tests/Feature/Ui/CashierReceivableFollowUpUixTest.php` (new)

## Risk / Rollback

Low risk — presentation-only. The new component reads existing invoice accessors
only. Rollback: revert the branch / GO tag; no migration, no data change.

## Next recommended sprint

UIX-13 Owner Dashboard & KPI Polish (awaiting explicit user approval).
