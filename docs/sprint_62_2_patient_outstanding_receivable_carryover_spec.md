# Sprint 62.2 — Patient Outstanding Receivable Carry-over for New Visits

**Status:** IMPLEMENTED (2026-06-27) on branch `feature/sprint-62-2-patient-outstanding-receivable-carryover`. No migration, no permission, no route added. Implementation matches this spec with one documented divergence: the control-chain path (`allocateControlPayment`) was kept **unchanged** rather than re-expressed through the generalized engine, to preserve every existing control-visit test with zero risk; the ordinary patient-level path is a new sibling (`getOutstandingReceivablesForPatientVisit` / `getVisitPayableSummary` / `allocateVisitPayment` / `completeVisitAfterCashierBatch`) that reuses the shared private payment helpers. See `docs/sprint_history.md` (Sprint 62.2) for the as-built summary.
**Base branch (target):** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do **NOT** target `main`).
**Author date:** 2026-06-27
**Theme:** Allow the cashier to see and optionally collect a patient's previous outstanding receivables during *any* new RME visit cashier payment — without merging old debt into the new invoice.

---

## 0. Scope guardrails (read first)

- **HARD RULE:** Do **NOT** merge old receivables into the new invoice's `items` or `grand_total`. Old invoices stay separate rows for audit + Owner KPI accuracy. Each `trx_rme_invoice` keeps its own identity, status, and payment ledger.
- Reuse, do not duplicate, the existing FIFO allocation engine (`RmePaymentService::allocateControlPayment()`), which already records one `trx_rme_payment` per real invoice id.
- Preserve every Sprint 62.1 / hotfix gate: room gate (`visit.room`), consent gate, doctor→cashier completion gate, full-payment-only-per-invoice rule is **not** changed (an invoice is still either fully or partially paid; "full payment only" referred to *no installment plan object* — partial payments already complete the visit per the 2026-06-27 hotfix).
- KTP/NIK, scanned documents, and raw medical notes are never surfaced anywhere in this feature.

---

## 1. Current behavior summary

| Aspect | Current state |
|---|---|
| Ordinary new visit cashier | `RmePaymentController@create` → `getControlPayableSummary($rmeInvoice)`. For a non-control visit `has_carry_over` is **false**, so only the current invoice's remaining is shown. Old receivables are invisible. |
| Carry-over discovery | `RmeControlReceivableService::getCarryOverInvoicesForControlVisit()` — only triggers when the visit `isControlVisit()` **and** has `follow_up_of_visit_id`. Walks the ancestor chain (`collectAncestorVisitIds`) up the `follow_up_of_visit_id` links, same patient, RME branches only. |
| Selection | None. For a control visit, **all** chain-ancestor UNPAID/PARTIAL invoices are auto-included; cashier cannot pick a subset. |
| Allocation | `RmePaymentService::allocateControlPayment()` — FIFO: ancestor (parent) invoices first in chain order, remainder to the control invoice. One `trx_rme_payment` row per invoice, all sharing a `payment_batch_uuid`. |
| Invoice identity | Preserved. Each invoice keeps its own `status`/ledger; new invoice `grand_total` is never inflated. |
| Completion | Current visit completes on any payment made in the batch (`completeControlVisitIfSettled` for control; `completeVisitAfterCashierPayment` for plain). Parent balance never blocks current-visit completion. |
| Validation | `CreateRmePaymentRequest` caps `amount` at `total_payable` (carry-over path) or invoice remaining (plain path). |
| KPI / receivable report | `OwnerDashboardKpiService` and `rme.cashier.receivables` aggregate `grand_total − payments_sum_amount` **per invoice** over `status IN (UNPAID, PARTIAL)`. Already debt-by-invoice, no merging. |

**Conclusion:** The allocation engine, per-invoice payment ledger, and KPI aggregation are already exactly the model Sprint 62.2 needs. The only missing pieces are (a) a *generalized discovery* of a patient's outstanding receivables for **any** new visit (not just `follow_up_of_visit_id` chains), and (b) an explicit *cashier selection* of which receivables to collect.

---

## 2. Proposed behavior

At the cashier screen for **any** new RME visit (`rme.cashier.payment.create`):

