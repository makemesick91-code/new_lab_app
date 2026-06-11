# Sprint 21 Planning — RME Advanced Workflow + Pilot Deployment

**Date:** 2026-06-11
**Planning Branch:** `feature/sprint-21-planning`
**Sprint 20 Baseline Tag:** `sprint-20-rme-core-ui-complete`
**Status:** PLANNING — no feature code added

---

## 1. Executive Summary

Sprint 21 extends the Sprint 20 RME pilot into advanced workflow integration and deployment
readiness. Sprint 20 delivered a complete single-branch RME workflow covering visit queue,
odontogram, handwriting medical record, finalization, cashier billing, full payment, and print.
Sprint 21 builds on this baseline to close the remaining gaps before full production pilot:
connecting RME invoices to the existing lab-order workflow, hardening multi-branch isolation,
preparing PDF exports, designing the installment payment path, adding owner analytics, and
producing a verified VPS deployment checklist. No application behavior is changed until each
phase is coded, reviewed, and tested in sequence.

---

## 2. Sprint 21 Main Goals

| # | Goal | Notes |
|---|---|---|
| 1 | **RME → Lab Integration** | Connect paid RME invoice items to existing `LabOrder` module |
| 2 | **PDF Export Readiness** | Server-side PDF for RME summary, odontogram, and receipt |
| 3 | **Cicilan / Installment Planning** | Design installment payment schedule — implement only if approved |
| 4 | **Owner Dashboard RME Analytics** | Daily visits, revenue, cashier queue, branch comparison |
| 5 | **WhatsApp / Notification Planning** | Templates only; no auto-send until audit rules approved |
| 6 | **Multi-Branch Pilot Hardening** | Branch isolation audit, role audit, seeders, backup rehearsal |
| 7 | **VPS Deployment Checklist** | Verified step-by-step deployment runbook for production pilot |

---

## 3. Sprint 21 Non-Goals

The following are explicitly out of scope for Sprint 21 and must not be implemented:

| Non-Goal | Reason |
|---|---|
| SOAP doctor UI reactivation | Handwriting RM is the primary clinical input; SOAP hidden by design since Sprint 20 |
| Replacement of handwriting RM | Handwriting PNG workflow is intentional and stable |
| Breaking changes to Sprint 20 full-payment flow | Full-payment-only behavior is baseline; installment changes begin only when Phase 21.4 is explicitly approved |
| Uncontrolled `migrate:fresh` on VPS | Never — only `php artisan migrate --force` on production |
| Automatic WhatsApp sending | No automatic sends before templates and audit rules are reviewed and approved |
| New feature code before design is complete | Architecture/design phases must complete before implementation phases begin |

---

## 4. Current Sprint 20 Baseline

| Field | Value |
|---|---|
| **Branch** | `feature/sprint-20-rme-core` |
| **Final closure tag** | `sprint-20-rme-core-ui-complete` |
| **Final merge commit** | `8246008` |
| **Merge tag** | `sprint-20-rme-ui-modernization-merged` |
| **RME test suite** | 283 passed, 718 assertions |
| **Full test suite** | 1842 passed, 6290 assertions |
| **Frontend build** | `npm run build` — success |
| **Code style** | `./vendor/bin/pint --dirty` — passed, no changes |
| **Closure report** | `docs/sprint_20_final_closure_report.md` |
| **Pilot summary** | `docs/sprint_20_rme_limited_pilot_summary.md` |

### Sprint 20 RME Modules (baseline, immutable for Sprint 21 baseline)

| Module | Table(s) | Role |
|---|---|---|
| `ClinicVisit` | `trx_clinic_visits` | Visit queue, status workflow, initial service |
| `MedicalRecord` | `trx_medical_records` | Handwriting PNG primary input; SOAP legacy structured |
| `Odontogram` | `trx_odontograms`, `trx_odontogram_items` | Per-tooth conditions, notes, finalize, print |
| `RmeInvoice` | `trx_rme_invoices`, `trx_rme_invoice_items`, `trx_rme_payments` | Cashier billing, full payment, receipt |

### Sprint 20 Data Integrity Rules (remain in force)

1. Branch isolation via `BranchContext::requireId()` on all RME data.
2. Handwriting PNG mandatory before `finalize_rme`.
3. RME and odontogram are immutable after finalization.
4. SOAP fields hidden from doctor-facing UI.
5. Cashier billing requires finalized RME + `cashier_pending` visit.
6. **Full payment only** — partial payments rejected at service layer.
7. Payment completion sets invoice `PAID`, visit `completed`.
8. RME payments do not touch lab-order payment tables.
9. Initial service is triage-only — no billing.

