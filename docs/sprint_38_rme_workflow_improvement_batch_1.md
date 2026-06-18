# Sprint 38 — RME Workflow Improvement Batch 1

Status: Draft / Local Validation Pending
Baseline: Sprint 37 GO at 078be4e
Scope: Controlled RME workflow improvement Batch 1 / local implementation / targeted regression only

## Purpose

Sprint 38 follows Sprint 37 controlled roadmap execution Batch 1 and governance review. It implements
the first controlled **RME Workflow Improvement Batch 1** selected by that governance review.

It focuses on:

- patient identity handling (No. KTP binding + duplicate blocking),
- WhatsApp (WA) number workflow clarity,
- treatment consent (TTD Surat Persetujuan Tindakan) checklist clarity,
- RME print / privacy protection (KTP never printed),
- targeted regression coverage.

Sprint 38 does **not** deploy and does **not** touch production/VPS. It is a small, controlled, local,
and reversible improvement built on the existing baseline.

## Baseline references

- Sprint 36 GO: `sprint-36-operational-governance-maintenance-cadence-expansion-readiness-go` at `7a1f959`
- Sprint 37 GO: `sprint-37-controlled-roadmap-execution-batch-1-governance-review-go` at `078be4e`
- Sprint 37 feature commit: `a2d6de8`

Latest stable base HEAD for Sprint 38 work: `078be4e`.

## Discovery findings

Read-only discovery (Graphify map + targeted `rg`) confirmed that the functional foundation for
Batch 1 already exists from earlier RME patient-format work and is committed on the baseline:

- `mst_patients.ktp_number` — `string(16)`, nullable, **unique**
  (migration `2026_06_14_120001_add_rme_patient_identity_fields_to_mst_patients_table`).
- `mst_patients.whatsapp_number` — `string(50)`, nullable (same migration).
- `trx_clinic_visits` consent fields (`consent_signed_by_patient`, `consent_signed_by_doctor`,
  `consent_verified_at`, `consent_verified_by`) —
  migration `2026_06_14_120002_add_consent_fields_to_trx_clinic_visits_table`.
- Duplicate-KTP blocking already enforced in `StorePatientRequest` and `UpdatePatientRequest`
  (`Rule::unique('mst_patients','ktp_number')` with `->ignore()` on update) and in the RME
  new-patient visit flow, with the exact message `Nomor KTP sudah terdaftar pada pasien lain.`
- No. KTP is already **not** rendered on the RME visit detail or RME print output; WA is shown.
- The cashier consent checklist is already enforced in `RmePaymentService` and surfaced on the
  cashier payment form, and `ClinicVisit::hasVerifiedConsent()` already exists.

Because the functional rules already hold, Sprint 38 layers **workflow clarity** on top of the
baseline instead of reworking payment, generation, or conversion logic. No schema change was
required.

## Implemented scope summary

### A. Patient identity (KTP / no_ktp) handling
- Reused the existing `mst_patients.ktp_number` field (no new column, no migration).
- Confirmed it binds patient identity when available and stays nullable for legacy rows.

### B. Duplicate identity validation
- Confirmed duplicate KTP is blocked on patient create, patient update (while allowing the patient
  to keep its own KTP), and through the RME new-patient visit registration flow.
- Regression-covered by the existing `RmePatientFormatConsentTest` and re-asserted by the Sprint 38
  checklist test.

### C. WA number workflow clarity
- Added operational help text on the patient form (`settings/patients/_form`) and the RME
  new-patient visit form (`rme/visits/_form`): WA is used for visit attendance confirmation and
  receivable/piutang follow-up, and the system sends no automated WhatsApp message.
- Surfaced WA usage context on the RME visit detail (`rme/visits/show`).
- No WhatsApp message is sent; no WhatsApp automation; no external service is called.

### D. Treatment consent checklist clarity
- Surfaced a cashier-facing, **read-only** `TTD Surat Persetujuan Tindakan` verification status on
  the RME visit detail (verified / not yet verified), reusing `ClinicVisit::hasVerifiedConsent()`.
- The binding cashier checklist remains in the payment flow (unchanged).
- No digital signature capture, no signature image upload, no external signature integration.

### E. RME print / privacy protection
- Confirmed and regression-asserted that No. KTP is never rendered on the RME visit detail or print
  output. Only WA appears, where operationally intended.

### Tests added / updated
- New: `tests/Feature/Sprint38/Sprint38RmeWorkflowImprovementBatch1Test.php` — Sprint 38 doc/history
  checklist assertions plus clarity-copy and KTP-privacy assertions against the RME visit detail and
  patient form.
- Existing functional coverage retained: `tests/Feature/RME/RmePatientFormatConsentTest.php`
  (duplicate KTP create/update, keep-own-KTP, KTP hidden on detail/print, consent enforcement).

### Migration added
- None. No schema change was required; the identity, WA, and consent columns already exist.

### Deferred items
- Digital signature capture/upload for Surat Persetujuan Tindakan — explicitly out of scope; consent
  remains a physical-document checklist verified by the cashier.
- Any actual WhatsApp sending/automation — explicitly out of scope.

## Safety boundaries

- no production/VPS access
- no deployment
- no production migration
- no external WhatsApp send
- no WhatsApp automation
- no signature upload/capture integration
- no backup/restore/rollback execution
- no destructive operation
- no `.env` change
- no dependency/package install
- no GO tag

## Validation commands

```bash
php artisan test --filter=Sprint38RmeWorkflowImprovementBatch1
php artisan test --filter=Rme
php artisan test --filter=ClinicVisit
php artisan test --filter=Patient
vendor/bin/pint --test
git diff --check
```

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1.

Sprint 39 should focus on controlled cashier/payment/receivable workflow improvement based on the
Sprint 37 roadmap governance and the Sprint 38 RME workflow results — for example cashier
verification flow clarity, payment status visibility, and receivable/piutang follow-up tracking —
keeping the same controlled, local, targeted-regression, no-production-deployment discipline.
