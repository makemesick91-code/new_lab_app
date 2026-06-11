# Sprint 21 Phase 21.8 — Sprint 21 Closure / Release Candidate Merge Plan

> **Phase type:** Documentation / release planning only  
> **Merge performed:** No  
> **VPS deployment performed:** No  
> **Application code changed:** No  
> **Review required:** Read this plan and run quality gates before creating `release/sprint-21-rme-advanced-workflow` or tagging `sprint-21-release-candidate`.

---

## 1. Executive Summary

Sprint 21 is **functionally complete through Phase 21.7** (VPS pilot deployment checklist). Phase **21.8** closes Sprint 21 by documenting the release candidate baseline, merge order, VPS deployment order, rollback strategy, and post-merge verification checklist.

**This phase did not:**

- Merge any Sprint 21 branch into `main` or `feature/sprint-20-rme-core`
- Deploy to the VPS
- Run production commands, SSH, or `php artisan migrate` on any remote environment
- Change application behavior (controllers, services, models, views, routes, migrations, tests)

**This phase did:**

- Create this closure and release candidate merge plan
- Document the recommended RC baseline (`feature/sprint-21-vps-pilot-checklist` @ `18d2eec`)
- Reference the Phase 21.7 VPS runbook for controlled pilot deployment
- Prepare the project for stakeholder review and RC validation

---

## 2. Sprint 21 Objective

**Theme:** RME Advanced Workflow + Pilot Deployment preparation

Sprint 21 extends the Sprint 20 RME pilot with a controlled RME → Lab integration path, Admin Lab review workflow, cross-module visibility, print/PDF hardening, and VPS deployment readiness.

**End-to-end workflow delivered:**

```
RME visit → finalize RM → cashier invoice → full payment (PAID)
    → LabCaseCandidate generation (requires_lab treatments)
    → Admin Lab candidate queue review
    → manual convert to LabOrder (explicit lab_service_id)
    → RME invoice/receipt/LabOrder visibility
    → RME print / PDF export
    → VPS deployment via Phase 21.7 runbook (when approved)
```

---

## 3. Completed Phase Summary

| Phase | Branch | Commit | Tag | Summary |
|---|---|---|---|---|
| 21.0 | `feature/sprint-21-planning` | `1879f14` | `sprint-21-planning` | Sprint 21 planning |
| 21.1 | `feature/sprint-21-rme-lab-architecture` | `8997758` | `sprint-21-phase-21-1-rme-lab-architecture` | RME → Lab integration architecture |
| 21.2 | `feature/sprint-21-lab-case-candidates` | `bd047fe` | `sprint-21-phase-21-2-lab-case-candidates` | LabCaseCandidate generation |
| 21.3 | `feature/sprint-21-lab-candidate-queue` | `0eed855` | `sprint-21-phase-21-3-lab-candidate-queue` | Admin Lab candidate queue UI |
| 21.4 | `feature/sprint-21-candidate-to-laborder` | `cb68615` | `sprint-21-phase-21-4-candidate-to-laborder` | Convert candidate to LabOrder |
| 21.5 | `feature/sprint-21-rme-lab-workflow-polish` | `243eb78` | `sprint-21-phase-21-5-rme-lab-workflow-polish` | Workflow visibility polish |
| 21.6 | `feature/sprint-21-rme-pdf-print-hardening` | `327e55f` | `sprint-21-phase-21-6-rme-pdf-print-hardening` | RME print/PDF hardening |
| 21.7 | `feature/sprint-21-vps-pilot-checklist` | `18d2eec` | `sprint-21-phase-21-7-vps-pilot-checklist` | VPS pilot deployment checklist |
| 21.8 | `feature/sprint-21-closure-rc-plan` | *(this commit)* | `sprint-21-phase-21-8-closure-rc-plan` | Closure / RC merge plan (documentation only) |

**Git history note:** Sprint 21 phase branches form a **linear chain** from `1879f14` through `18d2eec`. Each phase branch tip is an ancestor of the next.

---

## 4. Release Candidate Baseline

### Recommended RC source (functional baseline)

| Item | Value |
|---|---|
| **Recommended RC source branch** | `feature/sprint-21-vps-pilot-checklist` |
| **Recommended RC commit** | `18d2eec` |
| **Recommended RC tag baseline** | `sprint-21-phase-21-7-vps-pilot-checklist` |