---

## 5. Proposed Sprint 21 Phases

### Phase 21.1 — RME → Lab Integration Architecture

**Type:** Design / Documentation only  
**Risk:** Medium — touches both RME and LabOrder modules  
**Goal:** Produce a written integration architecture before any code is written.

**Scope:**
- Decide the trigger point for lab case creation.
  - **Recommended trigger:** After `trx_rme_invoices` status becomes `PAID` AND one or more
    `trx_rme_invoice_items` are flagged as requiring lab work (`requires_lab = true`).
  - Alternative: after RME finalization — rejected because lab work should only begin after
    payment is confirmed; billing/payment are the financial gate.
- Map RME patient/doctor/treatment data to existing `LabOrder` module fields.
- Define duplicate prevention strategy (unique key on `rme_invoice_item_id` in `trx_lab_orders`
  or a join table).
- Define branch isolation: lab order must carry the same `branch_id` as the RME visit.
- Define audit trail: status log entry on lab order creation referencing source RME invoice.
- Define ownership: who triggers lab order creation (cashier on payment confirm, or background
  event listener on invoice paid).
- Output: written design doc `docs/sprint_21_1_rme_lab_integration_design.md`.

**Architecture questions to resolve in Phase 21.1:**
- Immediate `LabOrder` creation vs. `LabCaseCandidate` staging table first?
- Who approves: cashier, doctor, or Admin Lab?
- How does the lab-order workflow (DRAFT → RECEIVED → … → COMPLETED) interact with the RME
  visit already being `completed`?

---

### Phase 21.2 — Lab Case Candidate / Lab Order Generation

**Status:** COMPLETE  
**Branch:** `feature/sprint-21-lab-case-candidates`  
**Tag:** `sprint-21-phase-21-2-lab-case-candidates`  
**Date:** 2026-06-11

**Type:** Implementation (tests-first)  
**Risk:** Medium — requires careful scoping to avoid breaking existing LabOrder workflow  
**Depends on:** Phase 21.1 design approved

**Scope implemented:**
- New table `trx_lab_case_candidates` (migration `2026_06_14_210001`).
- New model `App\Modules\LabOrder\Models\LabCaseCandidate` with status constants and relationships.
- New factory `LabCaseCandidateFactory`.
- New service `App\Modules\RmeInvoice\Services\RmeLabIntegrationService` with
  `generateForPaidInvoice()` and `generateForInvoiceItem()`.
- Post-commit hook in `RmePaymentService::pay()` — payment transaction commits first; candidate
  generation runs after in a safe try/catch; generation failure logs a warning without rolling
  back the payment.
- Idempotency via `UNIQUE(rme_invoice_item_id)` and `firstOrCreate` semantics.
- Branch isolation: service validates `invoice.branch_id === BranchContext::id()`.
- 11 new integration tests in `tests/Feature/RME/LabIntegrationTest.php`.

**Preserved from Sprint 20:**
- Full-payment-only rule unchanged.
- `trx_payments` (lab billing) not touched by RME payment.
- No real `LabOrder` created in this phase — staging candidate only.
- SOAP doctor UI remains hidden.

**Test results:** 294 RME tests passed / 742 assertions (283 baseline + 11 new).

---

### Phase 21.3 — RME PDF Export

**Type:** Implementation  
**Risk:** Low — additive; existing browser print is unchanged  
**Depends on:** Phase 21.2 (or can run in parallel after 21.1)

**Scope:**
- Evaluate PDF library already present in `composer.json`; if absent, add `barryvdh/laravel-dompdf`
  or `spatie/browsershot`.
- PDF routes for: RME summary, odontogram export, receipt/invoice export.
- Keep browser `window.print()` as fallback — do not remove existing print views.
- Handwriting PNG must embed correctly in PDF output.
- Do not break existing `rme.visits.print`, `rme.odontograms.print`, `rme.cashier.receipt.show`
  print views.
- Write feature tests for PDF generation (response content-type, HTTP 200, no exception).

---

### Phase 21.4 — Cicilan / Installment Payment Design

**Type:** Design first; implementation only if approved  
**Risk:** High — changes invoice/payment status machine; must not break Sprint 20 full-payment tests  
**Depends on:** Phase 21.2 complete; explicit approval to implement

**Scope (design):**
- Installment schedule: 3 payments over 3 months (configurable).
- Payment status expansion: add `PARTIALLY_PAID` to `trx_rme_invoices` status (mirrors lab
  `Invoice` module pattern).
- Outstanding balance tracking per installment schedule.
- Receipt per installment payment (not just final receipt).
- Late payment / overdue handling design.

