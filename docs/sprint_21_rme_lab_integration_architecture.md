# Sprint 21 Phase 21.1 — RME to Lab Integration Architecture

**Version:** 1.1  
**Date:** 2026-06-11  
**Branch:** `feature/sprint-21-rme-lab-architecture` (design) → `feature/sprint-21-lab-case-candidates` (implementation)  
**Type:** Design + Implementation  
**Status:** IMPLEMENTED — Phase 21.2 complete (tag: `sprint-21-phase-21-2-lab-case-candidates`)

**Phase 21.2 implementation summary:**
- `trx_lab_case_candidates` table created (migration `2026_06_14_210001`).
- `LabCaseCandidate` model at `app/Modules/LabOrder/Models/LabCaseCandidate.php`.
- `RmeLabIntegrationService` at `app/Modules/RmeInvoice/Services/RmeLabIntegrationService.php`.
- Post-commit hook added to `RmePaymentService::pay()` (Option 2 — separate transaction, non-blocking).
- 11 integration tests added to `tests/Feature/RME/LabIntegrationTest.php`.
- All architecture decisions from Section 6 implemented as specified.

---

## 1. Executive Summary

Sprint 21 integrates the Sprint 20 RME billing system with the existing LabOrder workflow for
dental treatments that require laboratory fabrication. When a patient's RME invoice is fully paid,
the system must detect which invoice items require lab work and initiate a lab workflow for each
such item.

This document defines the architecture for that integration: the trigger point, the data model,
the creation strategy, duplicate prevention, branch isolation, audit trail, error handling, and
the recommended pilot approach. No application code is changed in Phase 21.1. All decisions
documented here require implementation approval before Phase 21.2 begins.

---

## 2. Baseline From Sprint 20

The following Sprint 20 behaviors are immutable and must not be changed by Sprint 21.

| Rule | Implementation |
|---|---|
| RME payment is full-payment-only | `RmePaymentService::pay()` validates `amount === grand_total` |
| RME invoice becomes `PAID` after full payment | `RmePaymentService` updates `trx_rme_invoices.status` to `PAID` |
| Clinic visit becomes `completed` after payment | `RmePaymentService` calls `ClinicVisitService::transitionStatus()` to `STATUS_COMPLETED` |
| RME payment must not create lab-order payment records | `RmePaymentService` only writes to `trx_rme_payments`; `trx_payments` (lab billing) is untouched |
| SOAP doctor UI remains hidden | Intentional — handwriting RM is primary clinical input |
| Handwriting RM remains primary | `finalize_rme` blocked until PNG saved |
| RME cashier receipt exists | `resources/views/rme/cashier/receipt/show.blade.php` |
| RME data is branch-scoped | `BranchContext::requireId()` enforced throughout `RmeInvoice` module |
| Odontogram is one-way immutable after finalization | `draft → finalized` state is permanent |

---

## 3. Existing Domain Components

The following components were verified by direct code inspection on 2026-06-11.

### 3.1 ClinicVisit (`app/Modules/ClinicVisit/`)

Table: `trx_clinic_visits`

Key fields: `branch_id`, `clinic_id`, `patient_id`, `doctor_id`, `clinic_room_id`,
`initial_treatment_id`, `visit_number`, `status`, `completed_at`.

Status machine:
```
registered → waiting → in_progress → cashier_pending → completed
                                    ↘ cancelled
```

After RME full payment, visit status is `completed`. This is terminal — no further status
transitions are allowed from `completed`.

### 3.2 MedicalRecord (`app/Modules/MedicalRecord/`)

Table: `trx_medical_records`

Links to `ClinicVisit`. Stores handwriting PNG reference, SOAP fields (legacy, hidden from UI).
`finalized_at` is set when the doctor finalizes. Immutable after finalization.

### 3.3 Odontogram (`app/Modules/Odontogram/`)

Tables: `trx_odontograms`, `trx_odontogram_items`

