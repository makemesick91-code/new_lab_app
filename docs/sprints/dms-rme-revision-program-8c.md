# DMS-RME-REVISION-PROGRAM-8C — clinical write authority and prescription integrity

**Date:** 2026-11-07 (WITA = UTC+8). **Type:** SECURITY_FIX. **Base:** `3b283ce5` (runtime `fce68541`, the
Program 8B release). **Rule:** `.cursor/rules/228-rme-clinical-write-authority-and-prescription-integrity.mdc`.
**Parent checkpoint:** `DMS-RME-REVISION-PROGRAM-8-OWNER-OPERATIONAL-CLOSURE-1`
(`docs/sprints/dms-rme-revision-program-8-owner-operational-closure.md`).

Foundation-First Sprint Lock stays ACTIVE (allowed category: security fix / clinical correctness). Full Suite
deferred under the global temporary policy (CG-18): **not run, not passed**. Clinic reopening not authorised.

## 1. Owner decisions (CG-22) — recorded verbatim in substance, 2026-11-07

| Decision | Rows | Answer |
|---|---|---|
| D3 Group 1 — clinical write authority | OD-7-2 + R8A-1, OD-5-7, OD-5-2, OD-5-3, D3-NEW-1 | **FIX NOW**: room scope on prescription and canonical RME writes; only clinical roles START an examination, the front desk keeps CANCEL for no-shows; `doctor_id` frozen from `cashier_pending`; RME/odontogram authoring uses the prescription clinical-role allowlist |
| D3 Group 2 — document integrity | OD-8A-2, OD-8A-1, P8R-S5, OD-6-2, D3-NEW-6 | **FIX NOW**; after the reviewer's combined HIGH was surfaced the owner chose **LOCK AFTER COMPLETION**: once the visit is completed or cancelled the prescription is read-only, before that every change is audited |
| Signature authority | D3-NEW-2, OD-5-1 | **Named doctor signs only**: only the account linked to the prescription's own doctor saves the doctor-signature canvas; Perawat / Supervisor RME keep content authoring (OD-5-1 kept and recorded) |
| D3 rest | D3-NEW-4 | **FIX NOW**: clinical-document read allowlist; clinical roles and Owner keep their access |
| D3 rest | D3-NEW-3 / D3-NEW-5 | DEFER WITH CONDITIONS, no schema change (parent checkpoint §D3) |
| D3 rest | OD-7-4 | keep denied |
| D3 rest | RA-4 | separately governed sprint |
| D3 rest | 16 residuals + P3 backlog | itemized matrix presented; **NOT approved yet — kept open** |

Nothing outside these answers is implemented.

## 2. Delivery

`sprint:scope-audit --strict` refuses more than 10 changed `app/*.php` files under SECURITY_FIX (the Program 8B
precedent), so 8C ships as two PRs. One deploy and the GO tag `dms-rme-revision-program-8c-go` follow PR2.

### PR1 — clinical write authority + read allowlist (rule 228 §1–§5)

| Row | Change | File |
|---|---|---|
| OD-7-2 / R8A-1 | Prescription `view` / `print` / `viewForVisit` / `create` / `update` add the doctor room scope after the doctor-patient scope; the service re-asserts it | `RmePrescriptionPolicy`, `PrescriptionAuthoringAuthority` |
| OD-5-7 | The live-encounter write predicate refuses a doctor outside the encounter's room | `RmeVisitConsentService::actorMayWorkOnEncounter()` |
| D3-NEW-1 | The same predicate requires a role in `FrontOfficeRole::CLINICAL_ROLES` | `RmeVisitConsentService` |
| OD-5-2 | New `ClinicVisitPolicy::startExamination()` (= the clinical examination authority); authorized by the controller for `in_progress`, re-asserted by `ClinicVisitService::transitionStatus()`; the visit page offers the button only to its holders | `ClinicVisitPolicy`, `ClinicVisitController`, `ClinicVisitService`, `rme/visits/show.blade.php` |
| OD-5-3 | `assignRoom()` on a `cashier_pending` visit moves only the room | `ClinicVisitService` |
| D3-NEW-4 | `CROSS_BRANCH_DOCUMENT_READ_ROLES` (clinical + Owner); an unclassified account reads none | `RmeWorkingBranchScope` |

RED evidence: 12 of 28 new tests failed on the untouched base, each for the reason under test; the 16 positive
controls passed before and after. Records: rule 228, `.claude/rules/rme-clinical.md` AIR-RMC-50..52 (AIR-RMC-45
"no room rule" superseded), supersession markers in rules 226 §1.4 and 227 §1.3. CI: token
`RmeClinicalWriteAuthority` in both critical-gate variants; both suites declared mandatory.

### PR2 — prescription and consent document integrity

Filled in with PR2.

## 3. Release

Filled in at release.
