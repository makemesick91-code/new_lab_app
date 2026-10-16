# FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1

**Status:** pre-payment correction foundation — implemented, dormant (`rme.invoice_correction` OFF).
**Rule:** `.cursor/rules/190-rme-invoice-correction-receipt-foundation.mdc` (amends rules 186, 188, 189).
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `b3ff925b`
(production at start: `90eba2fa`, tag `feature-rme-billing-refund-1-phase-0-financial-prerequisites-go`).

## 1. Why this ships PARTIAL

The owner asked for invoice correction, maker-checker approval, history, original and revised receipts,
balance integrity, refund readiness and read-only oversight. Every owner decision D1–D27 that defines how a
correction may change money was still **PENDING** at implementation time (§3). Building any of them as live
behaviour would have meant inventing financial policy.

So the release splits along that line:

- Everything that needs **no** owner decision is implemented and tested: the data model, snapshots, the
  state machine, maker ≠ checker, STALE detection, frozen document versions, audit, and the read-only
  oversight area (whose scope the owner already approved under rule 186).
- Everything that changes money in production sits behind the **default-OFF, critical** flag
  `rme.invoice_correction`, and the three operational permissions are granted to **no role**. Turning the
  flag on still enables nobody until the owner grants a role (D1).
- Post-payment correction, refund, payment reversal, patient credit, Rp0 adjustment and retroactive revenue
  adjustment are **not implemented** and are refused regardless of the flag.

## 2. What exists after this release

| Area | Implementation |
|---|---|
| Schema | 5 additive migrations `2026_10_17_10000{1..5}`: `trx_rme_invoices.correction_version` (default 0, not fillable), `trx_rme_invoice_corrections`, `_lines`, `_events`, `trx_rme_invoice_document_versions`. PostgreSQL CHECKs on status/type/totals/difference and on version shape; partial unique index `trx_rme_inv_corr_one_active_uq` (one active correction per invoice). |
| Correction lifecycle | `SUBMITTED → APPROVED → APPLIED`; `REJECTED`, `CANCELLED`, `STALE` terminal. Service `RmeInvoiceCorrectionService`, builder `RmeInvoiceCorrectionProposalBuilder`, repository + interface, policy `RmeInvoiceCorrectionPolicy`. |
| Maker ≠ checker | Enforced in the service under the invoice row lock, Super Admin included (`Gate::before` cannot bypass it). Refused attempts audited as `RME_INVOICE_CORRECTION_REFUSED`. |
| Concurrency | Lock order invoice → correction (the lock every payment path takes). A payment that commits first makes the correction STALE; proven on PostgreSQL 16 with a real competing transaction. |
| Money | Integer cents, recomputed server-side. Submitted totals/branch/patient never trusted. No Rp0 line or Rp0/negative total. Applied total must equal the approved total or the apply rolls back. |
| Documents | v0 ORIGINAL frozen at first apply, vN REVISION per apply; immutable; rendered only from the snapshot; SHA-256 integrity; no patient identity inside. |
| Cashier UI | Correction panel on the invoice page (renders nothing — runs no query — while OFF and never corrected); create / list / approvals / detail / frozen-document pages under `rme.invoice-corrections.*`. |
| Oversight UI | `oversight.billing-corrections.*` (GET-only): Daftar Pasien Koreksi, Riwayat & Detail Koreksi, filters (search, status, branch, inclusive WITA date range), print, PDF, CSV. Fifth oversight permission `view_billing_correction_oversight` for Finance Klinik + Data Analyst. |
| Permissions | `request_rme_invoice_correction`, `approve_rme_invoice_correction`, `apply_rme_invoice_correction` (no role); `view_billing_correction_oversight` (Finance Klinik, Data Analyst). Laboratory `Finance` role unchanged. |
| Patient merge | `trx_rme_invoice_corrections` registered in `PatientOwnedRecordRegistry::REASSIGN`. |

## 3. Owner decision register (D1–D27)

Sources: audit §26 (D1–D23), refund sprint §4 (D24–D27). None was recorded as decided.