Per-tooth conditions and notes. Finalized one-way. Not directly relevant to lab integration
mapping, but provides clinical context.

### 3.4 RmeInvoice / RmeInvoiceItem / RmePayment (`app/Modules/RmeInvoice/`)

Tables: `trx_rme_invoices`, `trx_rme_invoice_items`, `trx_rme_payments`

**RmeInvoice** statuses: `DRAFT → UNPAID → PAID` (also `VOID`).

Key `RmeInvoice` fields: `branch_id`, `clinic_visit_id`, `patient_id`, `medical_record_id`,
`cashier_id`, `invoice_number`, `status`, `grand_total`.

**RmeInvoiceItem** fields: `rme_invoice_id`, `treatment_id`, `description`, `qty`, `unit_price`,
`discount`, `subtotal`, `doctor_id`.

**RmePayment** fields: `branch_id`, `rme_invoice_id`, `clinic_visit_id`, `patient_id`,
`cashier_id`, `payment_method_id`, `payment_number`, `amount`, `paid_at`, `reference_number`.

### 3.5 Treatment (`app/Modules/Treatment/`)

Table: `mst_treatments`

**`requires_lab` boolean column already exists** (confirmed in migration
`2026_06_13_100001_create_mst_treatments_table.php`, default `false`, indexed). No migration
is needed to add this column. Phase 21.2 only needs to seed or set this flag on appropriate
treatment records.

### 3.6 Tariff (`app/Modules/Tariff/`)

Table: `mst_tariffs`

Fields: `branch_id`, `treatment_id`, `price`, `effective_date`, `is_active`. Per-branch pricing
for treatments. Not directly involved in lab integration, but its `treatment_id` links to
Treatment.

### 3.7 LabOrder / LabOrderItem (`app/Modules/LabOrder/`)

Tables: `trx_lab_orders`, `trx_lab_order_items`

**LabOrder** statuses: `DRAFT`, `RECEIVED`, `ASSIGNED`, `IN_PRODUCTION`, `ON_HOLD`,
`QC_PENDING`, `QC_PASSED`, `READY_FOR_DELIVERY`, `IN_DELIVERY`, `DELIVERED`, `COMPLETED`,
`REMAKE`, `CANCELLED`.

Key `LabOrder` fields: `order_number`, `branch_id`, `clinic_id`, `doctor_id`, `patient_id`,
`medical_record_number`, `order_date`, `due_date`, `priority`, `status`, `notes`, `created_by`.

**LabOrderItem** fields: `lab_order_id`, `lab_service_id`, `tooth_number`, `shade_color_text`,
`material_text`, `quantity`, `unit_price`, `subtotal`, `notes`.

**Critical observation:** `LabOrderItem` references `lab_service_id` (from `LabService` module),
not `treatment_id`. RME items reference `treatment_id`. There is no existing mapping table
between `Treatment` and `LabService`. Direct LabOrder generation from RME items would require
either resolving this mapping or leaving `lab_service_id` null — which may violate LabOrder
business rules. This is the primary technical reason the LabCaseCandidate staging approach is
recommended for the pilot.

### 3.8 BranchContext (`app/Modules/Branch/Services/BranchContext`)

`BranchContext::requireId()` is the single source of truth for the active branch in all RME and
LabOrder queries. All new integration records must respect this contract.

### 3.9 AuditLog / StatusLog (`app/Modules/LabOrder/Models/`)

Tables: `sys_audit_logs` (polymorphic), `trx_lab_order_status_logs`.

`AuditLogService` and `StatusLogService` are used by LabOrderService for full audit trail.
The same pattern should be followed for lab integration events.

---

## 4. Integration Trigger Decision

**Recommended trigger: after `RmePaymentService::pay()` sets invoice status to `PAID`.**

### 4.1 Rationale

