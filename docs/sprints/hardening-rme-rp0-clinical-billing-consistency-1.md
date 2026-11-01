# HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1

**Program:** DAENGTISIAMS-RME-BILLING-CORRECTION · follow-up of Sprint 6/7 (rule 217) security finding MEDIUM-1.
**Rule:** `.cursor/rules/218-rme-clinical-billing-consistency.mdc` · **ADR:** `docs/adr/0015-rme-clinical-billing-consistency.md`
**Owner decisions:** CB-Q1, CB-Q2, CB-Q3 (2026-11-01) in `docs/decisions/rme-billing-correction-owner-decisions.md`.
**Flag:** `rme.zero_value_closure` — unchanged, **OFF**.

## 1. Status

- Implementation: complete (detective control, per owner CB-Q2).
- Enforcement: **detective only** — by owner decision, not by omission. No signal ever refuses a closure; only a
  fault in the evaluator itself does (it fails closed, rule 218 §5).
- MEDIUM-1: **`PARTIALLY_MITIGATED`** — never `CLOSED` (rule 218 §8).
- Deployment / production verification / GO tag: recorded in §8 by the evidence commit.

## 2. Baseline (verified before any change)

| Fact | Value |
|---|---|
| Canonical base (remote, after fetch) | `89906fd5` (docs evidence of the Rp0 hardening) |
| Production HEAD / tree | `ba33f9bb` / `2d1b684b` (runtime of `hardening-rme-rp0-closure-owner-decisions-and-paid-treatment-1-go`) |
| Production tree status | clean except the intentionally preserved `storage/runtime-home/` |
| Production PostgreSQL | 16.15 |

## 3. Clinical source authority audit (Gate 1)

Method: source read on the canonical base; Graphify rebuilt in the task worktree (42 548 nodes / 62 472 edges —
the pre-existing graph dated 2026-10-24 predated Sprints 4–6); production measured in a session forced
read-only (`default_transaction_read_only=on`), aggregates only, `laravel.log` 1 443 432 bytes before and after.

**Finding: there is no structured record of a treatment the doctor performed.** Matrix in rule 218 §1. Graphify:
every path between `MedicalRecord` / `MedicalRecordDiagnosis` / `Odontogram` / `RmePrescription` and `Treatment` /
`RmeInvoiceItem` runs only through framework base classes (`Controller`, `Model`, `AuthorizesRequests`). SATUSEHAT
builds its FHIR Procedure from the invoice lines.

Production, 2026-11-01 05:20 UTC (13:20 WITA):

| Measure | Value |
|---|---|
| Invoices / total | 10 / Rp31 048 000 (PAID 7, PARTIAL 1, UNPAID 2) |
| Payments / total | 10 / Rp22 548 000 |
| NO_CHARGE invoices | 0 |
| #36 / #42 / #43 | UNPAID 0 / PAID 5 000 000 / PAID 12 000 000 (correction v0/v1/v1) |
| Invoices whose Tindakan Awal is NOT on the bill | **6 of 10** (all legitimately paid) |
| Structured diagnoses | 0 |
| Medical records | 10 (all final, handwriting) |
| Odontograms charted | 8 (5 with no earlier chart; restorative transitions observed: 0) |
| failed_jobs | 0 |

## 4. Owner decisions (in-session, 2026-11-01)

The audit was shown; three questions were asked; each answer was the recommended option.
CB-Q1 structured doctor input is the authoritative evidence · CB-Q2 detective only · CB-Q3 the doctor input is a
separate sprint. Recorded as `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`; no signed artefact.

## 5. What was built

- `RmeClinicalBillingConsistency` (pure, Support) + `RmeClinicalBillingConsistencyResult` — statuses
  `REVIEW_REQUIRED` / `INSUFFICIENT_STRUCTURED_EVIDENCE` / `NOT_APPLICABLE` (+ oversight label `NOT_EVALUATED`);
  signals `CBC_REGISTERED_TREATMENT_NOT_BILLED`, `CBC_ODONTOGRAM_PROCEDURE_CHANGE`. Never `CONSISTENT`, never
  `CONFIRMED_DISCREPANCY`. Reuses `RmeZeroValueEligibility::lineRefusalCode()` (no second definition of "free").
- `RmeClinicalBillingConsistencyService` — gathers evidence through repository interfaces; no actor, no write,
  no lock.
- `OdontogramRepositoryInterface::latestChartedBeforeVisit()` — strictly-earlier, same-patient, RME-branch-scoped
  baseline (the existing history query also returns later charts).
- `RmePaymentService::closeZeroValueInvoice()` — evaluates after eligibility on the locked rows, snapshots into the
  existing audit row; a signal never refuses, an evaluator fault refuses the closure (fail closed, nothing written).
  `zeroValueClosurePanel()` — `consistency` for the cashier notice (a fault there only hides the notice).
- Cashier panel notice (`data-zero-value-consistency-notice`) — a count and a master-data name only.
- Oversight "Tanpa Biaya (Rp0)" — status column, signal labels, counts, two validated filters, read from the
  audit snapshot.
- SOP section "Peringatan … (rule 218)"; rule 217 §8 forward reference; billing domain rule index line.

**No migration, no seeder, no permission, no route, no new flag.** The snapshot lives in the existing jsonb
`sys_audit_logs.new_values`; the financial schema is unchanged.

