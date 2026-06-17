# Sprint 30 — Pilot Execution Bugfix & Operational Smoke

Status: Draft / Local Validation Complete

**Baseline:** Sprint 29.5 GO at `721bb55`

This sprint moves from Sprint 29 planning/checklist stabilization into **local pilot
execution simulation, operational smoke coverage, and safe bugfixes** for core clinic/lab
workflows — performed entirely on the local codebase and local test database, before any
VPS/production pilot action.

## Baseline lineage

- Sprint 29.0 GO: `sprint-29-phase-29-0-pilot-stabilization-backlog-prioritization-go` — merge `21ff95a`
- Sprint 29.1 GO: `sprint-29-phase-29-1-p0-p1-rme-control-workflow-regression-stabilization-planning-go` — merge `39b4fd9`
- Sprint 29.2 GO: `sprint-29-phase-29-2-cashier-payment-receivable-high-risk-stabilization-planning-go` — merge `266a0d2`
- Sprint 29.3 GO: `sprint-29-phase-29-3-whatsapp-reminder-manual-pilot-sop-go` — merge `06c5d81`
- Sprint 29.4 GO: `sprint-29-phase-29-4-monitoring-backup-restore-rehearsal-non-production-target-go` — merge `b6334fc`
- Sprint 29.5 GO: `sprint-29-phase-29-5-pilot-safety-review-final-stabilization-checklist-go` — merge `721bb55`

Sprint 30 baseline HEAD: `721bb55`.

## Scope

- Execute the Sprint 29.5 pilot operational smoke checklist locally (defined in 29.5, executed here).
- Validate the most important clinic/lab operational paths via targeted local tests.
- Identify and safely fix any real local bug surfaced during smoke (none required this pass).
- Add a Sprint 30 documentation + checklist test deliverable.
- Reference Sprint 29.4 / 29.5 monitoring + backup/restore evidence readiness without executing them.

In-scope operational paths:

- Patient registration / patient identity
- RME visit creation
- Odontogram / treatment note path
- Invoice creation
- Payment recording
- Receivable / piutang tracking
- Receipt / print / export readiness
- Cashier workflow
- RME control workflow
- WhatsApp manual reminder evidence path
- Reporting / export / print path
- User role / menu / permission access for pilot roles
- Monitoring / backup / restore rehearsal evidence readiness

## Safety boundaries

Sprint 30 is **local codebase and local test hardening only**.

- No production code change beyond safe, test-covered bugfixes.
- No migration.
- No deployment.
- No production/VPS access.
- No real backup execution.
- No real restore execution.
- No destructive DB action against production.
- No cron/scheduler/job/queue/notification automation.
- No WhatsApp sending automation.
- No external service call.
- No dependency/package install.
- No `.env` change.
- No broad refactor.
- No GO tag in this local pass.

## Local operational smoke plan

The smoke is executed as targeted local feature tests against the in-scope routes/controllers.
Routes were confirmed present via `php artisan route:list`; coverage was confirmed by running
the existing targeted suites. No code was edited during discovery.

| Path | Route(s) confirmed | Smoke method |
| --- | --- | --- |
| Patient registration / identity | `rme.visits.patient-options`, patient master | `PatientRegistrationTest` |
| RME visit creation | `rme.visits.create/store/show` | `ClinicVisitTest` |
| Odontogram / treatment note | `rme.visits.odontogram.store`, `rme.odontograms.*` | `OdontogramTest` |
| Medical record finalize | `rme.visits.medical-record.finalize` | `MedicalRecordFinalizationTest` |
| Invoice creation | `invoices.store`, `rme.cashier.store` | `InvoicePaymentTest` |
| Payment recording | `invoices.payments.store`, `rme.cashier.payment` | `InvoicePaymentTest`, `CashierBillingTest` |
| Receivable / piutang | `rme.cashier.receivables`, follow-ups | `RmeControlVisitReceivableCarryOverPaymentTest` |
| Receipt / print / export | `rme.cashier.*.receipt`, `rme.visits.pdf/print`, `rme.reports.*.export` | `ExportReportTest`, `RevenueAccuracyTest` |
| Cashier workflow | `rme.cashier.*` | `CashierBillingTest` |
| WhatsApp manual reminder | manual SOP (Sprint 29.3) | `WaReminderTemplateTest` (template only, no send) |
| Role / menu / permission | pilot roles (Owner/Kasir/Perawat/Doctor) | `RmeSmokeTestRouteTest` |