### Phase 21.8 documentation vs deployment baseline

Phase 21.8 adds documentation only. The Phase 21.8 commit will be **newer** than the Phase 21.7 functional baseline.

| RC strategy | When to use | Branch / commit |
|---|---|---|
| **Documentation-inclusive RC** | Stakeholders want closure docs in the RC branch | `feature/sprint-21-closure-rc-plan` (after Phase 21.8 merge) |
| **Deployment-only RC** | VPS deploy should match last functional phase only | `feature/sprint-21-vps-pilot-checklist` @ `18d2eec` |

**Recommendation:**

1. Treat `18d2eec` as the **functional** Sprint 21 baseline for VPS deployment.
2. Include Phase 21.8 docs in the RC branch for merge review and audit trail.
3. Create the final RC tag **`sprint-21-release-candidate`** only after stakeholder approval and full test suite pass — **do not create that tag in Phase 21.8** unless explicitly requested later.

---

## 5. Sprint 21 Deliverables

### A. RME → Lab integration

- `trx_lab_case_candidates` staging table and `LabCaseCandidate` model
- `RmeLabIntegrationService` — generation after paid RME invoice
- Idempotent duplicate prevention via `UNIQUE(rme_invoice_item_id)` and `firstOrCreate`
- Post-commit, non-blocking payment hook in `RmePaymentService::pay()` (generation failure does not roll back payment)
- Eligibility filter: `mst_treatments.requires_lab = true`

### B. Admin Lab review workflow

- Candidate queue: `/lab/case-candidates` (`lab-case-candidates.index`)
- Candidate detail: `lab-case-candidates.show`
- Convert to `LabOrder`: `lab-case-candidates.convert` via `LabCaseCandidateConversionService`
- Explicit `lab_service_id` selection (no automatic treatment → lab service mapping)
- `LabCaseCandidatePolicy` with branch isolation

### C. Workflow visibility

- RME invoice show — lab candidate status panel
- Cashier receipt — lab workflow panel (including print)
- Candidate queue/show — converted state and LabOrder link
- LabOrder show — "Sumber RME" block when sourced from candidate
- Model relations: `RmeInvoice::labCaseCandidates()`, `LabOrder::rmeLabCaseCandidate()`

### D. Print / PDF

- RME visit print hardening (`rme.visits.print`) — patient, visit, branch, initial treatment, RM + handwriting (SOAP hidden), odontogram, invoice/payment, lab workflow
- RME PDF route: `rme.visits.pdf` via `barryvdh/laravel-dompdf`
- Shared print partial: `resources/views/rme/visits/partials/print-body.blade.php`
- Receipt print includes lab workflow panel

### E. Deployment readiness

- VPS pilot deployment runbook: `docs/sprint_21_vps_pilot_deployment_checklist.md`
- Backup, rollback, permission reset, and smoke test checklist
- This closure plan: `docs/sprint_21_closure_release_candidate_plan.md`

---

## 6. Migration Impact

### Sprint 21 database changes

| Migration | Action | Notes |
|---|---|---|
| `2026_06_14_210001_create_trx_lab_case_candidates_table.php` | **CREATE** `trx_lab_case_candidates` | Only new Sprint 21 migration |

**Table:** `trx_lab_case_candidates`

- Branch-scoped staging records linking RME invoice items to future LabOrders
- `rme_invoice_item_id` UNIQUE — idempotency key
- `converted_lab_order_id` nullable — set on Admin Lab conversion
- Status: `pending_review`, `converted_to_lab_order`, `rejected`, `cancelled`
- Soft deletes enabled

### Pre-Sprint 21 tables (not new in Sprint 21)

The following related tables exist from Sprint 20 or earlier and are **not** created by Sprint 21 migrations:

- `trx_clinic_visits`, `trx_odontograms`, `trx_rme_invoices`, `trx_rme_invoice_items`, `trx_rme_payments`
- `trx_lab_orders`, `trx_lab_order_items`, etc.

### Migration safety rules

| Rule | Status |
|---|---|
| Destructive migrations expected | **No** |
| Table drops | **No** |
| Column removals | **No** |
| `migrate:fresh` on VPS | **Forbidden** |
| VPS deployment command | `php artisan migrate --force` **only** |