| # | Decision | Status | How this release treats it |
|---|---|---|---|
| D1 | Pre-payment approval authority | PENDING | Permission exists, granted to no role; flag OFF. |
| D2 | Post-payment approval authority | PENDING | Post-payment not implemented. |
| D3 | Admin Klinik may approve Rp0 | PENDING | Rp0 correction refused. |
| D4 | Super Admin required for refund | PENDING | No refund. |
| D5 | Refund method | PENDING | No refund. |
| D6 | Patient credit balance | PENDING | Not implemented. |
| D7 | Receipt versioning policy | PENDING | Versions frozen (needs no decision to be safe); only UNPAID invoices are correctable, so no issued receipt is affected. |
| D8 | Effect on historical revenue | PENDING | No payment row touched; revenue KPIs unchanged. |
| D9 | Gross vs net | PENDING | Not implemented. |
| D10 | Pending correction blocks payment | PENDING | Payment path untouched; a payment makes the correction STALE instead. |
| D11 | Rp0 per item vs whole invoice | PENDING | Rp0 refused. |
| D12 | Correction of a partially paid invoice | PENDING | Refused (any payment ⇒ `CORRECTION_TYPE_NOT_ENABLED`). |
| D13 | Cross-branch carry-over | PENDING | Correction belongs to the invoice's branch. |
| D14 | Maximum correction/refund | PENDING | No threshold invented. |
| D15 | Approval deadline | PENDING | No expiry invented; STALE only on real invoice drift. |
| D16 | Cancel an approved correction | PENDING | Maker may cancel before APPLIED with a reason (the conservative reading). |
| D17 | Mandatory supporting document | PENDING | Not implemented. |
| D18 | Refund execution separate from approval | PENDING | No refund. |
| D19 | Oversight export of all detail | APPROVED_SCOPE (rule 186) | Print/PDF/CSV behind `export_oversight_data`, no KTP. |
| D20 | Retention / receipt archive | PENDING | Snapshots immutable, never deleted. |
| D21 | Price authority | PENDING | Not invented: same validation as invoice creation. |
| D22 | Rp0 / zombie invoice completion | PENDING | Not implemented. |
| D23 | `requires_lab` items | PENDING | Invoice with lab candidates refused / STALE. |
| D24 | `paid_at` backdating window | PENDING | Untouched. |
| D25 | Settler ≠ approver | PENDING | No settlement. |
| D26 | Payment reversal | PENDING | Not implemented. |
| D27 | `paid_at` entry timezone | PENDING | Untouched. |

## 4. Capability dependency matrix (A–J)

| Capability | Needs a decision? | Delivered |
|---|---|---|
| A. Correction request | D1 to *enable* | Built, flag OFF |
| B. Maker-checker approval | D1, D2 | Built (pre-payment), flag OFF |
| C. Correction history | — | Built, readable |
| D. Original invoice snapshot | — | Built (on submit + v0 on apply) |
| E. Original receipt snapshot | D7 for policy | Built as frozen v0 (only UNPAID invoices) |
| F. Revised receipt versioning | D7 | Built as frozen vN, flag OFF |
| G. Approved pre-payment correction (apply) | D1, D10, D21 | Built, flag OFF |
| H. Financial balance integrity | — | Built (cents, server-side, STALE, rollback) |
| I. Post-payment / refund readiness | D2, D4–D6, D8, D9, D12, D18, D25, D26 | **Not built** — schema leaves room (`correction_type`), nothing executes |
| J. Read-only oversight | D19 (approved) | Built, live |

## 5. Verification

- `tests/Feature/RmeInvoiceCorrection/` — lifecycle 44, access 17, governance 10, concurrency 3 (PostgreSQL only).
  SQLite 71 passed + 3 PG-only skips; throwaway **PostgreSQL 16.15** (production major) **74/74**.
- Dependency regression (RME, ClinicOversight, PatientMerge, AccessControl, Navigation, Invoice): 2186 passed,
  14 skipped, 0 failed.
- **Mutation: 14/14 killed, 0 survived** (each guard reverted alone, restored by copy and verified): invoice lock
  in `lockPair()`, service self-approval, feature-flag guard, FormRequest 404, stale-on-payment, Rp0 line, lab
  candidates, treatment-clear, doctor re-attribution, overflow, negative total, terminal status, out-of-scope 404,
  approver attribution display.
- Two defects found by the tests and fixed before shipping: (1) the FormRequests validated before the
  controller's flag check, so a malformed POST answered a validation redirect instead of 404 — both now 404 in
  `prepareForValidation`; (2) an out-of-branch correction id answered 403, an existence oracle — it now answers
  404 like a missing one.
- Oversight governance pins updated: four → five permissions; 18 → 22 oversight output routes.

### Independent adversarial review — no CRITICAL/HIGH; all MEDIUM and actionable LOW fixed