| Condition at trigger point | Status |
|---|---|
| Doctor has finalized RME | ✓ Required before cashier billing |
| Cashier has finalized billing items | ✓ Invoice is `UNPAID` before payment |
| Payment is confirmed in full | ✓ Full-payment-only rule enforced |
| Visit is `completed` | ✓ Transition happens in same transaction |
| No further invoice mutations expected | ✓ `PAID` invoice is effectively immutable |

Lab work should only begin after the financial gate is cleared. Creating lab candidates before
payment risks fabricating work for invoices that may be cancelled, voided, or never paid.

### 4.2 Rejected Trigger Points

| Trigger | Reason Rejected |
|---|---|
| On visit creation (`registered`) | Too early — no treatment or billing confirmed |
| On RME finalization (`finalized_at`) | Billing not yet confirmed; invoice may change |
| On invoice creation (`DRAFT`) | Draft invoice may be edited or voided |
| On invoice `UNPAID` (issued) | Payment not yet received |
| On receipt page load | Side effects from read-only page render are unsafe |
| On cashier `show` page load | Same risk — read operations must be side-effect-free |

### 4.3 Integration Point in Code

The integration will be called immediately after `RmePaymentService::pay()` sets the invoice to
`PAID` — either as a service call at the end of the same transaction, or as a post-commit hook,
depending on the transaction strategy chosen in Phase 21.2 (see Section 12).

Exact file: `app/Modules/RmeInvoice/Services/RmePaymentService.php` — after the invoice status
update and visit transition, before `return $payment->refresh()`.

---

## 5. Lab Creation Strategy

### Option A — Create LabOrder Directly After Paid RME Invoice

**Pros:**
- Faster lab workflow — no extra approval step
- Uses existing `LabOrder` module without new tables
- Less UI to build initially

**Cons:**
- `LabOrderItem` requires `lab_service_id`; RME items have `treatment_id` — no mapping exists
- Mapping errors create real LabOrders that are difficult to correct
- Bypasses admin lab review, which is valuable during pilot
- If generation fails, it is harder to retry without re-triggering payment

**Verdict:** Not recommended for pilot without a treatment → lab service mapping table, which
does not yet exist.

### Option B — Create LabCaseCandidate First (Staging)

**Pros:**
- Safer for pilot: no real LabOrder until Admin Lab reviews and approves
- Does not require treatment → lab service mapping at generation time
- Easier duplicate prevention via source reference key
- Better audit trail — candidate records the RME source faithfully
- Failed generation can be retried manually without touching payment state
- Supports review-before-commit workflow appropriate for a multi-branch dental lab

**Cons:**
- Requires new `trx_lab_case_candidates` table, model, service, and minimal UI
- Extra step for staff (Admin Lab must convert candidate → LabOrder)

**Verdict: Option B is recommended for Sprint 21 pilot.**

If treatment → lab service mapping is added and tested in Phase 21.2, direct LabOrder creation
from approved candidates is possible as a Phase 21.2b enhancement.

---

## 6. Recommended Sprint 21 Architecture

```
RmePaymentService::pay()
    └─ [after PAID + visit completed]
        └─ RmeLabIntegrationService::generateCandidatesForPaidInvoice(RmeInvoice $invoice)
                └─ foreach RmeInvoiceItem as $item
                        └─ if $item->treatment->requires_lab === true
                                └─ firstOrCreate LabCaseCandidate
                                        source: rme_invoice_item_id (unique key)
                                        branch_id: $invoice->branch_id
                                        status: pending_review
```

**Key contracts:**
- Only `RmeInvoiceItem` records where `treatment.requires_lab = true` produce candidates.
- Each eligible item produces at most one active candidate (idempotent).
- Each candidate stores the full source reference chain for traceability.
- No `trx_rme_payments` mutation occurs — payment state is not touched.
- No `trx_payments` (lab billing) record is created.
- Candidate generation failure must not roll back the payment.

---

## 7. Data Mapping

The following table maps RME source fields to the proposed `trx_lab_case_candidates` table.