**Pre-deploy verification (local and VPS):**

```bash
php artisan migrate:status | grep lab_case_candidates
```

Expected: `2026_06_14_210001_create_trx_lab_case_candidates_table` shows **Ran** after deploy.

---

## 7. Business Rules Preserved

| Rule | Sprint 21 status |
|---|---|
| Doctor SOAP UI remains hidden | Preserved — handwriting RM is primary clinical input |
| SOAP DB fields remain optional/legacy | Preserved — not exposed in doctor UI |
| RME payment remains full-payment only | Preserved — partial/cicilan deferred |
| RME payment does not create LabOrder directly | Preserved — staging via `LabCaseCandidate` only |
| RME payment does not create lab invoice/payment | Preserved — no `trx_payments` from RME flows |
| Candidate conversion requires explicit `lab_service_id` | Preserved — manual Admin Lab selection |
| Candidate conversion does not create payment records | Preserved |
| Branch isolation remains mandatory | Preserved — `BranchContext::requireId()` |
| Cross-branch access must return 403 | Preserved — tested in feature suites |

---

## 8. Merge Plan

**No merge was performed in Phase 21.8.** The following is the recommended plan for stakeholders.

### Option A: Merge latest linear Sprint 21 branch (recommended)

Because Sprint 21 phases are linear (each phase builds on the previous):

1. Use `feature/sprint-21-closure-rc-plan` (or `feature/sprint-21-vps-pilot-checklist` for functional-only) as the Sprint 21 source branch.
2. Open a PR into the project's integration branch or `main` per project policy.
3. Verify the PR diff contains all Sprint 21 phases (21.0–21.8 documentation).
4. Confirm no unintended files from unrelated sprints.

**Why Option A:** Git history is linear from `48c9fe6` (Sprint 20 closure) → `1879f14` → … → `18d2eec`. No merge conflicts expected between phase branches.

### Option B: Merge phase branches sequentially

Use only if history is non-linear or a partial merge is required:

| Order | Branch |
|---|---|
| 1 | `feature/sprint-21-planning` |
| 2 | `feature/sprint-21-rme-lab-architecture` |
| 3 | `feature/sprint-21-lab-case-candidates` |
| 4 | `feature/sprint-21-lab-candidate-queue` |
| 5 | `feature/sprint-21-candidate-to-laborder` |
| 6 | `feature/sprint-21-rme-lab-workflow-polish` |
| 7 | `feature/sprint-21-rme-pdf-print-hardening` |
| 8 | `feature/sprint-21-vps-pilot-checklist` |
| 9 | `feature/sprint-21-closure-rc-plan` |

**Recommendation:** Use **Option A** — the latest branch already contains all prior phase commits.

### Branches explicitly not merged in this phase

- `main`
- `feature/sprint-20-rme-core` (Sprint 20 closure baseline remains at `48c9fe6` / `sprint-20-rme-core-ui-complete`)

---

## 9. Release Candidate Branch Plan

**Do not run these commands until stakeholder approval and quality gates pass.**

### Create RC branch

```bash
git checkout feature/sprint-21-closure-rc-plan
git pull origin feature/sprint-21-closure-rc-plan
git checkout -b release/sprint-21-rme-advanced-workflow
git push -u origin release/sprint-21-rme-advanced-workflow
```

### Tag RC (after full test suite pass)

```bash
git tag sprint-21-release-candidate
git push origin sprint-21-release-candidate
```

**Only run after:**

- Stakeholder approval
- All pre-RC quality gates pass (Section 10)
- PR review complete (if applicable)

---

## 10. Pre-RC Quality Gates

Run locally before creating `release/sprint-21-rme-advanced-workflow` or tagging `sprint-21-release-candidate`:

| Gate | Command |
|---|---|
| RME suite | `php artisan test --filter=RME` |
| Lab integration | `php artisan test --filter=LabIntegration` |
| Candidate queue | `php artisan test --filter=LabCaseCandidateQueue` |
| Candidate conversion | `php artisan test --filter=LabCaseCandidateConversion` |
| Workflow polish | `php artisan test --filter=RmeLabWorkflowPolish` |
| Print/PDF hardening | `php artisan test --filter=RmePdfPrintHardening` |
| Full suite | `php artisan test` |
| Code style | `./vendor/bin/pint --dirty` |
| Frontend build | `npm run build` |

