# RME Refund Request & Approval — Release Gate Checklist (Sprint 4/7)

> **Status: CHECKLIST for a future release.** Every box starts unchecked. A gate is passed only with
> evidence named in the "Evidence" column; a WATCH is reported as WATCH (CG-21). Design:
> `docs/architecture/rme-refund-request-approval-design.md`.

## RG-0 — Owner decisions (blocks implementation)

| ☐ | Gate | Evidence |
|---|---|---|
| ☐ | RF-1 (D4) approval authority answered, incl. RF-1a pilot identities | register entry `OWNER_POLICY_APPROVED` |
| ☐ | RF-2 (D18) approval ≠ settlement answered | register entry |
| ☐ | RF-3 scope, flag, pilot, activation order answered | register entry |
| ☐ | RF-4 full vs partial answered | register entry |
| ☐ | RF-5 evidence at request answered | register entry |
| ☐ | RF-6 rejection/cancellation semantics answered | register entry |
| ☐ | RF-7 dispute interaction answered | register entry |
| ☐ | RF-8 visibility/reporting answered | register entry |
| ☐ | RF-12, RF-13, RF-14 scope exclusions confirmed | register entry |
| ☐ | Design, migration plan, threat model, TDD matrix updated to the options actually approved | diff |

No signature, reference or date is invented; a "recommended" option is never recorded as approved.

## RG-1 — Implementation (flag OFF)

| ☐ | Gate | Evidence |
|---|---|---|
| ☐ | Tests written first and observed failing on the unmodified base | run log |
| ☐ | Every TDD matrix row implemented; PostgreSQL 16 rows run on PostgreSQL 16 | test output (driver asserted) |
| ☐ | Suite selected by both NSF-R011 critical-gate variants; mandatory suites declared | `--list-tests` output |
| ☐ | Mutation targets (TDD §4) each killed | mutation log |
| ☐ | `PatientOwnedRecordRegistry` and every race cleanup list updated | diff + PostgreSQL run |
| ☐ | No settlement route/permission/method/status (RR-SEC-11) | structural test |
| ☐ | Security review: no CRITICAL/HIGH open | review record |
| ☐ | `pint`, `git diff --check`, `sprint:manifest-check`, `sprint:scope-audit` | output |
| ☐ | All required CI gates green on the **exact** candidate SHA; skipped ≠ passed | CI run id |
| ☐ | Full Suite reported as DEFERRED under the active temporary policy, never as passed (CG-18) | report |

## RG-2 — Deployment (flag OFF)

| ☐ | Gate | Evidence |
|---|---|---|
| ☐ | Financial fingerprint (reconciliation contract RFI-8) taken immediately before deploy | output |
| ☐ | Deploy only through the runner on the VPS; status `exit=0` and log `DEPLOY OK` | status file + log |
| ☐ | Migrations ran; constraints and partial indexes present; refund tables empty | read-only SQL |
| ☐ | Fingerprint identical after deploy | output |
| ☐ | Production grants diffed against `RoleSeeder` before seeding; then `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset` | diff + output |
| ☐ | Flag `rme.billing_refund_request` resolves OFF; routes 404 | `foundation:feature-flags` + HTTP |
| ☐ | Health 200 on the canonical URL; zero new application errors (log byte size before/after) | output |
| ☐ | Implementation GO tag created only after the above (CG-16) — it is **not** a feature GO | tag |

## RG-3 — Activation prerequisites (blocks enabling the flag)

Rows marked [RF-n] follow the recommended option and must be rewritten to the option the owner approves.

| ☐ | Gate | Evidence |
|---|---|---|
| ☐ | Sprint 2 (pre-payment pilot) closure verified, incl. `HUMAN_SEPARATION` red flag resolved [RF-1a, RF-3] | closure tag `verification-rme-invoice-correction-prepayment-pilot-1-go` |
| ☐ | `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1` (F-17) merged, deployed and verified **before** post-payment correction is activated (task instruction, 2026-10-31) | GO tag of that hardening + registry present on production |
| ☐ | Sprint 3 (post-payment correction) activated by its own owner decision (PP-10) [RF-3] | register + flag state |
| ☐ | At least one **real** OPEN obligation exists, caught not created | read-only SQL |
| ☐ | Per-branch staffing measured: ≥ 1 maker and ≥ 1 different checker account active at the pilot branch | read-only report |
| ☐ | RFI-1…RFI-3 PASS on production | probe output |
| ☐ | Owner activation decision for refund request/approval recorded | register entry |

## RG-4 — Pilot verification (after enabling)

| ☐ | Gate | Evidence |
|---|---|---|
| ☐ | One real request filed by the pilot maker, decided by a different pilot checker with a case-specific Owner reference | refund request id, events |
| ☐ | Reconciliation contract §4 deltas match exactly | form |
| ☐ | No money moved; obligation `OPEN`; KPIs unchanged | read-only SQL |
| ☐ | Evidence files contain ids and cents only — no patient identity | review |

## Not part of any gate in Sprint 4

Settlement, payout, patient credit, payment reversal, cross-invoice offset. Those belong to Sprint 5 or
to separate owner-decided workstreams.