| RME Source | RME Field | Lab Candidate Field | Notes |
|---|---|---|---|
| `trx_rme_invoices.branch_id` | `branch_id` | `branch_id` | Mandatory; enforces branch isolation |
| `trx_clinic_visits.patient_id` | `patient_id` | `patient_id` | Via `rme_invoice.clinic_visit.patient_id` |
| `trx_clinic_visits.doctor_id` | `doctor_id` | `doctor_id` | Via `rme_invoice.clinic_visit.doctor_id`; also available on item |
| `trx_rme_invoices.clinic_visit_id` | `clinic_visit_id` | `clinic_visit_id` | Direct from invoice |
| `trx_rme_invoices.id` | `id` | `rme_invoice_id` | Source invoice reference |
| `trx_rme_invoice_items.id` | `id` | `rme_invoice_item_id` | **Unique key for idempotency** |
| `trx_rme_invoice_items.treatment_id` | `treatment_id` | `treatment_id` | Lab candidate carries treatment; mapping to lab_service deferred |
| `trx_rme_invoice_items.description` | `description` | `source_description` | Cashier-entered description |
| `trx_rme_invoice_items.qty` | `qty` | `quantity` | Integer quantity |
| `trx_rme_invoice_items.unit_price` | `unit_price` | `estimated_price` | Informational; not a lab invoice |
| `trx_rme_invoice_items.doctor_id` | `doctor_id` | `doctor_id` | Item-level doctor if set, else visit-level |
| `trx_medical_records.id` | (via visit) | `medical_record_id` | Requires implementation verification |
| Odontogram reference | (via visit) | `metadata` (JSON) | Odontogram id can be stored in metadata |
| `trx_rme_invoices.invoice_number` | `invoice_number` | `metadata` (JSON) | For display on candidate review UI |
| Due date | — | `due_date` | To be configured; see Section 18 open questions |
| — | — | `status` | `pending_review` on creation |
| — | — | `converted_lab_order_id` | Null until Admin Lab converts |
| `RmePaymentService` actor | cashier user | `created_by` | User who triggered payment |
| — | — | `reviewed_by` / `reviewed_at` | Set by Admin Lab on review |
| — | — | `notes` | Admin Lab notes |
| — | — | `metadata` (JSON) | Extensible store for additional source context |

Fields marked "Requires implementation verification" must be confirmed against actual foreign key
availability before Phase 21.2 coding begins.

---

## 8. Duplicate Prevention

**Rule:** One active `LabCaseCandidate` per `rme_invoice_item_id`.

**Mechanism:**
- Unique database constraint on `rme_invoice_item_id` (active-only or unconditional depending on
  void/cancel strategy).
- Service uses `firstOrCreate` semantics: if a candidate for this item already exists, return it
  without creating a new record.
- Repeated calls to `RmeLabIntegrationService::generateCandidatesForPaidInvoice()` must be
  idempotent — safe to call multiple times with the same invoice.
- The unique key is on the source item reference, not on `(patient_id, treatment_id, date)` —
  this prevents false duplicate detection across legitimate separate visits.

**Void / cancel behavior (to be designed in Phase 21.2):**
- Voiding an RME invoice after payment is currently not supported (PAID is effectively terminal).
- If void capability is ever added, the corresponding LabCaseCandidate should be set to
  `cancelled` status.
- Do not delete candidates — preserve for audit trail.

---

## 9. Branch Isolation

**Rules:**

1. Every `LabCaseCandidate` record must carry the same `branch_id` as the source
   `trx_rme_invoices.branch_id`.
2. The integration service must validate `rme_invoice.branch_id === BranchContext::requireId()`
   before generating candidates (mirrors existing `RmePaymentService` branch check).
3. Cross-branch patient, doctor, or treatment records must be rejected with a validation
   exception.
4. All candidate queries in Admin Lab UI must be branch-scoped via `BranchContext::requireId()`.
5. Policy class for `LabCaseCandidate` must gate all actions on branch membership.
6. Tests must prove: Branch A payment does not create candidates visible in Branch B queries.

---

## 10. Audit Trail