**Scope (implementation — approval-gated):**
- Migration: `trx_rme_installment_schedules` table (invoice_id, due_date, amount, paid_at).
- Service: `recordInstallmentPayment()` alongside existing `recordFullPayment()`.
- Policy gate: installment mode vs. full-payment mode determined by invoice creation, not after.
- Do not change the existing `recordFullPayment()` path — Sprint 20 full-payment tests must
  continue to pass.

---

### Phase 21.5 — Owner Dashboard RME Analytics

**Type:** Implementation (read-only aggregation)  
**Risk:** Low — no schema changes; read-only queries on existing tables  
**Depends on:** Phase 21.2 (for lab-queued counts); can start on read-only metrics independently

**Scope:**
- Dashboard metrics (daily, weekly, monthly, by branch):
  - Daily visit count (total, by status)
  - Completed RME count
  - Cashier pending count
  - Paid invoices count + total revenue
  - Revenue from RME (separate from lab revenue)
  - Treatments requiring lab (if Phase 21.2 done)
  - Branch comparison (multi-branch owners)
  - Doctor / treatment productivity (visits per doctor, top treatments)
- Read-only — no writes. Use `Reporting` module pattern (no new tables unless summary tables
  needed for performance).
- Gate with `view_clinic_visits` permission for read access.

---

### Phase 21.6 — WhatsApp / Notification Planning

**Type:** Design / Templates only  
**Risk:** Low (design phase); High (implementation — external API, audit requirements)  
**Depends on:** Phase 21.7 pilot hardening complete; explicit send-approval from stakeholder

**Scope (design + template):**
- Define message templates for:
  - Invoice unpaid reminder
  - Lab order ready for pickup notification
  - Follow-up appointment reminder
  - Payment confirmation
- Design: manual send first vs. queued automatic send.
- Audit log requirement: every WhatsApp send must be logged (template used, recipient, timestamp,
  triggered by).
- Use existing `WaReminderTemplate` module (already exists under `app/Modules/WaReminderTemplate`).
- No automatic sending until: templates approved, audit log implemented, stakeholder sign-off.

---

### Phase 21.7 — Multi-Branch Pilot Hardening

**Type:** Audit + Hardening  
**Risk:** Medium — cross-branch data leaks are security bugs  
**Depends on:** Sprint 20 baseline; should run before VPS deployment

**Scope:**
- Branch isolation audit: verify all RME controllers/services/repositories apply
  `BranchContext::requireId()` and branch-scoped queries.
- User role audit: verify roles/permissions match intended access for each branch staff type.
- Seeders: create or verify pilot data seeders for multi-branch testing.
- Backup/restore rehearsal: dry-run `php artisan rme:import-pilot-backup` on staging.
- Production safety checklist: document per-branch user setup, permission verification,
  branch-data isolation smoke test.
- Add or verify branch-isolation tests for RME: `tests/Feature/RME/BranchIsolationTest`.

---

### Phase 21.8 — VPS Pilot Deployment Checklist

**Type:** Documentation + Runbook  
**Risk:** Low (documentation); Critical (execution — any deviation on production is dangerous)  
**Depends on:** Phase 21.7 hardening complete

**Scope — verified step-by-step runbook:**

```
1.  Confirm current VPS database backup (pg_dump before anything else)
2.  git fetch origin && git checkout feature/sprint-20-rme-core (or sprint-21 when ready)
3.  git pull origin <branch>
4.  composer install --no-dev --optimize-autoloader
5.  npm ci && npm run build
6.  php artisan migrate --force           (NEVER migrate:fresh on production)
7.  php artisan db:seed --class=PermissionSeeder --force   (only if new permissions added)
8.  php artisan db:seed --class=RoleSeeder --force          (only if roles changed)
9.  php artisan optimize:clear
10. php artisan config:cache
11. php artisan route:cache
12. php artisan view:cache
13. sudo chown -R www-data:www-data storage/ bootstrap/cache/
14. sudo chmod -R 775 storage/ bootstrap/cache/
15. Smoke test: login → RME visits → cashier queue → payment → receipt
16. Smoke test: inventory dashboard
17. Rollback plan: restore pg_dump backup; git checkout <previous tag>
```

**Hard rules:**
- Never `migrate:fresh` on VPS.
- Always backup database before deploy.
- Keep `APP_URL` and `APP_ENV=production` correct.
- Validate: login, RME visit create, cashier billing, payment, receipt print, inventory dashboard.

---

## 6. Recommended Implementation Order

The following order minimizes risk by completing design before implementation and hardening
before deployment:

| Step | Phase | Type | Risk |
|---|---|---|---|
| 1 | **21.1** — RME → Lab Integration Architecture | Design | Medium |
| 2 | **21.2** — Lab Order Generation | Implementation (tests-first) | Medium |
| 3 | **21.7** — Multi-Branch Pilot Hardening | Audit + Hardening | Medium |
| 4 | **21.8** — VPS Deployment Checklist | Documentation | Critical |
| 5 | **21.3** — RME PDF Export | Implementation | Low |
| 6 | **21.5** — Owner Dashboard RME Analytics | Implementation | Low |
| 7 | **21.4** — Cicilan / Installment (if approved) | Design → Implementation | High |
| 8 | **21.6** — WhatsApp / Notification Planning | Design | Low/High |

**Rationale:** Lab integration (21.1 → 21.2) is the highest-value new feature and should go
first while the RME context is freshest. Hardening (21.7) and the deployment checklist (21.8)
must happen before any production push. PDF (21.3) and analytics (21.5) are low-risk additive
features. Installment (21.4) and WhatsApp (21.6) are deferred because they carry the highest
risk of regressions and external dependency.

---

## 7. Data Model Planning Notes

No migrations should be written until the design phase is approved. The following are design
notes only.

### RME Invoice Item → Lab Order Link

```
trx_rme_invoice_items
  + requires_lab (boolean, default false)

trx_lab_orders (existing)
  + rme_invoice_item_id (nullable FK → trx_rme_invoice_items)
  + source_type (enum: 'rme', 'direct', nullable)
```

Duplicate prevention: `UNIQUE(rme_invoice_item_id)` on `trx_lab_orders` (only one lab order
per RME invoice item).

### Branch Consistency

All new records created from RME context must carry the same `branch_id` as the originating
`trx_clinic_visits.branch_id`. Never derive branch from the HTTP session alone — resolve via
`BranchContext::requireId()` in the service layer.

### Installment Schedule (design, approval-gated)

```
trx_rme_installment_schedules
  - id
  - rme_invoice_id (FK → trx_rme_invoices)
  - installment_number (1, 2, 3)
  - due_date (date)
  - amount (decimal 15,2)
  - paid_at (nullable timestamp)
  - payment_method
  - branch_id
  - created_by
```

### Audit Logs

All state-changing operations (lab order created from RME, installment recorded, WhatsApp sent)
should write to `sys_audit_logs` referencing `entity_type` and `entity_id` per the existing
morph-map pattern.

### Treatment `requires_lab` Flag

Current `trx_rme_invoice_items` stores treatment line items. Adding `requires_lab` at the item
level (not treatment master) allows per-visit override and avoids coupling to the treatment
category master data.

---

## 8. Test Strategy

| Test Scope | Strategy | Notes |
|---|---|---|
| RME integration (existing) | Run `php artisan test --filter=RME` as gate | Must stay 283+ passed |
| LabOrder integration | New feature tests before any lab-integration code | Covers create, duplicate prevention, branch isolation |
| Branch isolation | `tests/Feature/RME/BranchIsolationTest` (new or extend existing) | Cross-branch visit/invoice/lab access must 403 |
| Duplicate prevention | Unit + feature tests: same `rme_invoice_item_id` must not create second lab order | Test idempotency |
| Payment non-regression | Sprint 20 full-payment tests must pass throughout | Run `--filter=RME` after every change |
| UI smoke tests | Manual UAT checklist per phase (see Phase 21.7) | Browser-based; not automated |
| Full suite gate | `php artisan test` must stay green before each commit | 1842+ passed target |

### Test-First Rule

Phase 21.2 (lab order generation) and Phase 21.4 (installment payment) must have feature tests
written **before** any service or controller code is written. Do not write implementation until
the failing test exists.

---

## 9. Deployment Safety Rules

These rules are non-negotiable and apply to every sprint going forward:

1. **Never `migrate:fresh` on VPS** — this destroys all production data.
2. **Always backup before deploy** — `pg_dump` before any `git pull` on production.
3. **`php artisan migrate --force` only** — runs pending migrations; never resets.
4. **Seed only required seeders** — `PermissionSeeder`, `RoleSeeder` when permissions/roles
   change; never `DatabaseSeeder` on production.
5. **Keep `APP_URL` correct** — mismatch causes redirect loops and CSRF failures.
6. **Fix storage/cache permissions** — `chown www-data storage/ bootstrap/cache/` after every
   deploy.
7. **Run smoke tests** — validate login, RME visit create, cashier billing, payment, receipt
   print, inventory dashboard before declaring deploy complete.
8. **Rollback plan must exist** — restore pg_dump backup; git checkout previous tag; restart
   queue workers.
