# Sprint 22 Planning - Pilot Stabilization, Access Hardening & Owner Dashboard Foundation

**Date:** 2026-06-11  
**Planning Branch:** `feature/sprint-22-planning`  
**Release Baseline Branch:** `release/sprint-21-rme-advanced-workflow`  
**Release Baseline Commit:** `3ef3fd6` - Add Kasir RME sidebar menu hotfix  
**Sprint 21 RC Tag:** `sprint-21-release-candidate`  
**Sprint 21.9 Hotfix Tag:** `sprint-21-phase-21-9-cashier-rme-sidebar-hotfix`  
**Status:** PLANNING - documentation only; no application behavior changes

---

## 1. Purpose

Sprint 22 stabilizes the live Hostinger VPS pilot after the Sprint 21 RME Advanced Workflow deployment. The sprint focuses on controlled pilot hardening: role and menu visibility, safe RME smoke-test data, cashier/payment/receipt workflow checks, RME-to-lab candidate validation, and the first read-only Owner Dashboard foundation.

The sprint must preserve all Sprint 20 and Sprint 21 clinical, payment, and lab-integration boundaries. Sprint 22 starts from the deployed Sprint 21 release branch and does not change payment behavior, does not auto-create Lab Orders from RME payment, and does not bypass the `LabCaseCandidate` staging layer.

---

## 2. Current Baseline From Sprint 21

| Item | Baseline |
|---|---|
| VPS pilot status | Sprint 21 RME Advanced Workflow deployed and live on Hostinger VPS pilot |
| Release branch | `release/sprint-21-rme-advanced-workflow` |
| Latest deployed hotfix | `3ef3fd6` - Add Kasir RME sidebar menu hotfix |
| Release candidate tag | `sprint-21-release-candidate` |
| Hotfix tag | `sprint-21-phase-21-9-cashier-rme-sidebar-hotfix` |
| Lab integration strategy | Paid RME invoice creates `LabCaseCandidate` staging records only |
| LabOrder conversion | Manual Admin Lab conversion from pending candidate to `LabOrder` |
| Payment boundary | RME payment writes `trx_rme_payments` only; no lab payment records |
| Clinical input boundary | Handwriting RM remains primary; SOAP doctor UI remains hidden |

### Sprint 21 Capabilities Now In Pilot

- RME PDF export and print hardening.
- RME cashier billing, full payment, and receipt workflow.
- `LabCaseCandidate` staging table.
- RME paid invoice item to lab candidate generation.
- Admin Lab candidate queue.
- Manual candidate conversion to `LabOrder`.
- VPS pilot deployment checklist and release candidate baseline.
- Sprint 21.9 hotfix for the missing "Kasir RME" sidebar menu.

---

## 3. Sprint 22 Scope

Sprint 22 covers pilot stabilization and read-only monitoring foundations:

1. Confirm role, permission, and sidebar visibility for RME admin, doctor, cashier, Admin Lab, owner, and super-admin users.
2. Provide safe RME smoke-test data and operator workflow checks for pilot validation.
3. Validate the RME cashier flow from invoice to full payment, receipt/PDF output, and completed visit status.
4. Validate the RME paid invoice to `LabCaseCandidate` path and manual candidate conversion to `LabOrder`.
5. Start an Owner Dashboard as a read-only executive monitoring surface.
6. Prepare a conservative VPS pilot hardening and release candidate process for Sprint 22.

---

## 4. Explicit Out Of Scope

| Out-of-scope item | Reason |
|---|---|
| `migrate:fresh`, `db:wipe`, or destructive VPS reset | VPS pilot is live; only safe deploy/migrate steps are allowed |
| Destructive migrations | Sprint 22 must be pilot-safe and additive when later implementation is approved |
| Changing RME payment behavior | Full-payment-only pilot behavior remains the baseline |
| Partial payment / cicilan implementation | Deferred unless explicitly planned in a later approved phase |
| Auto-creating `LabOrder` directly from RME payment | `LabCaseCandidate` remains the staging layer |
| Creating lab payment records from RME payment | RME payment must not write lab billing/payment tables |
| Showing SOAP fields in doctor UI | Handwriting-first clinical record remains the approved pilot workflow |
| Owner Dashboard write actions | Sprint 22 dashboard is read-only only |
| Broad UI rewrites or navigation redesign | Sprint 22 hardens existing access and menu behavior only |
| Large test/build runs inside Codex | Heavy verification must be run manually from Ubuntu Terminal when needed |

---

## 5. Proposed Phases

### Phase 22.0 - Planning & Baseline