The following audit events must be recorded (via `AuditLogService` or equivalent):

| Event | When | Required Fields |
|---|---|---|
| `lab_candidate_created_from_rme` | Candidate successfully created | actor, branch_id, rme_invoice_id, rme_invoice_item_id, clinic_visit_id, patient_id, candidate_id |
| `lab_order_created_from_rme` | If direct LabOrder path is used in future | Same as above + lab_order_id |
| `lab_generation_skipped_no_lab_items` | Invoice has no `requires_lab` items | actor, branch_id, rme_invoice_id |
| `lab_generation_skipped_duplicate` | Candidate already exists for item | actor, branch_id, rme_invoice_item_id, existing_candidate_id |
| `lab_generation_failed_validation` | Validation error during generation | actor, branch_id, rme_invoice_id, error_message |

Audit records must include:
- `actor_user_id` — the cashier who triggered payment (not an automated system user)
- `branch_id`
- `rme_invoice_id`
- `rme_invoice_item_id` (where applicable)
- `clinic_visit_id`
- `patient_id`
- `generated_candidate_id` (where applicable)
- `metadata` summary (JSON) — source description, treatment name, quantity

---

## 11. Status Workflow

### 11.1 LabCaseCandidate Status Machine

```
pending_review
    ├─ converted_to_lab_order  (Admin Lab approves → LabOrder created)
    ├─ rejected                (Admin Lab rejects — no lab work needed)
    └─ cancelled               (Source RME invoice voided — future feature)
```

Terminal statuses: `converted_to_lab_order`, `rejected`, `cancelled`.

Candidates in `pending_review` appear in the Admin Lab review queue.

### 11.2 LabOrder Status (If Direct Creation Used)

If Option A (direct LabOrder) is ever chosen for a future phase:
- Use existing initial status `STATUS_RECEIVED` (same as manual creation via `LabOrderService`).
- Do not introduce new LabOrder statuses for RME-sourced orders unless absolutely necessary.
- Add source metadata fields to LabOrder (see Section 15).

---

## 12. Error Handling

### 12.1 Transaction Strategy Options

**Option 1 — Payment and candidate generation in same transaction:**
- If candidate generation fails, payment also rolls back.
- Risk: payment failure due to a non-payment bug blocks cashier.
- Use only if lab candidate generation is considered mandatory for payment completion.

**Option 2 — Candidate generation after payment commits (recommended for pilot):**
- Payment transaction commits first.
- Candidate generation runs in a separate transaction immediately after.
- If generation fails: error is logged, payment remains successful.
- Cashier can still issue receipt; Admin Lab can trigger manual regeneration.
- Safer for pilot: a generation bug does not block cashier operations.

**Recommended pilot approach:** Option 2.

### 12.2 Failure Recovery

- Failed generation attempts are logged to `sys_audit_logs` with event
  `lab_generation_failed_validation`.
- Admin Lab can trigger a manual "regenerate candidates for invoice" action (Phase 21.2 UI).
- Idempotency ensures safe re-runs without duplicates.

---

## 13. UI / UX Planning

The following screens are proposed for Phase 21.2. None are implemented in Phase 21.1.

| Screen | Location | Purpose |
|---|---|---|
| RME cashier receipt | `resources/views/rme/cashier/receipt/show.blade.php` | Add "Lab work queued: N items" status after payment if candidates exist |
| Admin Lab queue | New route under `admin/lab/candidates` | List of `pending_review` candidates, branch-scoped |
| Candidate show | New route under `admin/lab/candidates/{id}` | Review source RME data; approve or reject |
| Convert to LabOrder | Action on candidate show | Creates LabOrder from candidate; requires treatment → lab service mapping |
| LabOrder show | Existing `LabOrderController` | Add "Source RME Invoice" link if `rme_invoice_item_id` is present |

Implementation is deferred to Phase 21.2 pending approval.

---

## 14. Test Strategy for Phase 21.2

Required tests before any Phase 21.2 implementation begins (tests-first rule):