9. **Do not deploy untagged branches** — only deploy tagged closure commits to production.
10. **Queue worker restart** — `php artisan queue:restart` after every deploy if queues are
    running.

---

## 10. Sprint 21 Backlog Table

| Priority | Phase | Item | Type | Risk | Dependencies | Acceptance Criteria |
|---|---|---|---|---|---|---|
| P0 | 21.1 | RME → Lab integration architecture design | Design | Medium | Sprint 20 baseline | Written design doc approved; trigger point decided; duplicate prevention strategy defined |
| P0 | 21.2 | `requires_lab` flag on `trx_rme_invoice_items` | Migration + Implementation | Low | 21.1 design | Migration exists; backfill false; field fillable; tests pass |
| P0 | 21.2 | Lab order creation from paid RME invoice | Implementation | Medium | 21.1 design, `requires_lab` | Lab order created on payment; no duplicate on retry; branch matches visit |
| P0 | 21.2 | Duplicate lab order prevention | Implementation + Test | Medium | Lab order creation | UNIQUE constraint; idempotency test passes |
| P1 | 21.7 | Branch isolation audit — RME module | Audit | Medium | Sprint 20 baseline | All RME services/repos verified; branch-isolation tests pass |
| P1 | 21.7 | Multi-branch pilot role/permission audit | Audit | Medium | Branch isolation | Each role has correct permissions; no over-privilege |
| P1 | 21.8 | VPS pilot deployment runbook | Documentation | Critical | 21.7 hardening | Step-by-step doc verified on staging; rollback plan present |
| P2 | 21.3 | RME PDF export — RME summary | Implementation | Low | Sprint 20 print views | PDF route returns valid PDF; print view unchanged |
| P2 | 21.3 | RME PDF export — odontogram | Implementation | Low | 21.3 RME summary | PDF includes odontogram; handwriting PNG embedded |
| P2 | 21.3 | RME PDF export — receipt | Implementation | Low | 21.3 RME summary | Receipt PDF route; existing receipt unchanged |
| P2 | 21.5 | Owner RME analytics dashboard | Implementation | Low | Sprint 20 baseline | Daily/monthly visit, revenue, cashier pending metrics visible to owner |
| P2 | 21.5 | Branch comparison analytics | Implementation | Low | 21.5 basic analytics | Multi-branch owners see branch-level comparison |
| P3 | 21.4 | Installment payment design | Design | High | 21.2 complete; approval | Written design doc; status expansion plan; no regression test failures |
| P3 | 21.4 | Installment implementation (approval-gated) | Implementation | High | 21.4 design approved | Installment path works; full-payment tests still pass |
| P3 | 21.6 | WhatsApp template design | Design | Low | 21.7 hardening | Templates documented; audit log requirement defined |
| P3 | 21.6 | WhatsApp manual send implementation (approval-gated) | Implementation | High | 21.6 templates approved | Manual send works; audit log written; no auto-send |

---

## 11. Definition of Done

A Sprint 21 phase is complete when **all** of the following are true:

- [ ] All new and modified feature tests pass (`php artisan test`)
- [ ] RME test suite passes without regression (`--filter=RME`, 283+ passed)
- [ ] Full test suite passes (1842+ passed)
- [ ] No branch leak — no cross-branch data accessible
- [ ] No duplicate lab orders — idempotency verified by test
- [ ] No payment regression — Sprint 20 full-payment tests pass
- [ ] `./vendor/bin/pint --dirty` passes (no style errors)
- [ ] `npm run build` succeeds
- [ ] Deploy checklist verified (for Phase 21.8)
- [ ] Documentation updated (`docs/sprint_history.md`, phase doc)
- [ ] Commit tagged with phase tag

---

## 12. Open Questions

The following questions must be answered before Phase 21.2 implementation begins:

| # | Question | Suggested Answer | Owner |
|---|---|---|---|
| 1 | Should lab order be created after billing (invoice created) or after payment (invoice PAID)? | **After payment** — lab work should only proceed once payment is confirmed | Stakeholder + Tech Lead |
| 2 | Should lab integration create a real `LabOrder` immediately, or a `LabCaseCandidate` staging record first? | Immediate `LabOrder` in DRAFT status; simpler and avoids a new table | Tech Lead |
| 3 | Who owns lab order approval: cashier, doctor, or Admin Lab? | Admin Lab approves lab order (existing `manage_lab_orders` permission) | Stakeholder |
| 4 | Will cicilan (installment) be included in Sprint 21 or deferred to Sprint 22? | Design in Sprint 21; implementation in Sprint 22 unless explicitly approved | Stakeholder |
| 5 | Should WhatsApp be manual-send first before any automated sending? | **Yes** — manual-send only in Sprint 21; automation in Sprint 22+ after audit approval | Stakeholder |
| 6 | Is DomPDF already present in `composer.json`? | Check `composer show barryvdh/laravel-dompdf` before Phase 21.3 begins | Tech Lead |
| 7 | Should the `requires_lab` flag live on `trx_rme_invoice_items` or on the treatment master? | Per-item flag on `trx_rme_invoice_items` — allows per-visit override | Tech Lead |
| 8 | Should multi-branch RME visits and analytics be fully isolated or allow owner-level cross-branch read? | Owner-level read-only cross-branch analytics allowed; write operations remain branch-isolated | Stakeholder |