**Type:** Documentation / planning only  
**Goal:** Establish Sprint 22 scope, baseline, constraints, and phase order.

**Deliverables:**
- `docs/sprint_22_planning.md`
- Sprint 22 entry in `docs/sprint_history.md`
- Current-focus memory in `CLAUDE.md`

**Boundaries:**
- No routes, controllers, services, models, migrations, policies, Blade views, seeders, tests, or dependency files changed.
- Lightweight verification only.

### Phase 22.1 - Pilot Role/Permission/Menu Hardening

**Type:** Implementation, small scoped patches  
**Goal:** Ensure pilot users see exactly the RME, cashier, lab candidate, and owner/admin menus they are allowed to use.

**Scope:**
- Audit role permissions for RME admin, doctor, cashier, Admin Lab, owner, and super-admin.
- Validate sidebar gates for "Kasir RME", clinic visits, lab candidates, RME receipt/PDF access, and owner dashboard entry points.
- Add focused tests for role access, forbidden paths, and branch isolation where relevant.

**Preserved rules:**
- Do not remove policy checks.
- Do not expose cross-branch records.
- Do not broaden owner access beyond read-only dashboard and approved monitoring routes.

### Phase 22.2 - RME End-to-End Smoke Test Data

**Type:** Pilot support / tests-first implementation  
**Goal:** Provide a safe, repeatable smoke-test path for pilot operators without risking production data.

**Scope:**
- Define a smoke-test checklist for patient, visit, doctor handwriting, odontogram, cashier, payment, receipt, and lab candidate validation.
- If code is approved, add safe test data tooling that is explicit, branch-scoped, and not destructive.
- Ensure any data setup avoids `migrate:fresh` and avoids wiping pilot data.

**Manual pilot checks:**
- Admin creates a visit.
- Doctor completes handwriting RM and odontogram.
- RME finalization sends visit to cashier.
- Cashier creates invoice, records full payment, and prints/downloads receipt as allowed.

### Phase 22.3 - RME Cashier + Payment + Receipt Stabilization

**Type:** Implementation, bugfix-only unless separately approved  
**Goal:** Stabilize cashier usage after the live pilot deployment.

**Scope:**
- Verify cashier access to pending RME billing, invoice show, payment action, receipt page, and PDF/print paths.
- Harden empty states, validation messages, and permission denials.
- Preserve current full-payment-only service behavior.

**Out of scope for this phase:**
- Partial payment.
- New payment status machine.
- Lab billing records.

### Phase 22.4 - RME -> Lab Candidate End-to-End Validation

**Type:** Implementation / validation  
**Goal:** Confirm the paid RME invoice to lab candidate workflow is reliable under pilot usage.

**Scope:**
- Validate candidate generation for paid invoice items requiring lab work.
- Validate idempotency: no duplicate candidate for the same RME invoice item.
- Validate branch isolation and candidate visibility.
- Validate manual conversion from candidate to `LabOrder` by authorized Admin Lab users.

**Preserved rules:**
- RME payment creates candidates only.
- Admin Lab selects `lab_service_id` explicitly during conversion.
- No lab invoice/payment records are created by RME payment.

### Phase 22.5 - Owner Dashboard Foundation

**Type:** Read-only implementation  
**Goal:** Introduce a minimal Owner Dashboard shell and first safe executive metrics.

**Scope:**
- Read-only dashboard route/view gated for owner/admin visibility.
- Initial KPI cards from existing branch-scoped data.
- Conservative queries for pilot monitoring, such as visits today, cashier pending, paid RME invoices, and pending lab candidates.

**Boundaries:**
- No writes.
- No destructive migrations.
- No cross-branch leakage.
- No operational actions from dashboard cards.

### Phase 22.6 - Owner Dashboard KPI Detail

**Type:** Read-only implementation  
**Goal:** Expand Owner Dashboard detail after the foundation is verified.

**Scope:**
- Date range filters.
- Branch comparisons for authorized owner/super-admin users.
- RME revenue detail.
- Visit status breakdown.
- Lab candidate status breakdown.

**Risk controls:**
- Keep metrics read-only.
- Prefer existing repositories/services where available.
- Add authorization and branch-isolation tests for every dashboard data source.

### Phase 22.7 - VPS Pilot Hardening & Release Candidate

**Type:** Documentation + release hardening  
**Goal:** Prepare Sprint 22 for safe VPS pilot update and release candidate tagging.

**Scope:**
- Update deployment checklist for Sprint 22 deltas.
- Confirm DB backup, `git pull`, `php artisan migrate --force` if needed, cache clear, permissions, and smoke checks.
- Define release branch/tag and rollback point.
- Document manual post-deploy smoke-test commands and operator checks.