**Integration tests:**

1. Paid RME invoice with no `requires_lab` items creates no candidate/order.
2. Paid RME invoice with one `requires_lab` item creates exactly one candidate.
3. Paid RME invoice with multiple `requires_lab` items creates one candidate per eligible item.
4. Calling the integration service twice for the same paid invoice is idempotent (no duplicates).
5. Duplicate prevention: second call returns existing candidates, does not create new ones.
6. Branch isolation: Branch A payment creates no candidates visible from Branch B context.
7. RME payment still does not create records in `trx_payments` (lab billing table).
8. RME full-payment-only enforcement remains intact (existing Sprint 20 tests must stay green).
9. Failed candidate generation does not corrupt payment state under Option 2 transaction strategy.
10. Candidate status transitions: `pending_review → converted_to_lab_order` creates LabOrder.
11. Candidate status transitions: `pending_review → rejected` does not create LabOrder.

**Regression tests (must remain green):**

- `php artisan test --filter=RME` — all 283 Sprint 20 RME tests pass.
- Full test suite passes before Phase 21.2 merge.

---

## 15. Migration Planning Notes

### 15.1 New Table: `trx_lab_case_candidates` (if LabCaseCandidate path is approved)

```
trx_lab_case_candidates
├── id                     bigint, PK
├── branch_id              FK → mst_branches.id, not null, indexed
├── clinic_visit_id        FK → trx_clinic_visits.id, not null
├── rme_invoice_id         FK → trx_rme_invoices.id, not null
├── rme_invoice_item_id    FK → trx_rme_invoice_items.id, not null
├── patient_id             FK → mst_patients.id, not null
├── doctor_id              FK → mst_doctors.id, nullable
├── treatment_id           FK → mst_treatments.id, nullable
├── medical_record_id      FK → trx_medical_records.id, nullable
├── source_description     string, not null
├── quantity               integer, not null, default 1
├── estimated_price        decimal(15,2), nullable
├── status                 enum(pending_review, converted_to_lab_order, rejected, cancelled)
├── converted_lab_order_id FK → trx_lab_orders.id, nullable
├── reviewed_by            FK → users.id, nullable
├── reviewed_at            timestamp, nullable
├── notes                  text, nullable
├── metadata               json, nullable
├── created_by             FK → users.id, nullable
├── timestamps             (created_at, updated_at)
└── soft_deletes           (deleted_at) — optional, for void scenario
```

**Unique index:** `UNIQUE(rme_invoice_item_id)` — enforces one candidate per source item
(unconditional, ignoring deleted_at for simplicity; adjust if soft-delete void scenario requires
active-only uniqueness).

### 15.2 No Existing Table Changes (Phase 21.1 scope)

- `mst_treatments.requires_lab` already exists — no migration needed.
- `trx_rme_invoice_items` — no new columns in Phase 21.1.
- `trx_lab_orders` — source reference columns deferred; add only if direct LabOrder path is
  chosen in a future phase.

### 15.3 If Direct LabOrder Path Is Chosen Later (Option A)

Add to `trx_lab_orders`:
- `rme_invoice_id` — nullable FK → `trx_rme_invoices.id`
- `rme_invoice_item_id` — nullable FK → `trx_rme_invoice_items.id`, unique when not null

Add to `trx_lab_order_items`:
- Source treatment reference for display purposes only; `lab_service_id` must still be resolved.

---

## 16. Security / Authorization Planning

| Role | Capability |
|---|---|
| Cashier (`manage_rme_billing`) | Triggers lab candidate generation indirectly via payment — no direct candidate management |
| Admin Lab (`manage_lab_orders` or new permission) | Reviews candidates queue, approves or rejects, converts to LabOrder |
| Doctor | No candidate management unless explicitly approved by project owner |
| Owner / Super Admin | Read-only view of all candidates per existing multi-branch visibility rules |