**Phase 21.8 minimum gates (documentation-only):** RME filter, pint, npm build.

---

## 11. VPS Deployment Order

**Reference runbook:** `docs/sprint_21_vps_pilot_deployment_checklist.md`

**Updated deployment target (post-21.7):**

| Item | Recommended value |
|---|---|
| Branch | `feature/sprint-21-vps-pilot-checklist` or approved `release/sprint-21-rme-advanced-workflow` |
| Tag | `sprint-21-phase-21-7-vps-pilot-checklist` or `sprint-21-release-candidate` |
| Commit | `18d2eec` or newer approved deploy commit |
| App path | `/var/www/asia-dental-lab-v2` |

### Deployment sequence (summary)

| Step | Action |
|---|---|
| 1 | Local verification — all quality gates pass |
| 2 | VPS inspection — confirm path, PHP version, disk space, services |
| 3 | Database backup — `pg_dump`, verify file size |
| 4 | Git fetch/pull approved branch/tag |
| 5 | `composer install --no-dev --optimize-autoloader` |
| 6 | `npm ci` |
| 7 | `npm run build` |
| 8 | `php artisan down` (if ops policy requires) |
| 9 | `php artisan optimize:clear` |
| 10 | `php artisan migrate --force` |
| 11 | Cache rebuild (`config:cache`, `route:cache`, `view:cache` as per runbook) |
| 12 | Permission reset (`storage`, `bootstrap/cache`) |
| 13 | `php-fpm` / `nginx` reload |
| 14 | `php artisan up` |
| 15 | Smoke tests (Section 13) |
| 16 | Sign-off |

**Never:** `migrate:fresh`, `db:wipe`, or unverified backup skip.

---

## 12. Rollback Plan Summary

### Code rollback