1. **Tagihan Visit Saat Ini** — current visit invoice remaining (unchanged).
2. **Piutang Sebelumnya** — list of the patient's *other* outstanding invoices (UNPAID/PARTIAL, RME branches, same patient, **excluding the current invoice**), each with: visit number, visit date, branch, remaining amount. Each row has a **checkbox** (selectable). No KTP/NIK, no notes, no scans.
3. **Total Harus Dibayar** = current invoice remaining + Σ(selected previous receivables remaining). Recomputed client-side as checkboxes toggle; authoritatively recomputed server-side.
4. Cashier confirms which previous receivables to collect (zero, some, or all).
5. On submit, payment is allocated **FIFO**: selected previous receivables first (oldest first), then the current visit invoice.

The current control-visit behavior (`follow_up_of_visit_id`) becomes a **special case** of the generalized "patient outstanding receivables" discovery; the FIFO engine is shared.

### Decision: auto-select vs. explicit-select
- For **control visits with `follow_up_of_visit_id`**: preserve today's behavior — chain receivables are **pre-checked by default** (backward compatible) but now *deselectable*.
- For **ordinary new visits**: previous receivables are **listed but unchecked by default** (cashier opt-in), matching the business objective "cashier should be able to … optionally collect."

---

## 3. Data model impact

**No new tables. No new columns. No migration.**

Existing structures fully cover the requirement:
- `trx_rme_invoices` — `patient_id`, `branch_id`, `clinic_visit_id`, `status`, `grand_total`. Discovery query needs nothing new.
- `trx_rme_payments` — `rme_invoice_id`, `clinic_visit_id`, `amount`, `payment_batch_uuid`, `cashier_id`. The batch uuid already correlates multi-invoice allocations.
- `trx_clinic_visits` — `patient_id`, `branch_id`, status. Used for visit-number/date display.

The selection of receivables is **request-scoped input** (an array of invoice ids submitted by the cashier), not persisted state — so no schema change. The audit trail is the per-invoice `trx_rme_payments` rows + shared `payment_batch_uuid`, which already exists.

---

## 4. Migration needed?

**NO.** This is purely service/controller/view + request-validation work over existing columns and the existing `payment_batch_uuid` correlation. Explicitly: do not add columns, do not alter `trx_rme_invoices`, do not create a junction table.

---

## 5. Service design

### 5.1 New: patient-outstanding discovery (generalize the existing service)

Add to `RmeControlReceivableService` (keeps all carry-over logic in one place) a sibling method:

```
getOutstandingReceivablesForPatientVisit(ClinicVisit $visit, RmeInvoice $currentInvoice): Collection<RmeInvoice>
```

Rules:
- Same `patient_id` as the visit.
- `branch_id IN branches->rmeEnabledIds()` (branch isolation preserved).
- `status IN (UNPAID, PARTIAL)`.
- `id != currentInvoice->id` (never include the current invoice as its own receivable).
- `remainingAmount() > 0` (filter zero-remaining defensively).
- Ordered oldest-first by `(visit_date, invoice id)` for deterministic FIFO.
- Eager-load `clinicVisit`, `payments`, `items` (for remaining computation and display) — **no patient KTP, no medical record, no scans loaded.**

### 5.2 New: payable summary for any visit

```
getVisitPayableSummary(RmeInvoice $currentInvoice, array $selectedReceivableInvoiceIds = []): array
```

Returns:
```
[
  'outstanding_receivables' => Collection<RmeInvoice>,   // all eligible, for display
  'selected_receivables'    => Collection<RmeInvoice>,   // intersect(eligible, submitted ids)
  'selected_remaining'      => float,                     // Σ remaining of selected
  'current_invoice'         => RmeInvoice,
  'current_remaining'       => float,
  'total_payable'           => float,                     // selected_remaining + current_remaining
  'has_outstanding'         => bool,
]
```

`selected_receivables` is always the **server-side intersection** of (eligible discovery set) ∩ (submitted ids). Ids the cashier submits that are not eligible (wrong patient, wrong branch, already PAID, current invoice) are silently dropped — they can never be paid. This is the security boundary.

### 5.3 Allocation — reuse, lightly generalize

`RmePaymentService::allocateControlPayment()` is generalized (or a thin sibling `allocateVisitPayment()` is added that shares the private helpers) to accept the **selected** receivable set instead of deriving the chain internally:

- Iterate `selected_receivables` (already oldest-first) under `lockForUpdate()`.
- For each: skip if no longer payable; allocate `min(remainingPayment, parentRemaining)`; `recordPayment(...)` against **that invoice's own id** with the shared `$batchUuid`; `refreshInvoiceStatus`; `completeVisitAfterCashierPayment` for that prior visit (a prior visit still at `cashier_pending` would complete — but in practice prior visits are already `completed`, so this is a no-op guard).
- Remainder → current invoice (`recordPayment` against current id, refresh status).
- Complete current visit via the existing `completeVisitAfterCashierPayment` / `completeControlVisitIfSettled` rule: **any payment made in the batch** completes the current `cashier_pending` visit; zero-grand-total current visit completes only if a payment action occurred (cashier confirmation collecting a prior receivable counts).

The control-visit path (`follow_up_of_visit_id`) is re-expressed as: discovery set = chain ancestors, default selection = all. The generalized path is the same engine with a different discovery source and cashier-driven selection.

### 5.4 Idempotency / concurrency
- Whole allocation stays inside one `DB::transaction` with `lockForUpdate()` on the current invoice **and** each selected receivable (already the pattern).
- `assertInvoicePayable` re-checked under lock — if another cashier paid a receivable to PAID between page render and submit, the `isPayable()` skip prevents double payment.
- `amount` capped server-side at `total_payable` for the **server-recomputed selection** (not the client's claimed total).

---

## 6. Controller changes

`RmePaymentController`:

- **`create()`** — replace `getControlPayableSummary($rmeInvoice)` with `getVisitPayableSummary($rmeInvoice)` (no preselection on initial GET, except control-chain default-on). Pass `outstanding_receivables` + a `defaultSelected` flag to the view.
- **`store()`** — read the submitted `selected_receivable_ids` (validated array) from the request; build the server-side selection via `getVisitPayableSummary($rmeInvoice, $ids)`. Branch:
  - `selected_remaining > 0` → `allocateVisitPayment($rmeInvoice, user, validated, selectedIds)` (the generalized engine). Use existing receipt redirect + `payment_allocation` flash (already shows allocated-to-parent vs allocated-to-current).
  - else → existing plain `pay()` path.
- Keep `authorize('pay', $rmeInvoice)` — selected receivables are additionally authorized implicitly by the eligibility intersection (same patient + same RME branch set). Optionally assert each selected receivable passes the `pay` policy for defense-in-depth.

`RmeControlReceivableService` gains the two methods in §5; the existing control methods remain (re-expressed internally) for backward compatibility and existing tests.

---

## 7. UI changes

View `resources/views/rme/cashier/payment/create.blade.php`:

- New **"Piutang Sebelumnya"** card (only when `has_outstanding`): a `x-ui.table` with columns *Pilih (checkbox)* | No. Kunjungan | Tanggal | Cabang | Sisa Tagihan. Checkbox name `selected_receivable_ids[]`, value = invoice id. Control-chain rows pre-checked; ordinary rows unchecked.
- **"Tagihan Visit Saat Ini"** card unchanged.
- **"Total Harus Dibayar"** summary recomputed live (Alpine) from checked rows + current remaining; the `amount` input default = total. Server is authoritative.
- Receipt view (`rme.cashier.receipt.show`) already renders `allocatedToParent` / `allocatedToControl` via batch payments — relabel generically ("Dialokasikan ke Piutang Sebelumnya" / "Dialokasikan ke Tagihan Visit Ini"). Each underlying invoice keeps its own receipt/identity.
- **Privacy:** rows show only visit number/date/branch/amount. No KTP/NIK, no diagnosis, no scans.

---

## 8. Payment allocation algorithm

```
INPUT: currentInvoice, cashier, amount(>0), selectedReceivableIds[]
1. summary = getVisitPayableSummary(currentInvoice, selectedReceivableIds)   // server-side intersection
2. assert amount <= summary.total_payable               (else ValidationException)
3. batchUuid = uuid()
4. DB::transaction:
     lock currentInvoice; assertInvoicePayable; assertConsentVerified(currentVisit)
     remaining = amount
     FOR each rec in summary.selected_receivables (oldest-first):     // FIFO step 1
         if remaining <= 0: break
         lock rec; if !rec.isPayable(): continue
         alloc = min(remaining, rec.remaining)
         if alloc <= 0: continue
         recordPayment(rec, rec.visit, cashier, data, alloc, batchUuid, note)
         refreshInvoiceStatus(rec); completeVisitAfterCashierPayment(rec, rec.visit)
         remaining -= alloc
     IF remaining > 0:                                                 // FIFO step 2
         alloc = min(remaining, currentInvoice.remaining)
         if alloc > 0:
             recordPayment(currentInvoice, currentVisit, cashier, data, alloc, batchUuid)
             refreshInvoiceStatus(currentInvoice)
     completeCurrentVisit(currentInvoice, currentVisit, paymentMade = (anything allocated))
5. (post-commit) generateLabCandidatesIfPaid for each invoice that became PAID
```

**Payment-rule mapping (matches the task):**
1. **No payment** — never reaches the engine (`amount > 0` enforced by `normalizeAmount`/request `min:0.01`); current visit stays `cashier_pending`, old receivables untouched.
2. **Partial** — FIFO fills selected old receivables first, then current; unfilled balances stay UNPAID/PARTIAL (active piutang); current visit **completes** (any payment made). 
3. **Full** — selected receivables + current invoice each reach `remaining == 0` → PAID; current visit completes.
4. **Overpayment** — rejected: `amount > total_payable` throws the existing "Pembayaran tidak boleh melebihi total yang harus dibayar." (No change-giving / credit balance feature.)
5. **Zero-grand-total current visit** — current remaining = 0; old receivables still collectable if selected; current visit completes after cashier confirmation iff a payment action occurred (existing `completeControlVisitIfSettled` paymentMade rule). If cashier selects nothing AND current total is 0 → that is a no-amount action and is blocked by `amount > 0`; completing a free visit with truly nothing to pay continues to follow the existing free-visit completion path (out of scope to change here).

---

## 9. Idempotency risks

| Risk | Mitigation |
|---|---|
| Double-submit (network retry) creates two payment batches | Each click is a fresh `payment_batch_uuid`; the second submit re-locks invoices and `isPayable()` skips now-PAID invoices → at worst pays the *still-remaining* balance, never double-charges a settled invoice. Recommend front-end submit-disable + server `assertInvoicePayable` under lock (already present). |
| Receivable paid by another cashier between render and submit | `lockForUpdate` + `isPayable()` skip; that receivable is excluded, allocation cascades to next/current. |
| Stale selected ids (invoice voided/paid since page load) | Server-side eligibility intersection drops them; never paid. |
| Partial allocation re-run | FIFO is order-deterministic (oldest-first by visit_date,id); re-running only ever consumes remaining balances. |
| Lab candidate generation re-fire | Existing `generateLabCandidatesIfPaid` is idempotent via `firstOrCreate(rme_invoice_item_id)` (Phase 21.2); unchanged. |

---

## 10. Owner KPI impact

- **No double counting.** KPI `active_receivable` = Σ over invoices `IN (UNPAID,PARTIAL)` of `grand_total − payments_sum_amount`, computed **per invoice** (`OwnerDashboardKpiService` lines ~118–129, ~199–220, ~274–299). Because Sprint 62.2 records each allocation as a `trx_rme_payment` against its **own** invoice id and never inflates any `grand_total`, paying an old receivable simply increases that old invoice's `payments_sum_amount` and reduces its remaining — exactly once. The current invoice is counted independently.
- Revenue/`payments` KPIs sum `trx_rme_payments.amount`; FIFO produces one row per invoice, so total collected is correct and attributed to the right invoice/branch/date.
- **Branch attribution:** each payment row carries `branch_id` copied from its invoice; a cross-period/cross-branch old receivable is credited to its own branch, not the current visit's. (Note in test plan: confirm old receivable from branch A paid during a branch-A visit stays branch-A; cross-branch is impossible because discovery is RME-branch-scoped to the patient's invoices, but allocation credits each invoice's own branch.)
- No KPI schema/query change required; the design is intentionally KPI-neutral.

---

## 11. Receivable report impact

- `rme.cashier.receivables` lists outstanding invoices and aggregates per-invoice remaining — unchanged logic. After a Sprint 62.2 collection, the paid-down old invoice drops to PAID (disappears) or reduced PARTIAL (smaller remaining). Correct automatically.
- The follow-up reminder records (`trx_rme_receivable_follow_ups`) are unaffected; collecting via the new-visit cashier closes the underlying invoice the same way a direct payment would.
- Export (`cashier.receivables.export`) keeps masking — no KTP exposure; no change.

---

## 12. Branch isolation / security impact

- Discovery is strictly `branch_id IN rmeEnabledIds()` AND `patient_id = visit.patient_id`. A cashier can never surface or pay a receivable outside the active RME branch set.
- `assertInvoicePayable` re-checks `branch_id IN rmeEnabledIds()` under lock for every selected receivable.
- Submitted `selected_receivable_ids` are **never trusted**: they are intersected with the server-computed eligible set; non-matching ids are dropped. This blocks IDOR (paying another patient's / another branch's invoice by injecting ids).
- **KTP/NIK, scanned docs, raw medical notes:** not loaded, not displayed, not exported anywhere in the new card/receipt.
- **Consent gate:** `assertConsentVerified(currentVisit)` still required before any allocation. Supervisor RME has visibility but cannot bypass consent/room/doctor gates — all enforced server-side inside the transaction, not via hidden buttons.
- **Room/doctor gate:** the cashier screen is only reachable for `cashier_pending` visits that already passed room + doctor gates (Sprint 62.1). Unchanged.

---

## 13. Test plan

New file: `tests/Feature/RME/PatientOutstandingReceivableCarryOverTest.php`.

Discovery & display:
1. Ordinary new visit (no `follow_up_of_visit_id`) with a patient that has a prior UNPAID invoice → cashier screen lists it under Piutang Sebelumnya, **unchecked** by default.
2. Patient with no prior receivables → no Piutang card; only current invoice shown.
3. Prior PAID/VOID invoices excluded; prior PARTIAL with remaining included; current invoice never listed as its own receivable.
4. Branch isolation: prior receivable in a non-RME / other branch is **not** listed.
5. Cross-patient: another patient's outstanding invoice never listed.

Allocation:
6. Select one prior receivable + partial amount → FIFO fills prior first; current visit completes; current invoice may stay PARTIAL/UNPAID; prior invoice reduced/PAID.
7. Full amount = total_payable for selected prior + current → both PAID, current visit completed.
8. Overpayment (amount > total_payable) → ValidationException, nothing recorded.
9. No prior selected, normal current payment → behaves exactly like today's plain `pay()` (regression).
10. Zero-grand-total current visit + select a prior receivable → prior collected, current visit completes.
11. Each allocation produces one `trx_rme_payment` per invoice id sharing one `payment_batch_uuid`; `grand_total` of every invoice unchanged.

Security / gates:
12. Inject a `selected_receivable_ids` for another patient → silently dropped, not paid (assert no payment row, amount cap unaffected).
13. Consent not verified → payment blocked (existing message), no allocation.
14. Supervisor RME cannot bypass consent/room gate (server-side).

KPI / report:
15. After paying a prior receivable, `OwnerDashboardKpiService.active_receivable` drops by exactly the collected amount; total collected counted once; per-branch attribution correct.
16. `rme.cashier.receivables` reflects reduced/closed prior invoice.

Backward compatibility:
17. Existing control-visit (`follow_up_of_visit_id`) chain path: ancestors pre-checked, allocation identical to current `allocateControlPayment` behavior — keep/port existing `RmeControlVisitReceivableCarryOverPaymentTest` assertions green.

Regression suites to run green: `tests/Feature/RME` (full), CashierBilling, RmePayment, OwnerKpiDashboard, Receivable, SupervisorRme, Permission. Plus `vendor/bin/pint --test` and `git diff --check`.

---

## 14. Rollout / deploy plan

- Implement on a new branch off `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (e.g. `feature/sprint-62-2-patient-outstanding-receivable-carryover`). Do **NOT** target `main`.
- No migration → no DB change step. Standard pilot deploy follows the Phase 21.7 VPS checklist: backup DB first, `git pull`, `composer install` if needed, `php artisan migrate --force` (no-op here), `npm run build` (a Blade/Alpine UI change ships JS/CSS → run build), reset `storage`/`bootstrap/cache` perms. **Never** `migrate:fresh` / `db:wipe` on VPS.
- Feature is additive and defaults to opt-in (unchecked) for ordinary visits → low blast radius. Control-visit behavior preserved.
- Rollback: revert the branch merge; no data migration to undo (payments recorded are valid regardless).

---

## 15. Risks & mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Merging old debt into new invoice (violates hard rule) | High | Design keeps invoices separate; payments recorded per-invoice id; no `grand_total` mutation. Test 11 asserts this. |
| IDOR via forged `selected_receivable_ids` | High | Server-side eligibility intersection (patient + RME branch + payable); ids not in set are dropped. Test 12. |
| Double counting in KPI | Med | KPI aggregates per-invoice remaining; no inflation. Test 15. |
| Concurrency double-pay | Med | `lockForUpdate` + `isPayable()` skip; per-submit batch uuid. §9. |
| Cashier confusion (which invoice paid) | Low | Receipt shows allocation split (already supported); relabel generically. |
| Breaking existing control-visit chain flow | Med | Re-express chain as a discovery special-case; keep existing tests green (Test 17). |
| Privacy leak via new list | High | List shows only visit no/date/branch/amount; no KTP/NIK/notes/scans loaded. Tests assert no sensitive fields. |
| Overpayment expectation mismatch | Low | Explicitly reject; documented (rule 4). |

---

## 16. GO / NO-GO criteria

**GO when all are true:**
- Old receivables are listed and optionally collectable on any new visit; never merged into the new invoice `items`/`grand_total`.
- FIFO allocation: selected prior receivables (oldest-first) then current invoice; one `trx_rme_payment` per invoice under a shared `payment_batch_uuid`.
- Payment rules 1–5 behave as specified (incl. partial completes current visit, overpayment rejected, zero-total current visit handled).
- Branch isolation + cross-patient IDOR protection proven by tests; no KTP/NIK/scan/raw-note exposure.
- Consent/room/doctor gates unbypassable (incl. Supervisor RME).
- Owner KPI `active_receivable` and collected-revenue do not double count; per-branch attribution correct.
- Receivable report reflects paid-down prior invoices correctly.
- No migration; no permission/route added beyond reusing `cashier.payment.*`; `pint` + `git diff --check` clean; all named suites green.

**NO-GO if any:**
- Any path inflates the current invoice `grand_total` or merges line items.
- Cashier can surface/pay a receivable outside the patient or the active RME branch set.
- KPI double counts or attributes a prior-branch receivable to the current visit's branch.
- Any gate (consent/room/doctor) becomes bypassable.
- Any KTP/NIK/scan/raw-note appears in the new UI/export.

---

## Appendix A — Graphify-style data flow

```
Patient active receivables
  (trx_rme_invoices: same patient, RME branches, UNPAID|PARTIAL, remaining>0, != current)
        │  RmeControlReceivableService::getOutstandingReceivablesForPatientVisit()
        ▼
New visit cashier screen  (rme.cashier.payment.create)
  • Tagihan Visit Saat Ini (current remaining)
  • Piutang Sebelumnya (checkbox list — no KTP/notes/scans)
        │  cashier checks rows → selected_receivable_ids[]
        ▼
Cashier selects receivables to collect
  • server-side intersection(eligible, submitted) = authoritative selection
  • Total Harus Dibayar = current_remaining + Σ selected_remaining
        │  RmePaymentController@store → allocateVisitPayment()
        ▼
Payment allocation FIFO (single DB::transaction, lockForUpdate, batchUuid)
  1) selected prior receivables, oldest-first
  2) current visit invoice
        │  recordPayment() per invoice id
        ▼
Old invoice statuses updated   →  UNPAID/PARTIAL → PARTIAL/PAID (own ledger, own grand_total)
Current invoice status updated →  remaining→0 PAID, else PARTIAL
        ▼
Visit completed  (completeVisitAfterCashierPayment: any payment made → cashier_pending→completed)
        ▼
Receivable report / Owner KPI updated
  • per-invoice remaining recomputed → paid-down invoices drop off / shrink
  • collected revenue counted once, attributed to each invoice's own branch
  • NO double count, NO merged debt
```
