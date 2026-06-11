# Developer Notes — RME → Lab Candidate End-to-End Validation (Sprint 22 Phase 22.4)

## Flow summary

```text
ClinicVisit (in_progress)
  → MedicalRecord finalize (+ handwriting required)
  → ClinicVisit (cashier_pending)
  → RmeInvoice create (Kasir, manage_rme_billing)
  → RmePayment pay full amount
  → RmeInvoice (PAID) + ClinicVisit (completed)
  → RmeLabIntegrationService::generateForPaidInvoice() [post-commit hook]
  → LabCaseCandidate (pending_review) per lab-eligible invoice item
  → LabCaseCandidateConversionService::convertToLabOrder() [Admin Lab]
  → LabOrder + LabOrderItem
  → LabCaseCandidate (converted_to_lab_order)
```

Pilot rule preserved: **full payment only** — partial payment rejected; no lab candidate until PAID.

---

## Services / controllers

| Layer | Class | Responsibility |
|-------|-------|----------------|
| Service | `MedicalRecordService` | Finalize RM; gate handwriting |
| Service | `RmeInvoiceService` | Create cashier invoice (visit must be `cashier_pending`, RM final) |
| Service | `RmePaymentService` | Full payment; visit → `completed`; triggers candidate generation post-commit |
| Service | `RmeLabIntegrationService` | Idempotent `LabCaseCandidate` per `requires_lab` invoice item |
| Service | `LabCaseCandidateConversionService` | Convert pending candidate → `LabOrder` with explicit `lab_service_id` |
| Controller | `MedicalRecordController` | RM CRUD + finalize routes |
| Controller | `RmeInvoiceController` | Cashier billing index/create/show |
| Controller | `RmePaymentController` | Payment form/store/receipt |
| Controller | `LabCaseCandidateController` | Candidate queue show + convert |
| Controller | `LabOrderController` | Lab order show (RME source panel when from candidate) |

---

## Route names

| Step | Route name |
|------|------------|
| Visit show | `rme.visits.show` |
| Medical record update | `rme.visits.medical-record.update` |
| Medical record finalize | `rme.visits.medical-record.finalize` |
| Handwriting store | `rme.visits.medical-record.handwriting.store` |
| Odontogram show/update/finalize | `rme.visits.odontogram.show`, `rme.odontograms.update`, `rme.odontograms.finalize` |
| Cashier index/create/store/show | `rme.cashier.index`, `rme.cashier.create`, `rme.cashier.store`, `rme.cashier.show` |
| Payment create/store/receipt | `rme.cashier.payment.create`, `rme.cashier.payment.store`, `rme.cashier.receipt.show` |
| Lab candidate index/show/convert | `lab-case-candidates.index`, `lab-case-candidates.show`, `lab-case-candidates.convert` |
| Lab order show | `lab-orders.show` |

---

## Permissions / roles (Phase 22.1 hardened)

| Role | RME clinical | Cashier billing | Lab candidate queue | Convert to lab order |
|------|--------------|-----------------|---------------------|----------------------|
| Doctor | yes | no | no | no |
| Kasir | view visits | yes (`manage_rme_billing`) | no | no |
| Admin Lab | no (clinical) | no | yes (`view_lab_orders`) | yes (`create_lab_orders`) |
| Perawat | visits queue | no | no | no |
| Owner | dashboard/reports | no | no | no |

---

## Data relationship map

```text
trx_clinic_visits
  └── trx_medical_records (medical_record_id on candidate via invoice)
  └── trx_rme_invoices
        └── trx_rme_invoice_items (requires_lab via mst_treatments)
        └── trx_rme_payments
        └── trx_lab_case_candidates (UNIQUE rme_invoice_item_id)
              └── trx_lab_orders (converted_lab_order_id)
                    └── trx_lab_order_items
```

Key foreign keys on `LabCaseCandidate`:

- `branch_id`, `clinic_visit_id`, `patient_id`, `doctor_id`
- `rme_invoice_id`, `rme_invoice_item_id`, `treatment_id`, `medical_record_id`
- `converted_lab_order_id` (after conversion)

---

## Idempotency expectations

| Operation | Mechanism | Expected behavior |
|-----------|-----------|-------------------|
| Candidate generation | `firstOrCreate(rme_invoice_item_id)` | Repeated `generateForPaidInvoice()` returns same row; count stays 1 |
| Payment hook | Post-commit call after PAID | Re-pay blocked; no duplicate candidates |
| Conversion | Row lock + `converted_lab_order_id` | Second convert returns same `LabOrder`; no duplicate items |

---

## No-auto-finance boundary

- RME payment writes only `trx_rme_payments` — **not** `trx_payments` (lab billing ledger).
- Candidate generation creates **no** `LabOrder`.
- Conversion creates **no** `Invoice` / `trx_payments` records.
- Lab billing remains a separate workflow (deferred).

Verified in:

- `tests/Feature/RME/LabIntegrationTest.php`
- `tests/Feature/RME/LabCaseCandidateConversionTest.php`
- `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php`

---

## Branch isolation

- `RmeLabIntegrationService` validates `invoice.branch_id === BranchContext::requireId()`.
- `LabCaseCandidatePolicy` scopes view/convert to active branch.
- Cross-branch candidate show/convert returns 403 in tests.

Smoke users may lack `users.branch_id`; `BranchContext` falls back to `MAIN` — document for VPS operators.

---

## Manual VPS validation

1. Backup DB before any deploy (`docs/pilot/vps_pilot_deployment_checklist.md`).
2. Run safe seeders only if needed (`docs/pilot/safe_seeder_rollout.md`).
3. Map real users to Doctor / Kasir / Admin Lab roles.
4. Ensure at least one treatment with `requires_lab = true` exists in pilot master data.
5. Follow operator checklist: `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md`.
6. Do **not** run `migrate:fresh`, `db:wipe`, or unqualified `db:seed`.

---

## Tests (Phase 22.4)

**File:** `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php`

Scenarios:

1. Full happy path + traceability UI (invoice, receipt, candidate, lab order)
2. Generation + conversion idempotency
3. Non-lab treatment guard
4. Partial payment guard (Sprint 20 rule)
5. Role boundaries (Kasir, Doctor, Admin Lab, unauthorized, cross-branch)
6. Visit `cashier_pending` after RM finalize

Related partial coverage (do not duplicate unnecessarily):

- `LabIntegrationTest` — candidate generation rules
- `LabCaseCandidateConversionTest` — conversion service
- `RmeLabWorkflowPolishTest` — UI polish panels
- `PilotRouteAuthorizationTest` — role route gates

**Commands:**

```bash
php artisan test --filter=RmeLabCandidateE2EValidation
php artisan test --filter=Pilot
php artisan test --filter=RME
```

Heavy full suite: Ubuntu Terminal only (`php artisan test`).

---

## RmeSmokeTestSeeder (Phase 22.4 decision)

**Unchanged.** E2E validation tests use factories. Manual operators select a lab-required treatment during kasir billing; smoke seeder does not pre-seed lab treatments or Admin Lab account.

---

## Follow-up for Phase 22.5

1. Optional smoke seeder extension: one idempotent lab-required treatment + Admin Lab smoke user (only if stakeholders want zero manual master-data setup).
2. Map VPS pilot users to hardened roles if not using smoke accounts.
3. Pre-seed handwriting PNG for faster operator runs (optional).
4. Owner/branch dashboard KPIs for RME→lab funnel metrics (deferred).
5. Treatment → `lab_service_id` auto-mapping (deferred — conversion still requires explicit lab service).