1. Put app in maintenance mode: `php artisan down`
2. Checkout previous known-good commit/tag:
   - Pre-Sprint 21: `sprint-20-rme-core-ui-complete` / `48c9fe6`
   - Pre-deploy Sprint 21: tag/commit recorded at deploy time
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build` (if assets changed)
5. `php artisan optimize:clear` + cache rebuild
6. Reset `storage` / `bootstrap/cache` permissions
7. Reload `php-fpm` / `nginx`
8. `php artisan up`
9. Run smoke tests on rolled-back version

### Database rollback

- Restore **only** from a verified pre-deploy backup if schema/data rollback is approved
- Maintenance mode required during restore
- **Do not improvise** partial rollbacks or manual table drops
- If only code rollback is needed and migration was additive (`trx_lab_case_candidates` only), code rollback without DB restore may be acceptable — confirm with operator

### Recommended pre-Sprint 21 rollback point

| Item | Value |
|---|---|
| Tag | `sprint-20-rme-core-ui-complete` |
| Commit | `48c9fe6` |
| Branch | `feature/sprint-20-rme-core` |

---

## 13. Post-Deployment Smoke Test Summary

Execute after VPS deploy (see runbook for detailed steps):

| # | Test | Expected |
|---|---|---|
| 1 | Login | Authenticated session works |
| 2 | RME visit queue | Visits list loads for active branch |
| 3 | Visit print | `rme.visits.print` renders without error |
| 4 | RME PDF download | `rme.visits.pdf` returns PDF |
| 5 | Cashier invoice show | Invoice detail loads |
| 6 | Receipt | Receipt print includes lab workflow when applicable |
| 7 | Candidate queue | `/lab/case-candidates` loads for authorized Admin Lab |
| 8 | Candidate detail | Show page loads with branch-scoped data |
| 9 | Convert to LabOrder | Pilot/test data conversion succeeds with explicit `lab_service_id` |
| 10 | LabOrder show — Sumber RME | Source block visible when from candidate |
| 11 | Cross-branch 403 | Accessing other branch's candidate/order returns 403 |
| 12 | No lab payment side effects | No unexpected `trx_payments` from RME payment/conversion |

---

## 14. Known Open Items

| Item | Notes |
|---|---|
| VPS PHP-FPM service name | Must be verified on VPS (`php8.3-fpm`, `php8.2-fpm`, etc.) |
| PostgreSQL `DB_DATABASE` on VPS | Must be verified before backup/restore |
| HTTPS/domain vs IP-only | Must be decided for pilot URLs and PDF links |
| Disk space | Verify space for DB backup + `public/build` before deploy |
| Pilot users/roles | Review before go-live |
| `Treatment.requires_lab` values | Review against real clinic master data |
| Lab service mapping | Remains manual via conversion form — no auto-mapping |
| Phase 21.7 runbook deploy target | Runbook written at 21.6 baseline (`327e55f`); use 21.7/21.8 baseline (`18d2eec`) for actual deploy |
| `sprint-21-release-candidate` tag | Not created in Phase 21.8 — pending stakeholder approval |
| Partial/cicilan payments | Deferred beyond Sprint 21 |
| WhatsApp / notifications | Deferred beyond Sprint 21 |

---

## 15. Sprint 21 Acceptance Criteria

| Criterion | Status |
|---|---|
| All Sprint 21 tests pass | Verify via quality gates |
| Candidate generation from paid RME invoice | Implemented (Phase 21.2) |
| Candidate queue visible to authorized Admin Lab | Implemented (Phase 21.3) |
| Candidate conversion creates LabOrder correctly | Implemented (Phase 21.4) |
| RME invoice/receipt/LabOrder visibility | Implemented (Phase 21.5) |
| RME print/PDF works | Implemented (Phase 21.6) |
| VPS deployment checklist ready | Implemented (Phase 21.7) |
| Closure / RC plan documented | Implemented (Phase 21.8) |
| No destructive migrations | Confirmed — additive only |
| No business rule regressions | Preserved per Section 7 |

---

## 16. Final Recommendation

1. **Treat Sprint 21 as ready for RC** after full test suite pass and stakeholder review.
2. **Create `release/sprint-21-rme-advanced-workflow`** only after PR/merge approval — not in Phase 21.8.
3. **Deploy to VPS** only through `docs/sprint_21_vps_pilot_deployment_checklist.md` with updated baseline `18d2eec`.
4. **Do not skip database backup** before `git pull` or `migrate --force`.
5. **Do not run `migrate:fresh`** on VPS under any circumstance.
6. **Tag `sprint-21-release-candidate`** only after RC branch is validated — defer to post-21.8 stakeholder approval.

---

## Appendix A — Sprint 21 Test Files

| Test file | Phase | Focus |
|---|---|---|
| `tests/Feature/RME/LabIntegrationTest.php` | 21.2 | Candidate generation, idempotency, branch isolation |
| `tests/Feature/RME/LabCaseCandidateQueueTest.php` | 21.3 | Queue UI, auth, branch isolation |
| `tests/Feature/RME/LabCaseCandidateConversionTest.php` | 21.4 | Conversion, idempotency, lab_service_id |
| `tests/Feature/RME/RmeLabWorkflowPolishTest.php` | 21.5 | Visibility across RME/Lab views |
| `tests/Feature/RME/RmePdfPrintHardeningTest.php` | 21.6 | Print/PDF content, auth, no side effects |

## Appendix B — Key Routes (Sprint 21)

| Route name | Path | Module |
|---|---|---|
| `lab-case-candidates.index` | `/lab/case-candidates` | LabOrder |
| `lab-case-candidates.show` | `/lab/case-candidates/{candidate}` | LabOrder |
| `lab-case-candidates.convert` | POST convert action | LabOrder |
| `rme.visits.print` | `/rme/visits/{visit}/print` | ClinicVisit |
| `rme.visits.pdf` | `/rme/visits/{visit}/pdf` | ClinicVisit |

## Appendix C — Documentation Index

| Document | Purpose |
|---|---|
| `docs/sprint_21_planning.md` | Sprint 21 phase tracking |
| `docs/sprint_21_rme_lab_integration_architecture.md` | Phase 21.1 architecture decisions |
| `docs/sprint_21_vps_pilot_deployment_checklist.md` | Phase 21.7 VPS runbook |
| `docs/sprint_21_closure_release_candidate_plan.md` | This document (Phase 21.8) |
| `docs/sprint_history.md` | Permanent project memory |