## Smoke scenarios

### RME path

- Patient exists or can be selected — covered.
- Visit can be created — covered.
- RME show/detail page route exists (`rme.visits.show`) — covered.
- Odontogram/treatment notes route/view coverage exists — covered.
- Print route exists (`rme.visits.print`, `rme.visits.pdf`, `rme.odontograms.print`) — covered.
- No obvious permission/menu blocker — covered by role tests.

### Cashier / payment path

- Invoice/payment route coverage exists — covered.
- Payment recording test coverage exists — covered (full + partial + overpayment block).
- Receivable / piutang path is identifiable (`rme.cashier.receivables`) — covered.
- Zero remaining invoice handling not regressed — covered (full-payment path passes).
- Receipt/print/export path identifiable (`rme.cashier.*.receipt`, exports) — covered.

### WhatsApp manual reminder path

- Manual SOP from Sprint 29.3 remains documentation/SOP only — confirmed, unchanged.
- No automation introduced — confirmed.
- Evidence path described for pilot — manual screenshot/log per Sprint 29.3 SOP.

### Monitoring / backup / restore readiness

- Sprint 29.4 and 29.5 docs referenced — see baseline lineage.
- No real backup/restore execution — confirmed.
- Only checklist/evidence readiness documented.

### Role / menu / permission readiness

- Pilot roles identified: Owner, Kasir, Perawat, Doctor (Phase 22.1).
- Route names/menu references verified via smoke route test.
- WATCH item: deeper interactive runtime/browser testing deferred to manual pilot session.

## Findings table

| ID | Path | Severity | Finding | Status |
| --- | --- | --- | --- | --- |
| S30-01 | RME core (visit/odontogram/MR/finalize) | — | All targeted tests pass | No bug |
| S30-02 | Cashier / payment / invoice | — | Full + partial + overpayment guard pass | No bug |
| S30-03 | Receivable / piutang carry-over | — | Carry-over payment path passes | No bug |
| S30-04 | Reporting / export / revenue accuracy | — | Export + revenue accuracy pass | No bug |
| S30-05 | WhatsApp reminder template | — | Template only; no automation; SOP intact | No bug |
| S30-06 | Role / menu / permission (pilot roles) | — | Kasir/Owner/Doctor route gating correct | No bug |

## Bugfix summary

No production code bugfix required in this local pass. All in-scope operational smoke paths
pass at baseline `721bb55`. Sprint 30 delivers documentation + a checklist completeness test.

## Validation results

Local-only validation:

- `php artisan test` (core smoke: CashierBilling, ClinicVisit, Odontogram, MedicalRecordFinalization, PatientRegistration, InvoicePayment): **202 passed (548 assertions)**.
- `php artisan test` (secondary smoke: ReceivableCarryOver, ExportReport, RevenueAccuracy, WaReminderTemplate, RmeSmokeTestRoute): **101 passed (266 assertions)**.
- `php artisan test --filter=Sprint30PilotExecutionBugfixOperationalSmoke`: checklist completeness — PASS.
- `vendor/bin/pint --test` on the Sprint 30 test file: PASS.
- `git diff --check`: clean.

Total operational smoke: **303 tests passed** across the in-scope paths. No regressions.

## Remaining WATCH items

- Interactive browser/UI smoke (handwriting canvas, print preview rendering) requires a manual
  pilot session; not automatable in this pass.
- Real monitoring/backup/restore rehearsal remains evidence-template-only per Sprint 29.4/29.5
  — to be executed against a non-production target during the supervised pilot, not here.
- WhatsApp manual reminder remains a manual operator SOP; evidence capture is operator-driven.
- Deeper multi-branch concurrency/load behavior is out of scope for local smoke.

## No-production / no-deployment statement

This Sprint 30 pass performed **no production action**. No VPS/production access, no deployment,
no real backup, no real restore, no destructive DB action, no automation, no external service
call, no dependency install, no `.env` change. All work is local codebase + local test only.

## Next recommended phase

Sprint 30 next phase: supervised local-then-pilot operational smoke **execution session** with
operator evidence capture (RME → cashier → receivable → receipt/export → WhatsApp manual
reminder), followed by the Sprint 29.4/29.5 monitoring + backup/restore rehearsal against a
**non-production** target. Production/VPS pilot action remains gated on explicit owner approval.

GO CANDIDATE FOR PR REVIEW
