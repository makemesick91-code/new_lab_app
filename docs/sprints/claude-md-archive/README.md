---
kb_status: historical
kb_verified_against: 53d76643
kb_updated: 2026-10-26
---
# CLAUDE.md archive — index

Back to [00-INDEX](../../00-INDEX.md).

Until commit `53d76643` (2026-10-25) `CLAUDE.md` was 1,564,863 bytes: 240 sprint and foundation records, loaded into every Claude Code session. DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1 moved every record here **verbatim** and replaced `CLAUDE.md` with the critical global rules (CG-01…CG-24). Domain rules now load on demand from `.claude/rules/`.

**Status: HISTORICAL.** Nothing here is loaded automatically, and nothing here is edited: a correction goes in a newer document that cites the old one. A record may have been superseded by a later sprint; the current rule lives in `CLAUDE.md`, the matching `.claude/rules/` file and `.cursor/rules/`. Code, migrations, tests and config remain the authority.

**How to use.** Grep, never read a whole file:

```bash
grep -n "ENT-10" docs/sprints/claude-md-archive/*.md
```

**Integrity.** [manifest.json](manifest.json) records the sha256 of every section (sha256 over the UTF-8 section text (from its "## " heading line up to, excluding, the next "## " line) with trailing newlines removed). `tests/Feature/Foundation/DevflowAiInstructionSystemTest.php` re-hashes the archive on every critical CI run, so a lost, altered or extra record fails the gate.

**Files**