**VPS rule:** Never use `migrate:fresh` or `db:wipe` on VPS.

---

## 6. Risk List

| Risk | Mitigation |
|---|---|
| Pilot users cannot see required menus | Phase 22.1 audits roles, permissions, and sidebar gates with focused tests |
| Owner dashboard leaks cross-branch data | Use `BranchContext` and explicit authorization tests before release |
| Smoke-test data pollutes live pilot data | Use explicit branch-scoped setup and avoid destructive resets |
| RME payment changes break cashier workflow | Do not change payment behavior unless a later phase explicitly plans it |
| Duplicate lab candidates appear after payment retries | Preserve idempotency on `rme_invoice_item_id` and add validation tests |
| Dashboard queries become slow | Start with small read-only KPIs and add date filters/index review only when evidence requires it |
| VPS deploy damages pilot data | Backup first, use `migrate --force` only, and keep rollback tag documented |

---

## 7. Acceptance Criteria

Sprint 22 is acceptable when:

1. Role, permission, and menu visibility for RME, cashier, lab candidate, owner, and admin users are verified.
2. Pilot operators have a clear RME end-to-end smoke-test path.
3. Cashier invoice, full payment, receipt, and PDF/print flows remain stable.
4. Paid RME invoices still generate `LabCaseCandidate` records only when lab work is required.
5. Manual candidate conversion to `LabOrder` remains authorized, branch-scoped, and explicit.
6. Owner Dashboard foundation is read-only and protected by appropriate permissions.
7. VPS deployment notes include backup, safe migration, cache, permission, smoke test, and rollback steps.
8. Sprint 20/21 constraints remain intact: handwriting-first RME, SOAP hidden, full payment only, no auto LabOrder, and no lab payment records from RME payment.

---

## 8. Testing Strategy

Testing will scale by phase:

- Phase 22.0: Documentation diff review only; no full suite.
- Phase 22.1: Focused Pest tests for role/permission/menu visibility and forbidden access.
- Phase 22.2: Smoke-test checklist plus focused feature tests for setup/workflow support if code is added.
- Phase 22.3: Focused RME cashier/payment/receipt tests.
- Phase 22.4: Focused lab candidate generation, idempotency, branch isolation, and conversion tests.
- Phase 22.5-22.6: Read-only dashboard authorization, branch isolation, and metric correctness tests.
- Phase 22.7: Manual VPS smoke checks after deployment.

Heavy commands such as `php artisan test`, broad filtered suites, and `npm run build` should be run manually from the normal Ubuntu Terminal when a code phase needs them. Codex should avoid heavy verification during docs-only Phase 22.0.

---

## 9. VPS Deployment Strategy

Sprint 22 VPS deployment must remain conservative:

1. Confirm approved release branch/tag before deployment.
2. Backup database and relevant uploaded files before pulling code.
3. Pull only the approved release branch or tag.
4. Run `php artisan migrate --force` only if an approved additive migration exists.
5. Clear and rebuild Laravel caches as needed.
6. Reset `storage` and `bootstrap/cache` permissions.
7. Run manual smoke checks for login, sidebar visibility, RME cashier, receipt/PDF, lab candidates, and owner dashboard.
8. Monitor pilot feedback before declaring the release stable.

Never run `migrate:fresh`, `db:wipe`, or any destructive reset on VPS.

---

## 10. Rollback Strategy

Rollback must be tag-based and data-safe:

- Pre-Sprint 22 baseline: `release/sprint-21-rme-advanced-workflow` at `3ef3fd6`.
- Sprint 21 RC tag: `sprint-21-release-candidate`.
- Sprint 21.9 hotfix tag: `sprint-21-phase-21-9-cashier-rme-sidebar-hotfix`.
- If Sprint 22 code phases introduce migrations, rollback must be planned per migration and reviewed before VPS deployment.
- If a deployment fails before migrations, revert to the last known-good release tag.
- If a deployment fails after migrations, restore from the pre-deploy backup only with owner approval.

---

## 11. Notes For Future Sprint 23

Potential Sprint 23 work should be planned after Sprint 22 pilot evidence:

- Partial payment / cicilan design and implementation, if owner approves payment model changes.
- Deeper owner analytics beyond read-only pilot KPIs.
- WhatsApp/notification templates and audit trail design.
- Lab service mapping from RME treatments, if business mapping becomes stable.
- Performance tuning for dashboard queries if pilot data volume requires it.
- Broader multi-branch rollout plan after the Hostinger VPS pilot stabilizes.