**Policy requirements:**
- `LabCaseCandidatePolicy` must gate all actions on `branch_id` matching `BranchContext`.
- Admin Lab may only act on candidates in their active branch.
- Conversion to LabOrder requires the same permission as creating LabOrders manually.

---

## 17. Deployment Notes

- No VPS deployment in Phase 21.1 — documentation only.
- Future deployment of Phase 21.2 changes must:
  1. Run `pg_dump` backup before any migration.
  2. Run `php artisan migrate --force` (never `migrate:fresh` on VPS).
  3. Run new seeders only if idempotent.
  4. Verify `php artisan test` passes on staging before VPS migration.
  5. Check `APP_ENV=production` before running migrations on VPS.

---

## 18. Open Questions

The following questions must be answered before Phase 21.2 implementation begins:

1. **LabCaseCandidate or direct LabOrder?** This document recommends LabCaseCandidate for the
   pilot. Confirm with project owner before Phase 21.2 coding starts.

2. **Who reviews RME-origin lab candidates?** Admin Lab role, or a new role? What permission
   string should gate the review queue?

3. **Is full payment required before lab work, or can Admin Lab override?** Current architecture
   requires payment. Override path is out of scope until installment payment (Phase 21.4) is
   designed.

4. **What due date should lab candidates use?** Options: fixed N days from invoice date,
   configurable per treatment, or set manually by Admin Lab at review time.

5. **Should each `requires_lab` item produce a separate LabOrder, or are items grouped by
   visit/invoice into one LabOrder?** Current architecture: one candidate per item (simplest for
   pilot). Grouping adds complexity and is deferred.

6. **How should cancelled or voided RME invoices affect existing candidates?** PAID invoices are
   currently terminal — void capability would need to be added first.

7. **Should the RME cashier receipt display lab work reference?** Candidate ID or count to be
   shown on receipt after payment? Deferred to Phase 21.2 UI phase.

8. **What exact flag determines lab requirement?** Confirmed: `mst_treatments.requires_lab`
   boolean (already exists). No additional flag needed. Question resolved.

9. **Treatment → LabService mapping strategy?** This gap must be addressed before direct
   LabOrder creation is possible. Options: manual admin mapping table, or text-description-only
   LabOrderItems with null `lab_service_id`. Must be decided in Phase 21.2.

---

## 19. Recommendation

**For Sprint 21 pilot:**

> Create `LabCaseCandidate` records after RME invoice status becomes `PAID`.
> One candidate per eligible `trx_rme_invoice_items` row where
> `treatment.requires_lab = true`.
> Use idempotent `firstOrCreate` on `rme_invoice_item_id` as the unique source reference.
> Enforce branch isolation via `BranchContext`.
> Do not create lab payment records from RME payment.
> Convert to real `LabOrder` only after Admin Lab review and approval.

This approach is the safest path for the pilot phase. It:
- Does not risk blocking cashier operations on a lab generation bug.
- Does not require treatment → lab service mapping at generation time.
- Provides Admin Lab review before real lab work is commissioned.
- Is trivially idempotent and easy to test.
- Preserves all Sprint 20 payment contracts unchanged.

---

## 20. Phase 21.2 Implementation Readiness

Upon approval of this architecture document, the following tasks are ready to start:

| Order | Task | File / Location |
|---|---|---|
| 1 | Write integration tests (tests-first) | `tests/Feature/RME/LabIntegrationTest.php` |
| 2 | Write migration for `trx_lab_case_candidates` | `database/migrations/` |
| 3 | Write `LabCaseCandidate` model | `app/Modules/LabOrder/Models/LabCaseCandidate.php` or new module |
| 4 | Write `RmeLabIntegrationService` | `app/Modules/RmeInvoice/Services/RmeLabIntegrationService.php` |
| 5 | Bind service in `AppServiceProvider` or module provider | `app/Providers/` |
| 6 | Call integration service after payment in `RmePaymentService::pay()` | `app/Modules/RmeInvoice/Services/RmePaymentService.php` |
| 7 | Write `LabCaseCandidatePolicy` | `app/Modules/LabOrder/Policies/LabCaseCandidatePolicy.php` |
| 8 | Add minimal Admin Lab candidate queue route + controller | `routes/web.php`, new controller |
| 9 | Add candidate queue Blade view | `resources/views/lab/candidates/` |
| 10 | Update RME cashier receipt to show lab work status | `resources/views/rme/cashier/receipt/show.blade.php` |
| 11 | Seed `requires_lab = true` on relevant treatments | `database/seeders/` |
| 12 | Update `docs/sprint_21_planning.md` Phase 21.2 status | `docs/sprint_21_planning.md` |

