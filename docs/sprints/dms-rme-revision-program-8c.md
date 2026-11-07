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

### PR2 — prescription and consent document integrity (rule 228 §8–§13)

| Row | Change | File |
|---|---|---|
| OD-8A-1 | **Lock after completion**: `PrescriptionAuthoringAuthority::documentLocked()` (completed / cancelled). The policy refuses create/update with the reason; the service re-decides it on the visit row it holds FOR NO KEY UPDATE (`update()` now takes that lock first, as `create()` always did). `cashier_pending` stays amendable | `PrescriptionAuthoringAuthority`, `RmePrescriptionPolicy`, `RmePrescriptionService` |
| OD-8A-2 | One `sys_audit_logs` row per create / update (`RME_PRESCRIPTION_CREATED` / `_UPDATED`) in the same transaction: ids, locked visit status, actor, changed field NAMES — never a value, patient data or storage key | `RmePrescriptionService` |
| P8R-S5 | FormRequest `max` on both canvases; the decoder re-applies the payload bound, requires a real IHDR, refuses a declared size above 4096 per side or 4,194,304 pixels, and inflates bounded by the declared size | `Store/UpdateRmePrescriptionRequest`, `PrescriptionCanvasDecoder` |
| OD-6-2 | Consent signature stream `Cache-Control: private, no-store, max-age=0` | `RmeVisitConsentController` |
| D3-NEW-2 / OD-5-1 | **Named doctor signs only**: `maySign()` (identity via `DoctorIdentityResolver`, role-free); another clinician writes content unsigned and can never save or replace the signature; the canvas is offered only to that doctor (`canSign` from the controller) | `PrescriptionAuthoringAuthority`, `RmePrescriptionService`, `RmePrescriptionController`, `prescription/show.blade.php` |
| D3-NEW-6 | The uncalled RME-wide `MedicalRecordService::paginate()` and its now-unused dependency removed | `MedicalRecordService` |

Nine `app/*.php` files (the scope limit is ten). Kept OUT on purpose, recorded in rule 228 §15: two comment-only
docblocks in `OdontogramPolicy` / `MedicalRecordPolicy` that still describe the pre-8C read scope (the code follows
§5) — PR1 self-review finding #6, fixed for `RmePrescriptionPolicy` here.

RED evidence: 14 of 20 new tests failed on the PR1 head, each for the reason under test; I3-01 was then tightened to
assert the size message (it had passed only because the padded payload failed the PNG check) and I3-01b added for
the decoder-level bound. Records: rule 228 §8–§15, `.claude/rules/rme-clinical.md` AIR-RMC-53. CI: the existing
`RmePrescription` token selects the suite; it is declared mandatory.

Existing contracts revised, never deleted: authors who are not the visit's doctor send no signature (default forms in
the authoring-authority, role-matrix and printed-identity suites); P8A-RX-50 now pins the refusal the owner decided;
RX-01 is split (RX-01b: the named doctor writes both canvases); RX-17's positive control is split per account. Two
fixtures (`prescriptionVisit`, `p6PrescriptionVisit`) gave the visit a room of ANOTHER branch, so the visit doctor
could not be put online in it — they now use a room of the visit's own branch. Three tests could have passed for the
wrong reason under the new rule (the signer refusal fires before the path they test); they now act as the visit's
doctor and pin the exact message (`Area gambar kosong…`, `Resep untuk kunjungan ini sudah ada…`).

Verification: SQLite — integrity 21/21, related suites (Prescription | Consent | MedicalRecord | DoctorPatientScope
| clinical evidence | write authority | read scope | workspace | print | room scope) 726 run, 0 failed after the
fixes, governance contracts 113/113. PostgreSQL 16.15 + PHP 8.3 — 185 + 56 run, 0 failed after the fixes,
including both PostgreSQL-only concurrency suites (`RmeClinicalEvidenceWriteLockConcurrencyTest`, which caught the
two signer-fixture failures SQLite skips, and `RmePerformedTreatmentConcurrencyTest`, which exercises PR1's
live-encounter predicate). Mutation: 12 mutants, **12 killed, 0 survived** (lock off in authority / service /
policy, signer refusal off on create / update, `maySign` always true, payload and pixel bounds off, update audit off,
audit lists bookkeeping fields, consent signature cacheable, canvas offered to every manager), reverted by copy.

### Independent security review (owner decision 2026-11-08: wait for it; no waiver)

The Program 8/8A mechanism (re-issue the hook-rewritten `Agent` input once) cannot work as installed:
`context-mode` 1.0.162 `hooks/core/routing.mjs` (Agent branch) appends its routing block to every call
unconditionally, so the re-issued input is rewritten again and never matches what auto mode reviewed. No hook was
disabled and no permission was changed. The review therefore ran in a **separate headless session** (`claude -p`,
clean context, read-only by construction: `--permission-mode plan`, Edit/Write/Agent/web disallowed, only Read, Grep,
Glob and read-only `git` subcommands allowed, no MCP servers; `--bare` deliberately not used, so every hook stayed on).
The brief carried the requirements and file pointers, never the implementing session's findings.

**Round 1:** session `3ec032fd-3036-4d95-9314-7e3395cd52e8`, model `claude-opus-5`, inspected HEAD `37b9b4f5`
(PR1 `3296f585` + PR2) — **`INDEPENDENT_REVIEW_VERDICT=PASS`**, no CRITICAL, no HIGH, no access path widened.
Each finding was verified against source:

| Id | Sev | Finding | Disposition |
|---|---|---|---|
| F1 | MEDIUM | A live account holding `manage_clinic_visits` with no clinical role would lose RME authoring at deploy (availability) | **Disproved by read-only production census:** 26 active holders, 0 direct grants, the 8 without a clinical role are all exactly `Front Office` — already refused by the front-desk predicate before 8C. No code change |
| F2 | LOW | `markPrinted()` stamps `printed_at` on a closed prescription | Already declared in rule 228 §8.3 (print bookkeeping, not an amendment); no change |
| F3 | LOW | `assertPermits()` decided on the pre-lock copy (AIR-RMC-38) | **Fixed**: re-asserted on the locked row in create and update; R-01/R-02 move the room underneath a stale copy |
| F4 | LOW | `MAX_PIXELS` 4.19M ≈ 9.7× the largest canvas; the blank scan is O(w·h) | **Fixed**: `MAX_DIMENSION` 2048, `MAX_PIXELS` 1,048,576; R-03 with positive controls at 900×480 and the 700×220 consent canvas |
| F5 | LOW | The signature limit lived only in the FormRequests | **Fixed**: `decode(..., maxLength:)` — callers may only tighten; R-04 |
| F6 | INFO | Pre-existing: the blank check skips non-RGBA PNGs | Recorded (rule 228 §15) |
| F7 | INFO | The clinic-oversight prescription print is outside `RmePrescriptionPolicy` | Recorded (rule 228 §1.4) |
| F8 | INFO | `rmeClinicalReader()` inherits the seeded Owner permissions | Recorded (rule 228 §15) |

## 3. Release

Filled in at release.