---

## 13. Recommended First Coding Phase

### Phase 21.1 — Architecture/Design Only (no code)

Phase 21.1 must be completed as a **written design document** before any implementation begins.
Output: `docs/sprint_21_1_rme_lab_integration_design.md`.

The design document must resolve:
- Trigger point (after payment confirmed)
- Data mapping (RME visit/patient/doctor/treatment → LabOrder fields)
- Duplicate prevention mechanism
- Branch isolation guarantee
- Audit trail approach
- Ownership (who can create/approve)

No service, controller, migration, or test code should be written until Phase 21.1 design is
reviewed and approved.

---

## 14. Phase 21.1 — Completion Note

**Status:** COMPLETE (2026-06-11)  
**Branch:** `feature/sprint-21-rme-lab-architecture`  
**Tag:** `sprint-21-phase-21-1-rme-lab-architecture`  
**Document:** `docs/sprint_21_rme_lab_integration_architecture.md`  
**Type:** Design / Documentation only — no application code changed

**Key decisions made in Phase 21.1:**

| Decision | Resolution |
|---|---|
| Integration trigger | After `RmePaymentService::pay()` sets invoice to `PAID` (post-payment) |
| Creation strategy | `LabCaseCandidate` staging table first (Option B) |
| Eligibility filter | `mst_treatments.requires_lab = true` — column already exists |
| Idempotency key | `UNIQUE(rme_invoice_item_id)` on `trx_lab_case_candidates` |
| Transaction strategy | Payment commits first; candidate generation is post-commit (Option 2) |
| Branch isolation | `branch_id` copied from RME invoice; validated against `BranchContext` |
| Lab payment records | Must not be created from RME payment — `trx_rme_payments` only |
| LabOrder mapping gap | `LabOrderItem` uses `lab_service_id`; RME items use `treatment_id` — no mapping exists; resolving deferred to Phase 21.2 |

**Phase 21.2 is COMPLETE.** See `docs/sprint_history.md` for the full Phase 21.2 entry.
See `docs/sprint_21_rme_lab_integration_architecture.md` for architecture detail.

---

*This document is planning-only. No application behavior, controllers, models, migrations,
routes, policies, seeders, factories, tests, or Blade views were modified as part of this
planning phase. Sprint 20 behavior is fully preserved. This document should be updated as each
Sprint 21 phase begins and completes.*

---

## 15. Phase 21.3 — Admin Lab Candidate Queue UI

**Status:** COMPLETE (2026-06-11)
**Branch:** `feature/sprint-21-lab-candidate-queue`
**Tag:** `sprint-21-phase-21-3-lab-candidate-queue`

### Goal

Create a read-only Admin Lab queue UI for `LabCaseCandidate` records generated
from paid RME invoices. Phase 21.4 will add conversion to `LabOrder`.

### Files Added

| File | Purpose |
|---|---|
| `tests/Feature/RME/LabCaseCandidateQueueTest.php` | 12 TDD tests covering auth, branch isolation, filters, sidebar |
| `app/Modules/LabOrder/Policies/LabCaseCandidatePolicy.php` | viewAny + view with branch isolation |
| `app/Modules/LabOrder/Controllers/LabCaseCandidateController.php` | index + show, branch-scoped |
| `resources/views/lab/case-candidates/index.blade.php` | TailAdmin-style paginated queue |
| `resources/views/lab/case-candidates/show.blade.php` | Candidate detail page |

### Files Modified

| File | Change |
|---|---|
| `app/Providers/RepositoryServiceProvider.php` | Registered `LabCaseCandidatePolicy` |
| `routes/web.php` | Added `lab-case-candidates.index` and `lab-case-candidates.show` |
| `resources/views/layouts/partials/sidebar.blade.php` | Added "Kandidat Lab RME" menu item |

### Routes

| Name | URL | Permission |
|---|---|---|
| `lab-case-candidates.index` | `GET /lab/case-candidates` | `view_lab_orders\|manage_lab_orders` |
| `lab-case-candidates.show` | `GET /lab/case-candidates/{candidate}` | `view_lab_orders\|manage_lab_orders` |