| File | Sections | Domain rule file(s) |
|---|---:|---|
| [00-global.md](00-global.md) | 3 | — (global, rewritten into `CLAUDE.md`) |
| [01-rme-clinical.md](01-rme-clinical.md) | 15 | `.claude/rules/rme-clinical.md` |
| [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | 30 | `.claude/rules/rme-billing.md` |
| [03-branch-context.md](03-branch-context.md) | 12 | `.claude/rules/branch-context.md` |
| [04-patient-identity.md](04-patient-identity.md) | 13 | `.claude/rules/patient-identity.md` |
| [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | 19 | `.claude/rules/legacy-rme.md` |
| [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | 6 | `.claude/rules/legacy-rme.md` |
| [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | 19 | `.claude/rules/legacy-import.md` |
| [06-legacy-import-odontogram-patient.part2.md](06-legacy-import-odontogram-patient.part2.md) | 2 | `.claude/rules/legacy-import.md` |
| [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | 19 | `.claude/rules/doctor-access.md`, `.claude/rules/doctor-device-android.md` |
| [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | 14 | `.claude/rules/doctor-access.md`, `.claude/rules/doctor-device-android.md` |
| [07-doctor-access-device.part3.md](07-doctor-access-device.part3.md) | 2 | `.claude/rules/doctor-device-android.md` |
| [08-lab.md](08-lab.md) | 11 | `.claude/rules/lab.md` |
| [09-inventory.md](09-inventory.md) | 5 | `.claude/rules/inventory.md` |
| [10-ui.md](10-ui.md) | 6 | `.claude/rules/ui.md` |
| [11-satusehat.md](11-satusehat.md) | 7 | `.claude/rules/satusehat.md` |
| [12-foundation-enterprise.md](12-foundation-enterprise.md) | 30 | `.claude/rules/foundation-governance.md` |
| [13-cicd-testing.md](13-cicd-testing.md) | 14 | `.claude/rules/cicd-testing.md` |
| [14-deploy-infra-security.md](14-deploy-infra-security.md) | 5 | `.claude/rules/deploy-infra.md` |
| [15-monitoring.md](15-monitoring.md) | 8 | `.claude/rules/monitoring.md` |

**Section index (original order)**

| # | Original line | Record | File | Domain rule file |
|---:|---:|---|---|---|
| 0 | 1 | Foundation-First Sprint Lock | [00-global.md](00-global.md) | `CLAUDE.md` |
| 1 | 5 | Developer Knowledge Base (Obsidian vault: `docs/`) | [00-global.md](00-global.md) | `CLAUDE.md` |
| 2 | 13 | RME Module (Sprint 20) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 3 | 50 | graphify | [00-global.md](00-global.md) | `CLAUDE.md` |
| 4 | 60 | Sprint 21 Phase 21.3 — Admin Lab Candidate Queue UI (2026-06-11) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 5 | 95 | Active Planning — Sprint 60 RME Multi-Page Handwriting Canvas | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 6 | 110 | Sprint 61.0 — Patient Data Completeness Audit & RM Gap Review | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 7 | 135 | Sprint 62.0 — Owner KPI Dashboard (2026-06-27) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 8 | 139 | Sprint 62.1 — RME Doctor-to-Cashier Completion Gate (2026-06-27) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 9 | 143 | Hotfix — RME Partial Payment Completes Visit (2026-06-27) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 10 | 147 | Sprint 62.2 — Patient Outstanding Receivable Carry-over for New Visits (2026-06-27) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 11 | 151 | Sprint 62.3 — Legacy RME Patient Batch Import (2026-06-27) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 12 | 155 | Sprint 63.1 — Structured Odontogram Print Template Conversion (2026-06-28) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 13 | 159 | Sprint 64.0 — Patient-Centric RM Workspace with Swipeable Sheets (2026-06-28) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 14 | 163 | STORAGE-1 — Object Storage Readiness (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 15 | 167 | STATELESS-1 — Runtime Statelessness & Deploy Portability Foundation (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 16 | 171 | LB-1 — Load Balancer Pilot (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 17 | 175 | OBS-1 — Request ID, Correlation ID & Observability Foundation (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 18 | 179 | OBS-2 — Centralized Logging & Error Tracking Readiness (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 19 | 183 | ROADMAP-1 — Foundation Roadmap Canonicalization (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 20 | 187 | ENT-0 — Enterprise Foundation Freeze Rules & Roadmap Reconciliation (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 21 | 191 | ENT-1 — Enterprise Architecture Baseline Lock (2026-07-05) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 22 | 195 | ENT-2 — Database Performance Contract (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 23 | 199 | ENT-3 — Reporting Materialized Summary Expansion (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 24 | 203 | ENT-4 — Redis Cache Enterprise Policy (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 25 | 207 | ENT-5 — Queue, Retry & Failed Job Governance (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 26 | 213 | ENT-6 — Idempotency & Outbox Foundation (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 27 | 217 | ENT-7 — Developer Assistance Console (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 28 | 223 | ENT-8 — Observability & Health Check Pack (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 29 | 229 | ENT-9 — Security & PII Compliance Hardening (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 30 | 235 | ENT-10 — CI/CD Enterprise Gate (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 31 | 241 | ENT-11 — Deployment & Rollback Automation (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 32 | 247 | ENT-12 — Backup & Disaster Recovery Automation (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 33 | 253 | ENT-13 — Load Test 5 Cabang Baseline (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 34 | 259 | ENT-14 — Load Test Scale Projection (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 35 | 265 | ENT-15 — Enterprise Documentation & Runbook (2026-07-06) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 36 | 271 | ENT-16 — Enterprise Foundation Closure GO/NO-GO (2026-07-07) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 37 | 275 | POST-ENT — Enterprise Foundation Runtime Hardening (Queue Worker & Deploy Evidence Timeout) (2026-07-07) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 38 | 279 | UIX-1 — DaengtisiaMS Luxury Healthcare Design System Foundation (2026-07-07) | [10-ui.md](10-ui.md) | `ui` |
| 39 | 283 | UIX-2 — Dashboard Owner Polish (2026-07-07) | [10-ui.md](10-ui.md) | `ui` |
| 40 | 287 | UIX-3 — Kunjungan List Polish (2026-07-07) | [10-ui.md](10-ui.md) | `ui` |
| 41 | 291 | UIX-4 — RME + Odontogram Polish (2026-07-07) | [10-ui.md](10-ui.md) | `ui` |
| 42 | 295 | UIX-5 — Kasir / Payment Polish (2026-07-07) | [10-ui.md](10-ui.md) | `ui` |
| 43 | 299 | UIX-6 — Inventory Polish (2026-07-07) | [09-inventory.md](09-inventory.md) | `inventory` |
| 44 | 303 | UIX-8 — Reports, Print & PDF Polish (2026-07-07) | [10-ui.md](10-ui.md) | `ui` |
| 45 | 307 | UIX-9 — Inventory Analytics, Charts & Workflow Forms Polish (2026-07-08) | [09-inventory.md](09-inventory.md) | `inventory` |
| 46 | 311 | CICD-CTRL-1 — Safe CI Runtime Control & Required-Gate Optimization (2026-07-09) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 47 | 315 | FIX-PRE-68-45 — RME/Owner/Doctor/Inventory-PR Polish Fix Sprint (2026-07-09) | [09-inventory.md](09-inventory.md) | `inventory` |
| 48 | 338 | HOTFIX — FIX-PRE-68-45 Doctor Performance 403 (2026-07-09) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 49 | 342 | HOTFIX — FIX-PRE-68-45 Doctor Performance Treatment Date Table (2026-07-09) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 50 | 346 | SPRINT-68.45 — Inventory Procurement Workflow Stabilization, GR Batch Default Hardening & Vendor Report Governance (2026-07-09) | [09-inventory.md](09-inventory.md) | `inventory` |
| 51 | 350 | MON-1 — Foundation Monitoring & Observability Gap Consolidation (2026-07-09) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 52 | 354 | ROLL-5-1 — Five Branch Controlled Production Rollout Readiness (2026-07-09) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 53 | 358 | ROLL-5-1A — Staging Restore Drill Evidence & Stage-1 GO Clearance (2026-07-10) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 54 | 362 | FIX-PO-MULTI-VENDOR — Item-Level Vendor Selection, Estimated Arrival Date & Supplier PDF (2026-07-10) | [09-inventory.md](09-inventory.md) | `inventory` |
| 55 | 366 | LAB-WORKFLOW-V2 — Cabang Pickup, Courier Transport, Internal/External Production, QC, Delivery Proof & Legacy Inactivation (2026-07-10) | [08-lab.md](08-lab.md) | `lab` |
| 56 | 384 | FIX-LAB-REQUEST-RME-BRANCH-SEARCHABLE-FIELDS (2026-07-10) | [08-lab.md](08-lab.md) | `lab` |
| 57 | 388 | OPS — Lab Request Operator → Cabang RME Mapping (2026-07-10) | [08-lab.md](08-lab.md) | `lab` |
| 58 | 392 | RME-BRANCH-SUN4 — Cabang Sunu + Perawat Online Context (2026-07-11) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 59 | 396 | FIX-LAB-TECHNICIAN-ROLE-ASSIGNMENT-UPLOAD-COMPRESSION (2026-07-11) | [08-lab.md](08-lab.md) | `lab` |
| 60 | 400 | FIX-ADMIN-LAB-LAB-ONLY-ACCESS — Restrict Admin Lab Role to Lab Module & Lab Workflow Only (2026-07-11) | [08-lab.md](08-lab.md) | `lab` |
| 61 | 404 | HOTFIX-LAB-V2-NOTIFICATION-DESTINATION-ROUTING — Recipient-Aware Notification Destinations + Repair Broken Links (2026-07-11) | [08-lab.md](08-lab.md) | `lab` |
| 62 | 408 | DEVFLOW-1 — Safe Sprint Acceleration, Reusable Foundations & Release Automation (2026-07-12) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 63 | 412 | LAB-WORKFLOW-V2-PILOT-UAT-1 — Pilot Operational Readiness, Real-Role UAT, SLA Baseline & Closure (2026-07-12) | [08-lab.md](08-lab.md) | `lab` |
| 64 | 416 | LAB-OPS-READINESS-1 — Lab Workflow V2 Operational Data Readiness Closure (Technician Account Governance & Active External Lab Enablement) (2026-07-12) | [08-lab.md](08-lab.md) | `lab` |
| 65 | 422 | LAB-PROD-2 — Lab Workflow V2 Operational Analytics & KPI (2026-07-12) | [08-lab.md](08-lab.md) | `lab` |
| 66 | 428 | LAB-PROD-3 — Technician Capacity Planning (2026-07-12) | [08-lab.md](08-lab.md) | `lab` |
| 67 | 434 | FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS — Login Recovery & Deploy Runtime Permission Hardening (2026-07-12) | [14-deploy-infra-security.md](14-deploy-infra-security.md) | `deploy-infra` |
| 68 | 440 | SATUSEHAT-1 — Readiness Foundation & Controlled Submission Filter (2026-07-16) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 69 | 446 | SATUSEHAT-2 — Sandbox API Adapter & FHIR Submission (2026-07-16) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 70 | 450 | SATUSEHAT-3 — Dental Use-Case Expansion & Production Readiness (2026-07-16) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 71 | 454 | SATUSEHAT-4A — Credential-Independent Operational Readiness & Data Quality Closure (2026-07-17) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 72 | 458 | SATUSEHAT-4B — Structured Diagnosis Adoption & Clinical Terminology Rollout (2026-07-17) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 73 | 462 | SATUSEHAT-4C — Branch Readiness Remediation & Internal Pilot Operations (2026-07-18) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 74 | 466 | SATUSEHAT-4D — Multi-Branch Readiness Scale-Up & Operational Governance (2026-07-17) | [11-satusehat.md](11-satusehat.md) | `satusehat` |
| 75 | 470 | LEGACY-RME-PDF-1A — Legacy RME PDF Archive Foundation: Schema, Permission & Date Rules (2026-08-06) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 76 | 490 | LEGACY-RME-PDF-1B — Private Upload Runtime, PDF Validation, Queue Processing & Page Rendering (2026-08-06) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 77 | 514 | CICD-CTRL-3 — Dedicated Self-Hosted CI Runner (2026-08-07) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 78 | 538 | LEGACY-RME-PDF-1C — Controlled Publish & Patient History Integration (2026-08-10) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 79 | 554 | LEGACY-RME-PDF-ROLL-1 — Feature Flag Runtime Override & Controlled Enablement Readiness (2026-08-10) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 80 | 568 | LEGACY-RME-PDF-1D — VOID & Clinical Read/Print Completion (2026-08-11) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 81 | 586 | LEGACY-RME-PDF-ROLL-2 — Controlled Pilot Enablement & Operational Readiness (2026-08-11) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 82 | 612 | LEGACY-RME-PDF-FIX-ROLL2-1 — Legacy Eligibility, Multi-Date & RM-Branch Resolution (2026-08-12) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 83 | 632 | LEGACY-RME-PDF-ROLL-3 — Multi-Branch Controlled Migration Rollout (2026-08-12) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 84 | 658 | LEGACY-RME-PDF-ROLL-3 — Wave-1 production evidence + corrective (2026-08-13) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 85 | 670 | LEGACY-RME-PDF-HISTORY-1 — Patient-Centric Clinical History Integration (2026-08-13) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 86 | 692 | LEGACY-RME-PDF-HISTORY-1A — Published Legacy Clinical Read Availability & Read/Write Capability Separation (2026-08-13) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 87 | 716 | LEGACY-RME-PDF-HISTORY-1B — Doctor Clinical Branch Context & Production Read UAT (2026-08-13) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 88 | 744 | LEGACY-RME-PDF-ROLL-4 — Production Migration Operations & Scale-Up (2026-08-14) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 89 | 780 | LEGACY-RME-PDF-ROLL-4-WAVE-1 — Controlled Production Migration Wave-1 (2026-08-14) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 90 | 802 | INFRA-SEC-ENV-1 — Production Secret File Permission Hardening (2026-08-15) | [14-deploy-infra-security.md](14-deploy-infra-security.md) | `deploy-infra` |
| 91 | 818 | INFRA-SEC-RUNTIME-1 — Dedicated Runtime Identity & Co-Tenant Isolation (2026-08-15) | [14-deploy-infra-security.md](14-deploy-infra-security.md) | `deploy-infra` |
| 92 | 840 | DEPLOY-HARDEN-1 — Immutable Deployment Entrypoint & Self-Update Safety (2026-08-15) | [14-deploy-infra-security.md](14-deploy-infra-security.md) | `deploy-infra` |
| 93 | 874 | LEGACY-RME-DATE-TZ-1 — Canonical Clinical Timezone & Date-Boundary Correctness (2026-08-15) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 94 | 896 | DEVFLOW-FIX-BASE-REF-1 — Canonical Remote Base Resolution (2026-08-15) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 95 | 915 | LEGACY-RME-PDF-ROLL-4-WAVE-2 — Controlled Production Migration Wave-2 (2026-08-16) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 96 | 919 | LEGACY-RME-PDF-ROLL-4-WAVE-3 — SKIPPED / NOT REQUIRED (2026-08-16) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 97 | 923 | LEGACY-RME-OPS-CLI-1 — Canonical Import Lifecycle CLI & Abort-Recovery Operations (2026-08-16) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 98 | 947 | LEGACY-RME-SOD-1 — Mandatory Separate Publisher Enforcement & Production Activation (2026-08-16) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 99 | 965 | LEGACY-RME-MASTERDATA-1 — RM 27541 Patient Resolution Investigation & Master-Data Integrity (2026-08-16) | [05-legacy-rme.part1.md](05-legacy-rme.part1.md) | `legacy-rme` |
| 100 | 981 | LEGACY-RME-SOURCE-RM-BINDING-1 — Source RM Capture, Patient-Binding Enforcement & Wrong-Patient Prevention (2026-08-17) | [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | `legacy-rme` |
| 101 | 1009 | CICD-BASELINE-REVERIFY-1 — Full-Suite Baseline Revalidation & Stale Failure-Debt Closure (2026-08-17) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 102 | 1033 | LEGACY-RME-STEADY-STATE-OPS-1 — Operationalization, SOP, Monitoring & Multi-Branch Routine Migration (2026-08-18) | [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | `legacy-rme` |
| 103 | 1055 | LEGACY-RME-DOCTOR-WORKSPACE-1 — Embedded Read-Only Legacy PDF in the Doctor's RME Workspace (2026-08-18) | [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | `legacy-rme` |
| 104 | 1079 | LEGACY-RME-DOCTOR-WORKSPACE-1A — Inline Legacy PDF Pages in the Handwritten RME Swipe Canvas (2026-08-18) | [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | `legacy-rme` |
| 105 | 1101 | LEGACY-RME-PROGRAM-CLOSURE-1 — Final Program Audit, Foundation Revalidation & Production Steady-State Closure (2026-08-18) | [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | `legacy-rme` |
| 106 | 1139 | FIX-LEGACY-RME-ROUTINE-OPS-1 — Routine Batch Operational Surface, Time-Bounded Governance & SOD Staffing Truth (2026-08-19) | [05-legacy-rme.part2.md](05-legacy-rme.part2.md) | `legacy-rme` |
| 107 | 1165 | GLOBAL TEMPORARY FULL-SUITE POLICY — **ACTIVE** (2026-08-19) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 108 | 1191 | CI-TEMP-FULL-SUITE-SCHEDULE-GATE — Temporary Scheduled Full-Suite Suppression (2026-08-19) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 109 | 1209 | FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — Clinic/Cashier Working Branch, Document Identity, WhatsApp Prescriptions, Super-Admin SATUSEHAT (2026-08-20) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 110 | 1233 | FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 — signed consent as the payment gate, RME page ownership, print ownership, one-page kwitansi (2026-08-20) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 111 | 1265 | FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 — explicit examination completion, consent as the RME authoring gate, cashier consent independence, patient odontogram history + Legacy Odontogram (2026-08-21) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 112 | 1285 | POST-RME-ODONTOGRAM-STABILIZATION-1 — odontogram view creates no clinical row, repository SQLite artifact hygiene, RM number on the clinical calendar (2026-08-21) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 113 | 1305 | LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 — an empty odontogram placeholder is not clinical evidence for the archive cutoff (2026-08-22) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 114 | 1321 | TEST-BASELINE-MONITORING-FAILURES-1 — the Monitoring baseline is zero, and it is deterministic (2026-08-22) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 115 | 1339 | MONITORING-LOGS-WATCH-ROOT-CAUSE-1 — the logs WATCH was correct; the logs section can no longer report OK without evidence (2026-08-22) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 116 | 1355 | MONITORING-LOG-SOURCE-RESILIENCE-1 — Monitoring reads where the application writes (2026-08-22) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 117 | 1371 | MONITORING-LOG-TIMESTAMP-ROLLOVER-1 — an event's age comes from the timestamp the log wrote, not one the parser invented (2026-08-22) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 118 | 1387 | MONITORING-LOG-COVERAGE-ANCHOR-INJECTION-1 — log coverage is proven by the bytes actually read, never by a timestamp inside them (2026-08-23) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 119 | 1405 | RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1 — Restore Drill evidence age is computed only from a faithful timestamp (2026-08-23) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 120 | 1427 | MONITORING-UNDATED-SEVERITY-ESCALATION-1 — Unageable ERROR Evidence Carries Severity Weight (2026-08-23) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 121 | 1447 | CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1 — Critical Gate warnings are attributable and judged (2026-08-23) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 122 | 1467 | CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1 — mandatory critical suites are selected by an explicit registry, never a filename coincidence (2026-08-23) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 123 | 1487 | RESTORE-DRILL-EVIDENCE-READ-STATE-1 — Restore Drill Evidence Read-State Contract (2026-08-23) | [15-monitoring.md](15-monitoring.md) | `monitoring` |
| 124 | 1503 | STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — Private Clinical Evidence Remediation (2026-08-23) | [14-deploy-infra-security.md](14-deploy-infra-security.md) | `deploy-infra` |
| 125 | 1523 | FINAL-STABILIZATION-RESIDUAL-AUDIT-1 — Residual Reconciliation Before the Consolidated Full Suite (2026-08-24) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 126 | 1545 | FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1 — PDF field assertions read their own column (2026-08-25) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 127 | 1565 | FIX-PDF-TEMPFILE-LEAK-1 — PDF temp-file ownership contract (2026-08-25) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 128 | 1585 | FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1 — Lab analytics lateness stops depending on the hour CI starts (2026-08-25) | [08-lab.md](08-lab.md) | `lab` |
| 129 | 1607 | FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — every temporary artifact has one owner that can reach it (2026-08-25) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 130 | 1625 | FIX-CI-GATE-WORKDIR-TEMPFILE-LEAK-1 — R-22: ownership is the allocation API, not a prefix list (2026-08-26) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 131 | 1645 | FINAL-PRE-FULL-SUITE-READINESS-CHECK-1 — readiness GO (retry after R-22) (2026-08-26) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 132 | 1665 | AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-2 — Final Stabilization Programme Closure (2026-08-26) | [13-cicd-testing.md](13-cicd-testing.md) | `cicd-testing` |
| 133 | 1681 | FIX-RME-PRINT-REMOVE-PATIENT-VISIT-DUPLICATE-1 — RME print drops the duplicated patient/visit card (2026-08-27) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 134 | 1701 | FEATURE-LEGACY-IMPORT-HUB-1 — Legacy Import Hub: one home, one ceiling (2026-08-27) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 135 | 1741 | FEATURE-LEGACY-IMPORT-HUB-1A — Legacy RME Activation (2026-08-28) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 136 | 1767 | FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — Daily Working-Branch Lock for Kasir & Admin Klinik (2026-08-29) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 137 | 1805 | REVISION-RME-REPORTS-TODAY-DEFAULT-1 — RME reports default to the clinical today (2026-08-29) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 138 | 1835 | REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — Kunjungan Baru patient selection (2026-08-29) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 139 | 1855 | BUGFIX-NEW-VISIT-PATIENT-SEARCH-RUNTIME-1 — New Visit patient search survives PostgreSQL (2026-08-29) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 140 | 1873 | REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — New Visit patient lookup is global patient identity (2026-08-30) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 141 | 1903 | BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the operator can find the patient, and is told when they cannot (2026-08-30) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 142 | 1923 | REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1 — a paper chart does not need permission from the present (2026-08-31) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 143 | 1943 | BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1 — Dedicated queue producer ↔ consumer contract (2026-08-31) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 144 | 1965 | REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — Telkomas Branch Code Foundation (2026-08-31) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 145 | 1981 | FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1 — Doctor Account Identity Link (2026-08-31) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-access` |
| 146 | 1995 | REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 — the odontogram may be charted before the signature; the record and the finish may not (2026-09-01) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 147 | 2015 | BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1 — the empty state stopped offering a create the server refuses (2026-09-02) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 148 | 2035 | REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1 — Cabang Sunu canonical code becomes SPN4 (2026-09-02) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 149 | 2051 | REVISION-SUNU-ADD-ROOM-A-B-1 — Cabang Sunu gets Ruangan A + Ruangan B (2026-09-02) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 150 | 2069 | FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 1: Doctor room-scoped access + RME/Odontogram print denial (2026-09-03) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-access` |
| 151 | 2093 | FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 2: Doctor device registry + Master Data → Device Dokter (2026-09-03) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 152 | 2119 | FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 3: Android Clinic App + cryptographic device enrolment (2026-09-03) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 153 | 2141 | FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 3.5: Android production readiness & governance (2026-09-03) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 154 | 2169 | REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1 — self-managed signing + direct APK (2026-09-03) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 155 | 2191 | REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — doctor↔device approval + app-only gate (2026-09-03) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 156 | 2221 | REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1 (2026-09-04) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 157 | 2243 | EVIDENCE-PHASE4A-REAL-DEVICE-KEYINFO-PREFLIGHT-1 (2026-09-05) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 158 | 2265 | PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1 (2026-09-05) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 159 | 2289 | REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1 (2026-09-05) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 160 | 2307 | PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 — the production signing key exists (2026-09-06) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 161 | 2327 | PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1 — the certificate is pinned (2026-09-06) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 162 | 2343 | PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1 — Release source frozen, ceremony pending (2026-09-06) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 163 | 2377 | PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — non-destructive doctor Android pilot, prepared and inert (2026-09-06) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 164 | 2472 | PWA-FOUNDATION-1 — Installable PWA Foundation (2026-09-07) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 165 | 2488 | DOCTOR-PWA-WEBAUTHN-1 — WebAuthn device credentials for the doctor browser login (2026-09-08) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 166 | 2519 | DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — a session remembers the proof that authenticated it (2026-09-08) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 167 | 2542 | DOCTOR-PWA-WEBAUTHN-KILL-SWITCH-1 — the WebAuthn admission switch, proven as a rollback (2026-09-08) | [07-doctor-access-device.part1.md](07-doctor-access-device.part1.md) | `doctor-device-android` |
| 168 | 2560 | DOCTOR-PWA-WEBAUTHN-CREDENTIAL-REVOKE-CONTAINMENT-1 — revoking one credential contains exactly one session (2026-09-08) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-device-android` |
| 169 | 2588 | DOCTOR-PWA-MULTI-DOCTOR-PILOT-1 — the pilot becomes a bounded cohort, and goes live for three doctors (2026-09-09) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 170 | 2612 | DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — measuring whether the whole fleet could be enforced, and finding that twelve doctors could not (2026-09-10) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 171 | 2634 | DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION — one authenticated session per doctor (2026-09-11) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 172 | 2654 | DOCTOR-ACCESS-PR-B-BRANCH-LOCK-COVER — one server-authoritative branch per doctor (2026-09-11) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 173 | 2686 | DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 (2026-09-13) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 174 | 2734 | FIX-READINESS-ESTATE-SNAPSHOT-INTEGRITY — one readiness report, one device-estate snapshot (2026-09-13) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 175 | 2754 | DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — CLOSED / GO, 15/15 (2026-09-14) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 176 | 2774 | DOCTOR-ACCESS-GLOBAL-ACTIVATION-BLOCKER-CLOSURE-1 (2026-09-15) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 177 | 2796 | REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 (2026-09-16) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 178 | 2830 | DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-PROVISIONING-1 (2026-09-16) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 179 | 2855 | DOCTOR-ACCESS-GLOBAL-ACTIVATION-1 — PHASE-0 DELTA RECHECK (2026-09-17) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 180 | 2875 | DOCTOR-ACCESS-GLOBAL-ACTIVATION-1 — HALF-A CUTOVER + BRANCH-MODEL CORRECTION (2026-09-17) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 181 | 2901 | DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1 — Half-B readiness (2026-09-18) | [07-doctor-access-device.part2.md](07-doctor-access-device.part2.md) | `doctor-access` |
| 182 | 2921 | REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 — Stages 1–3 + verify-device bugfix (2026-09-19) | [07-doctor-access-device.part3.md](07-doctor-access-device.part3.md) | `doctor-device-android` |
| 183 | 2941 | FIX-SUNU-GO-LIVE-BLOCKERS-1 — Sunu go-live blocker closure (2026-09-19) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 184 | 2959 | DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — Pendaftaran Device Dokter (2026-09-21) | [07-doctor-access-device.part3.md](07-doctor-access-device.part3.md) | `doctor-device-android` |
| 185 | 2967 | REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — Front Office Branch-Device Login Lock, Four Accounts Only (2026-09-21) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 186 | 2987 | REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — Branch Context Lock for Four Front Office Accounts (2026-09-22) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 187 | 3023 | SUNU-GO-LIVE-READINESS-1 — Cabang Sunu ready for its first REAL patient (2026-09-24) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 188 | 3041 | REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1 — Legacy Import Unlimited Business Quota (2026-09-24) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 189 | 3057 | REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — Date Attestation at a Real Visit (2026-09-26) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 190 | 3087 | REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — Legacy Patient Staged Verification & Cancellation (2026-09-27) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 191 | 3107 | DIAGNOSE-AND-CLEAN-LEGACY-PATIENT-NIK-CONFLICT-1 — Rolled-Back Legacy Patient Batch Reserved Every NIK (2026-09-27) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 192 | 3131 | REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 (2026-09-27) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 193 | 3157 | FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 — Mass Upload Legacy RME & Odontogram (2026-09-30) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 194 | 3181 | BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1 — Mass Upload 413 (2026-10-03) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 195 | 3205 | OPS — Admin Sunu admitted as WAVE-4 migration operator for SPN4 (2026-10-03) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 196 | 3209 | FIX-LEGACY-WAVE4-WINDOW-EXTENSION-1 — extending an approved migration window (2026-10-04) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 197 | 3213 | FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 — PR1 Batch Review (RME + Odontogram) (2026-10-04) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 198 | 3235 | FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 — PR2 Batch Publish (RME + Odontogram) (2026-10-04) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 199 | 3259 | BUGFIX-LEGACY-BATCH-REVIEW-DECISION-NOT-SUBMITTED-1 — a declarative binding is stale at submit time (2026-10-04) | [06-legacy-import-odontogram-patient.part1.md](06-legacy-import-odontogram-patient.part1.md) | `legacy-import` |
| 200 | 3287 | FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — Kelengkapan Arsip Pasien Legacy (2026-10-05) | [06-legacy-import-odontogram-patient.part2.md](06-legacy-import-odontogram-patient.part2.md) | `legacy-import` |
| 201 | 3327 | FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — Unified Medical-Record Index (2026-10-05) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 202 | 3341 | FIX-LEGACY-ODONTOGRAM-CROSS-BRANCH-READ-SCOPE-1 — Upload Authority Is Not Global Read Authority (2026-10-06) | [06-legacy-import-odontogram-patient.part2.md](06-legacy-import-odontogram-patient.part2.md) | `legacy-import` |
| 203 | 3357 | FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — DaengtisiaMS Observability Console (2026-10-07) | [12-foundation-enterprise.md](12-foundation-enterprise.md) | `foundation-governance` |
| 204 | 3367 | FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Duplikasi Pasien (2026-10-07) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 205 | 3381 | PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT — Server-side pilot gate for KTP camera OCR (2026-10-10) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 206 | 3389 | PHASE-2-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT-ACTIVATION-1 — Pilot armed for Admin Sunu @ SPN4 (2026-10-10) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 207 | 3393 | REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — Hybrid field-based KTP OCR (2026-10-10) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 208 | 3407 | PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — D7 consent before KTP OCR, privacy review, pilot readiness (2026-10-10) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 209 | 3421 | REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — Live field overlay on the KTP camera preview (2026-10-10) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 210 | 3441 | AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1 — Is a camera KTP photo archived as the patient's document? (2026-10-11) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 211 | 3455 | SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — A stored KTP card is served only to its capture branch (2026-10-11) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 212 | 3469 | GOVERNANCE-PATIENT-KTP-NEW-PATIENT-ONLY-1 — Patient identity is global; KTP photo capture is new-patient-only (2026-10-11) | [04-patient-identity.md](04-patient-identity.md) | `patient-identity` |
| 213 | 3485 | REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — visit detail order, landscape invoice, server-derived visit type (2026-10-12) | [01-rme-clinical.md](01-rme-clinical.md) | `rme-clinical` |
| 214 | 3503 | FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — two read-only cross-branch oversight roles (2026-10-12) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 215 | 3511 | REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 — oversight dashboard over an inclusive WITA date range (2026-10-13) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 216 | 3517 | AUDIT-RME-BILLING-CORRECTION-PAYMENT-REFUND-FOUNDATION-1 — RME billing correction / payment / refund foundation audit (2026-10-16) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 217 | 3529 | FEATURE-RME-BILLING-REFUND-1 — Phase 0 financial prerequisites shipped; refund execution NOT built (2026-10-16) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 218 | 3543 | FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — Pre-payment Invoice Correction Foundation, Default OFF (2026-10-17) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 219 | 3559 | ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 — activation NO_GO; payment bound to invoice version (2026-10-17) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 220 | 3575 | FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — branch tariff authority (D21), pending-correction payment block (D10), correction tariff gate (2026-10-17) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 221 | 3597 | FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — tariff approval provenance; no tariff imported (2026-10-17) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 222 | 3613 | REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — owner D21 revised to manual cashier pricing (2026-10-20) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 223 | 3631 | ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 — pre-payment correction pilot activation (2026-10-21) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 224 | 3661 | VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-1 — live SPN4 pilot verified, not closable yet (2026-10-21) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 225 | 3675 | HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1 — pilot identity allowlist (owner D1e) + explicit payment actor (2026-10-21) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 226 | 3691 | FIX-RME-INVOICE-BRANCH-SCOPE-EXPLICIT-ACTOR-1 — invoice branch scope from the explicit actor (2026-10-22) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 227 | 3701 | AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — clinic visit authority from the explicit actor (2026-10-22) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 228 | 3707 | HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — deactivated accounts stop everywhere; registration re-asserted at the service; OD-1 cross-branch Kontrol (2026-10-22) | [03-branch-context.md](03-branch-context.md) | `branch-context` |
| 229 | 3725 | VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-2 — second pilot re-verification, still not closable (2026-10-23) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 230 | 3739 | FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 — a Rp0 ORIGINAL invoice is never corrected; every correction mutation decided on the persisted active account (2026-10-23) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 231 | 3759 | PREPARATION-RME-INVOICE-CORRECTION-PILOT-OPERATIONAL-READINESS-1 — counter procedure for the SPN4 correction pilot (2026-10-23) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 232 | 3771 | IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — an invoice known to be wrong is not collected (2026-10-23) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 233 | 3783 | HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — explicit branch dispute reviewers; a Kontrol invoice payable alone while its origin is disputed (2026-10-24) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 234 | 3797 | VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-CLOSURE — third pilot re-verification, still OPERATIONAL_BLOCKED (2026-10-24) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 235 | 3805 | FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 — one payment form, at most one payment (2026-10-25) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 236 | 3845 | PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — post-payment correction prepared, nothing enabled (2026-10-25) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 237 | 3855 | VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-REAL-CASE-42-1 — the first real correction reconciles, but it was never authorized on the record (2026-10-25) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 238 | 3867 | RATIFICATION-RME-POSTPAYMENT-OWNER-DECISIONS-PP1-PP10-1 — post-payment correction policy approved, nothing enabled (2026-10-25) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
| 239 | 3875 | FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — post-payment correction built, switch OFF (2026-10-25) | [02-rme-billing-reporting.md](02-rme-billing-reporting.md) | `rme-billing` |