## 6. Tests and quality gates

All runs on the final tree; one test process at a time.

| Gate | Result |
|---|---|
| TDD RED (new suites, before implementation) | 40 tests: 19 failed, 15 errored, 6 passed — the 6 are invariant controls that must pass before and after |
| New suites | 49 tests (`…ClinicalConsistencyTest` 38, `…ClinicalConsistencyOversightTest` 11) + concurrency case **M** |
| `tests/Feature/RmeZeroValueClosure` (SQLite) | 158 tests: 145 passed, 13 skipped (PostgreSQL-only concurrency cases), 0 failed, 565 assertions |
| Financial regression (**PostgreSQL 16**, 19 directories) | **4 108 passed, 0 failed, 0 skipped, 16 669 assertions** — RmeZeroValueClosure 158 (all 13 real concurrency cases ran), RME 2 038, RmeInvoiceCorrection 362, RmeBillingDispute 175, Ui 176, LegacyOdontogram 205, AccessControl 174, RmeRefundRequest 140, RmeRefundSettlement 128, RmeTariffAuthority 110, ClinicOversight 95, PatientMerge 74, Pilot 74, RmePaymentIdempotency 68, RmeManualPricing 62, RmeOwnerReferenceRegistry 33, BranchScope 17, Owner 12, DataQuality 7 |
| Concurrency case M | a Tindakan Awal edit holding the visit row lock vs a closure: the closure waited (> 1 s), closed, and snapshotted the COMMITTED value |
| Mutation (manual guard-removal harness, revert by copy, checksum-verified) | **26 mutants: 25 killed, 1 equivalent, 0 real survivors**. M23 (empty branch set) is equivalent: Laravel's base grammar compiles an empty `whereIn` to `0 = 1` and neither the PostgreSQL nor the SQLite grammar overrides it, so the guard is defence in depth; pinned by its own test anyway. M01 survived the first campaign and was closed with a pure NOT_APPLICABLE test |
| Governance contracts | 65 passed / 2 857 assertions (critical-gate suite coverage, DEVFLOW instruction system, production shell-command guard, program-closure contract) |
| DEVFLOW / foundation gates | all GO: base-ref, manifest, scope-audit, devflow, shared-service, ci-runtime-control, security-compliance, enterprise-documentation, cicd-enterprise, ui-governance, roadmap, self-hosted-runner |
| Static | pint passed, `git diff --check` clean, Blade compiles |
| CI classifier | `unknown_high_risk` (strongest profile). Full Suite: classifier says required, the global temporary policy defers it → **SKIPPED, not PASS** |

## 7. Findings and residual risk

Independent security review: **0 CRITICAL, 0 HIGH**.

| # | Severity | Finding | Outcome |
|---|---|---|---|
| F1 | MEDIUM | The notice told the cashier to bill the procedure at more than Rp0 — impossible on a Rp0-original invoice (D28) | Fixed: the notice says do not close, ask the doctor, escalate; pinned by a test |
| F2 | LOW | An exception in the evaluator surfaced as HTTP 500 in the closure and on the cashier page | Fixed: the closure fails CLOSED with a clear `zero_value` refusal; the page renders without the notice |
| F3 | LOW | The odontogram baseline reads across all RME branches, not the cashier's working branch | Accepted (engineering choice, documented in rule 218 §4): patient history is cross-branch, the output is a count |
| F4 | LOW | The odontogram signal label implied a procedure AT THIS visit, but the baseline is only the previous chart — on a Kontrol (the main Rp0 population) a filling done and billed on the parent visit fires it | Fixed: relabelled "Status gigi berubah sejak odontogram sebelumnya"; the notice asks whether it was done at this visit and says so when the baseline is the parent visit's chart |
| F5 | INFO | "Tindakan Awal berbayar" claimed more than the predicate shows (it is "not an approved free service", which also covers voucher-excluded, uncategorised and lab-requiring treatments) | Fixed: worded "bukan layanan gratis yang disetujui" |
| F6 | INFO | The service docblock and rule 218 §5 claimed every input is stable; treatment master data and branch RME status are read unlocked | Fixed: wording corrected (clinical inputs stable at `cashier_pending`, master data read unlocked; the snapshot is the committed state at closure) |
| F7 | INFO | Edge cases of the bounded baseline search | Accepted: each can only miss a signal, never raise a false one (rule 218 §8); detail in the private security record |
| F8 | INFO | Oversight `not_evaluated` count could disagree with the row labels | Fixed: both use "no status in `RECORDED_STATUSES`" |
| F9 | INFO | Two consecutive docblocks on the audit payload builder | Fixed: merged |

- **MEDIUM-1 = `PARTIALLY_MITIGATED`.** Handwriting-only procedures raise no signal; detective only.
- **No in-app remediation path** when a warning is right (pre-existing): D28 forbids correcting a Rp0-original
  invoice, an RME invoice has no void, a visit has one active invoice. SOP: do not close, escalate. Input to the
  CB-Q1 sprint and to D21g/D28.
- **Cashier clinical summary (pre-existing, Sprint 62 `e48c6450`)** already shows the cashier this visit's
  odontogram including its notes. Not widened or narrowed here; a product decision.
- Tindakan Awal is noisy evidence (6/10 mismatches on legitimate production invoices).

## 8. Release evidence

Recorded by the evidence commit after merge, deploy and production verification.