All Phase 21.2 coding tasks are blocked on project owner approval of this architecture document.

---

*End of Sprint 21 Phase 21.1 Architecture Document.*  
*Design only — no application code changed.*  
*Next: project owner reviews and approves → Phase 21.2 implementation begins.*

---

## Phase 21.3 — Admin Lab Candidate Queue UI

**Status:** COMPLETE (2026-06-11)

### Summary

Phase 21.3 adds a read-only Admin Lab queue for `LabCaseCandidate` records.
No conversion to `LabOrder` is implemented in this phase.

### Routes

```
GET  /lab/case-candidates            → lab-case-candidates.index
GET  /lab/case-candidates/{id}       → lab-case-candidates.show
```

### Authorization

`LabCaseCandidatePolicy` uses existing `view_lab_orders | manage_lab_orders` permissions.
Branch isolation enforced: `candidate->branch_id` must equal `BranchContext::forUser($user)`.

### UI

- TailAdmin shell (`x-settings-shell`)
- Index: filter by status, search by patient/doctor/description, paginated table
- Show: full candidate detail with patient, visit, invoice, treatment, and estimated price
- Sidebar: "Kandidat Lab RME" item visible to `view_lab_orders | manage_lab_orders`

### Phase 21.4 — Conversion Implemented (2026-06-11)

**Branch:** `feature/sprint-21-candidate-to-laborder`
**Tag:** `sprint-21-phase-21-4-candidate-to-laborder`

Conversion is explicit/manual via `LabCaseCandidateConversionService::convertToLabOrder()`.

| Rule | Implementation |
|---|---|
| Trigger | Admin Lab POST `lab-case-candidates.convert` from candidate show page |
| `lab_service_id` | Required in payload — never inferred from `treatment_id` |
| Idempotency | Row lock + return existing `LabOrder` if `converted_lab_order_id` set |
| Branch | `BranchContext::requireId()` must match `candidate.branch_id` |
| Payment | No `trx_payments` / `trx_invoices` created |
| RME state | Invoice, payment, visit status unchanged by conversion |
| Authorization | `create_lab_orders` or `manage_lab_orders` + `LabCaseCandidatePolicy::convert` |

Candidate show page displays conversion form only for `@can('convert', $candidate)` (pending status).
Converted candidates link to `lab-orders.show`.

### Phase 21.5 — Workflow Visibility Polish (2026-06-11)

**Branch:** `feature/sprint-21-rme-lab-workflow-polish`
**Tag:** `sprint-21-phase-21-5-rme-lab-workflow-polish`

Read-only UI polish across the integration path. No changes to payment, candidate generation, or conversion services.

| Surface | Visibility added |
|---|---|
| RME invoice show | Status Pekerjaan Lab RME — counts, per-item status, authorized candidate/LabOrder links |
| RME receipt | Compact Kandidat Lab RME section (hidden on print) |
| Candidate index | Lab Order number column when converted |
| Candidate show | Linked RME invoice, visit number, conversion metadata, Belum dikonversi state |
| Lab order show | Sumber RME block when `LabOrder::rmeLabCaseCandidate()` exists |

Relations used (no new migrations): `RmeInvoice::labCaseCandidates()`, `LabCaseCandidate::convertedLabOrder()`, `LabOrder::rmeLabCaseCandidate()`.