### Authorization

- Reuses existing `view_lab_orders` / `manage_lab_orders` permissions (no new permissions).
- Admin Lab role already holds both. Super Admin bypasses via `Gate::before`.
- Branch isolation: `LabCaseCandidatePolicy::view()` checks `candidate->branch_id === BranchContext::forUser($user)`.

### Scope Boundary

- **Read-only**: no conversion to `LabOrder`, no mutation endpoints.
- Phase 21.4 will implement the conversion action.

### Test Results

| Suite | Result |
|---|---|
| `php artisan test --filter=LabCaseCandidateQueue` | 12 passed, 26 assertions |
| `php artisan test --filter=LabIntegration` | 11 passed |
| `php artisan test --filter=RmePayment` | 16 passed |
| `php artisan test --filter=RME` | 306 passed |

---

## 16. Phase 21.4 — Convert LabCaseCandidate to LabOrder

**Status:** COMPLETE (2026-06-11)
**Branch:** `feature/sprint-21-candidate-to-laborder`
**Tag:** `sprint-21-phase-21-4-candidate-to-laborder`

### Goal

Explicit manual conversion from one `pending_review` `LabCaseCandidate` into a real `LabOrder`.
RME payment still creates candidates only — never `LabOrder`.

### Files Added

| File | Purpose |
|---|---|
| `tests/Feature/RME/LabCaseCandidateConversionTest.php` | 16 TDD tests for conversion, idempotency, branch isolation, RME preservation |
| `app/Modules/LabOrder/Services/LabCaseCandidateConversionService.php` | Conversion business rules + transaction |
| `app/Modules/LabOrder/Requests/ConvertLabCaseCandidateRequest.php` | Validates `lab_service_id`, `due_date`, optional notes/qty |

### Files Modified

| File | Change |
|---|---|
| `app/Modules/LabOrder/Models/LabCaseCandidate.php` | Helpers + `convertedLabOrder()` relation |
| `app/Modules/LabOrder/Policies/LabCaseCandidatePolicy.php` | Added `convert` ability |
| `app/Modules/LabOrder/Controllers/LabCaseCandidateController.php` | `convert` action + lab services for show form |
| `resources/views/lab/case-candidates/show.blade.php` | Conversion form + converted order link |
| `routes/web.php` | `POST lab-case-candidates.convert` |
| `tests/Feature/RME/LabCaseCandidateQueueTest.php` | Updated phase 21.3 read-only guard test |

### Routes

| Name | URL | Permission |
|---|---|---|
| `lab-case-candidates.convert` | `POST /lab/case-candidates/{candidate}/convert` | `create_lab_orders\|manage_lab_orders` |

### Conversion Rules

- `lab_service_id` required explicitly — no inference from `treatment_id`.
- Inactive lab services rejected.
- Idempotent: already-converted candidate returns existing `LabOrder`.
- Branch isolation via `BranchContext::requireId()`.
- No `trx_invoices`, `trx_payments`, or lab billing records created.
- RME invoice/payment/visit status unchanged by conversion.

### Authorization

- Reuses `create_lab_orders` / `manage_lab_orders` — no new permissions.
- Show form gated by `@can('convert', $candidate)` (pending + branch + create permission).

### Test Results

| Suite | Result |
|---|---|
| `php artisan test --filter=LabCaseCandidateConversion` | 16 passed |
| `php artisan test --filter=LabCaseCandidateQueue` | 12 passed |
| `php artisan test --filter=LabIntegration` | 11 passed |
| `php artisan test --filter=RME` | All passed |
| `php artisan test` | All passed |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |
| `php artisan test` (full suite) | All passed |

---

## 17. Phase 21.5 — RME Lab Workflow Polish

**Status:** COMPLETE (2026-06-11)
**Branch:** `feature/sprint-21-rme-lab-workflow-polish`
**Tag:** `sprint-21-phase-21-5-rme-lab-workflow-polish`

### Goal

Polish UI/visibility only — help clinic and lab staff trace RME invoice → lab candidate → lab order without changing Sprint 20/21 business rules.

### Files Added

| File | Purpose |
|---|---|
| `tests/Feature/RME/RmeLabWorkflowPolishTest.php` | 16 tests for visibility, auth, branch isolation, regression guards |
| `resources/views/components/rme/lab-workflow-panel.blade.php` | Shared read-only lab workflow summary for invoice/receipt |

### Files Modified