| # | Finding | Resolution |
|---|---|---|
| M1 | Approver could not see doctor/treatment changes (blind approval) | Original → proposed treatment AND doctor on every line in cashier detail, oversight detail and print; treatment change marked |
| M2 | An inactive current treatment was absent from the form, so an untouched line became "no treatment" | Form always offers the current treatment (labelled nonaktif); builder refuses clearing an existing item's treatment |
| — | (follow-on) doctor re-attribution of an existing item | Refused (`CORRECTION_DOCTOR_CHANGE_NOT_PERMITTED`): attribution policy undecided |
| L1 | Integer overflow → 500 | Refused before multiplying (`CORRECTION_TOTAL_OVERFLOW`) |
| L2 | Negative-total invoice → CHECK violation 500 on PostgreSQL | Refused (`CORRECTION_INVOICE_TOTAL_NEGATIVE`) |
| L3 | Idempotency replay checked outside the lock | Re-checked under the invoice lock; an idempotency-index violation resolves to the replay |
| L5 | Out-of-scope invoice on create/store → 403 oracle | 404 |
| L6 | No model-level guard on terminal status | Model refuses moving APPLIED/REJECTED/CANCELLED/STALE |
| L4 | Payment not bound to `correction_version` | **Residual, D10** — payment path untouched; impossible while the flag is OFF (rule 190 §4a) |
| L7 | Flag-off mutation routes answer 403 (not 404) to accounts without a correction permission | Accepted: route middleware runs first; same as Legacy RME |

## 6. DataViz

No chart was introduced: the oversight area is tabular by design (history, lines, timeline). DataViz
verification: **NOT APPLICABLE / UNAVAILABLE** — nothing to verify.

## 7. Enabling later (not part of this release)

1. Owner decides at least D1, D7, D10, D21.
2. Grant the operational permissions to roles via `RoleSeeder` (PermissionSeeder → RoleSeeder →
   `permission:cache-reset`), with maker and checker on different accounts.
3. Set `FEATURE_RME_INVOICE_CORRECTION=true`, rebuild the config cache as the runtime user.
4. Rollback: set it false and rebuild the cache. Nothing is deleted; applied corrections and their frozen
   documents stay readable.

## 8. Shipped + production evidence (2026-10-17 WITA)

| Item | Evidence |
|---|---|
| PR | #487, squash-merged as `03fe25b1f308bf1a0c6623d9741b40dd52d799b4`; merge tree `46e37c51` == CI candidate tree (`f992757e`) |
| CI | run `38920201982` success — Classifier, NSF-R012 Quality, Android gate, NSF-R011 Critical (**5770 passed, 0 failed**, exit status 0; the PostgreSQL concurrency suite executed 3/3), Selective Module, NSF-9, NSF-10. **Full Suite: SKIPPED** by the active temporary policy — not a pass |
| Deploy | `scripts/deploy-vps-runner.sh start` run ON the VPS: `exit=0`, `DEPLOY OK: 20261016-172451`, `DEPLOY_HEAD_TARGET_MATCH=YES`, runtime isolation 70 GO / 0 FAIL; all 5 migrations DONE (201 → 206) |
| Seeders | PermissionSeeder → RoleSeeder → `permission:cache-reset` as `daengtisiams`. Permissions 177 → 181. Role grants 502 → 508: Finance Klinik +1, Data Analyst +1, Super Admin +4 — exactly the delta measured read-only beforehand; **0 removals** (`Tester RME` is not seeder-managed and was untouched) |
| Flag | `rme.invoice_correction` enabled=false, resolved from the default, env override captured (cache-safe), risk critical |
| Routes | 10 operational (`rme.invoice-corrections.*`) + 7 oversight; guest requests 302, `/login` and `/health/{live,ready,lb}` 200 over `https://daengtisia.online` |
| Data | RME invoices 6 / items 7 / payments 7 and Σ Rp 3.548.000 / Rp 3.548.000 **unchanged**; lab `trx_invoices`/`trx_payments` 0/0 unchanged; correction tables all empty; `correction_version` 0 on every invoice; partial unique index and 4 CHECK constraints present |
| Runtime | env pilot, debug OFF, maintenance OFF; php8.3-fpm, nginx, queue worker active; 0 failed jobs; `laravel.log` byte-identical (1,437,201 B / 162 ERROR before and after) |
| Tag | annotated `feature-rme-invoice-correction-receipt-foundation-1-prepayment-foundation-go` (object `44e00813`) → `03fe25b1`, exact match at VPS HEAD. **SCOPED** — not `feature-rme-invoice-correction-receipt-foundation-1-go` |

This evidence commit is documentation only and is deliberately not deployed; the runtime authority stays `03fe25b1`.