| File | Change |
|---|---|
| `app/Modules/RmeInvoice/Models/RmeInvoice.php` | `labCaseCandidates()` relation |
| `app/Modules/LabOrder/Models/LabOrder.php` | `rmeLabCaseCandidate()` relation |
| `app/Modules/LabOrder/Models/LabCaseCandidate.php` | Display helpers (`statusLabel`, `statusTone`, etc.) |
| `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php` | Eager-load candidates for show |
| `app/Modules/RmeInvoice/Controllers/RmePaymentController.php` | Eager-load candidates for receipt |
| `app/Modules/LabOrder/Controllers/LabCaseCandidateController.php` | Eager-load `convertedLabOrder`, `reviewedBy` |
| `app/Modules/LabOrder/Controllers/LabOrderController.php` | Pass `rmeSourceCandidate` to show view |
| `resources/views/rme/cashier/show.blade.php` | Lab workflow section |
| `resources/views/rme/cashier/receipt/show.blade.php` | Compact lab workflow section |
| `resources/views/lab/case-candidates/index.blade.php` | Lab Order column + status badges |
| `resources/views/lab/case-candidates/show.blade.php` | Invoice link, conversion metadata, pending state |
| `resources/views/lab-orders/show.blade.php` | Sumber RME section |

### Authorization

- Candidate links: `@can('view', $candidate)` (`view_lab_orders | manage_lab_orders` + branch)
- Lab order links: `@can('view', $labOrder)`
- RME invoice links: `@can('view', $rmeInvoice)` (`manage_rme_billing` + branch)
- No new permissions

### Test Results

| Suite | Result |
|---|---|
| `php artisan test --filter=RmeLabWorkflowPolish` | 16 passed |
| `php artisan test --filter=LabCaseCandidateConversion` | 16 passed |
| `php artisan test --filter=LabCaseCandidateQueue` | 12 passed |
| `php artisan test --filter=LabIntegration` | 11 passed |
| `php artisan test --filter=RME` | All passed |
| `php artisan test` | All passed |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |

---

## 18. Phase 21.6 — RME PDF Export / Print Hardening

**Status:** COMPLETE (2026-06-11)
**Branch:** `feature/sprint-21-rme-pdf-print-hardening`
**Tag:** `sprint-21-phase-21-6-rme-pdf-print-hardening`

### Goal

Harden RME visit print and receipt print outputs for pilot use. Add optional PDF download using existing `barryvdh/laravel-dompdf` infrastructure.

### Files Added

| File | Purpose |
|---|---|
| `tests/Feature/RME/RmePdfPrintHardeningTest.php` | 21 tests — print/PDF auth, content, branch isolation, no side effects |
| `resources/views/rme/visits/partials/print-body.blade.php` | Shared print-safe RME bundle content |
| `resources/views/rme/visits/print-pdf.blade.php` | DomPDF view for visit PDF export |

### Files Modified

| File | Change |
|---|---|
| `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` | Eager-load print relations; `pdf()` export; shared `resolvePrintViewData()` |
| `resources/views/rme/visits/print.blade.php` | Print hardening — branch, initial treatment, invoice/payment, lab workflow; SOAP hidden |
| `resources/views/rme/cashier/receipt/show.blade.php` | Lab workflow visible on print (`print-lab-workflow`) |
| `resources/views/components/rme/lab-workflow-panel.blade.php` | Print break-inside-avoid |
| `routes/web.php` | `rme.visits.pdf` route |
| `tests/Feature/RME/ClinicVisitTest.php` | Print test expects Final status, not legacy SOAP |

### Print bundle now includes

- Patient / visit identity, branch, clinic, doctor
- Initial treatment (when set)
- Medical record finalization metadata + handwriting preview (SOAP fields hidden)
- Odontogram summary
- Paid RME invoice / payment summary (when available)
- Lab case candidate status + converted LabOrder reference (when available)

### PDF export

- Route: `GET /rme/visits/{clinicVisit}/pdf` (`rme.visits.pdf`)
- Package: existing `barryvdh/laravel-dompdf` (same pattern as stock transfer checklist)
- Authorization: same as `rme.visits.print` (`ClinicVisitPolicy::print`)
- No record mutations on download

### Phase boundaries preserved

- No RME payment / candidate generation / conversion logic changes
- No auto LabOrder from RME payment
- No lab invoice/payment records
- No cicilan, WhatsApp, SOAP doctor UI, or migrations

### Test Results

| Suite | Result |
|---|---|
| `php artisan test --filter=RmePdfPrintHardening` | 21 passed |
| `php artisan test --filter=RmeLabWorkflowPolish` | 16 passed |
| `php artisan test --filter=RME` | All passed |
| `php artisan test` | All passed |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |
