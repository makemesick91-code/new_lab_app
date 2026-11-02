# Owner decision package — doctor performed treatments (DPT-Q2 … DPT-Q10)

**Sprint that opened the questions:** `FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1` (rule 219, ADR 0016).
**Sprint that ratified them:** `RATIFICATION-RME-DOCTOR-PERFORMED-TREATMENTS-OWNER-DECISIONS-1` (2026-11-02 WITA).

**Status: every question below is answered — `OWNER_POLICY_APPROVED`, several of them STAGED.** The per-question
"Owner decision" block is authoritative; the "Question", "Current behaviour" and "Recommendation" text above each
block is kept verbatim as the record of what was presented and what the runtime did when the decision was taken.

> **Superseded status (kept, not deleted).** Until 2026-11-02 this package read: *"Status of every question below:
> `PENDING_OWNER_DECISION`. Nothing here is approved, and nothing here was implemented on an assumed answer."* That
> was true of the release `931c716c` and is superseded only by the ratification recorded here.

Decided earlier and NOT reopened here: CB-Q1 (structured doctor input is authoritative), CB-Q2 (detective only),
CB-Q3 (separate sprint), DPT-Q1 (explicit "Tidak Ada Tindakan" = operational KONTROL; empty = NOT_RECORDED;
operational KONTROL ≠ NO_CHARGE). S6-D1…S6-D8, S6-Q1…S6-Q3b, D21, D28 unchanged.

---

## Provenance of the ratification

- **Policy approval (DPT-Q2 … DPT-Q10).** The Owner approved, in principle, the recommendations previously
  presented for every question, by an explicit statement in the execution brief of
  `RATIFICATION-RME-DOCTOR-PERFORMED-TREATMENTS-OWNER-DECISIONS-1` (received 2026-11-01 ≈ 23:50 WITA). The brief
  states the approval; it is the source. **No signed artefact exists and none is claimed; no approval timestamp
  other than the brief's arrival is claimed.**
- **Pilot designation (DPT-Q8 only).** The brief approved "one branch initially" but did not name it. The four
  operational facts were asked in-session and answered by the Owner on 2026-11-02 ≈ 05:05 WITA (the time the
  answers arrived): branch **SPN4 — Cabang Sunu**; cohort **all treating doctors at the pilot branch**; doctor
  training **done, attested by the Owner**; activation window **immediately after the deploy is verified**.
- **What the brief explicitly did NOT authorize** — and this package therefore does not record as approved:
  mandatory declaration now, financial blocking now, an amendment workflow, a D28 recovery operation, a change of
  the SATUSEHAT Procedure source, activation on more than one branch, or `rme.zero_value_closure`.

## The five states are kept apart

| Label | Meaning for this package |
|---|---|
| `OWNER_POLICY_APPROVED` | The Owner stated the policy. Nothing more is implied. |
| `REPOSITORY_POLICY_RECORDED` | Written here, in the register and in rule 219. |
| `TECHNICAL_ENFORCEMENT_DEPLOYED` | Code enforcing it is merged, deployed and production-verified. |
| `PRODUCTION_ACTIVATED` | The flag is ON in production for the approved scope, and verified. |
| `OPERATIONAL_ACCEPTANCE_VERIFIED` | A real doctor used it in a real encounter and the server evidence was read. |

`STAGED` means the policy names a TARGET and the conditions to reach it; the pilot runs the earlier stage.

## Decision matrix (summary)

| ID | Approved policy (one line) | Approval | Pilot runtime | Deferred |
|---|---|---|---|---|
| DPT-Q2 | Detective during the pilot; confirmed paid-treatment discrepancies block unauthorized NO_CHARGE later | `OWNER_POLICY_APPROVED_STAGED` | detective, no block | blocking contract |
| DPT-Q3 | Target: "Ada / Tidak Ada Tindakan" required before "Selesai Pemeriksaan" | `OWNER_POLICY_APPROVED_STAGED` | optional | mandatory gate |
| DPT-Q4 | Authorized, controlled, auditable amendment after completion | `OWNER_POLICY_APPROVED` (direction) | read-only | the whole workflow |
| DPT-Q5 | Konsultasi and Kontrol stay out of the picker | `OWNER_POLICY_APPROVED` | already enforced | — |
| DPT-Q6 | Operational Kontrol ≠ registration Kontrol; no restatement | `OWNER_POLICY_APPROVED` | existing reporting | any KPI redefinition |
| DPT-Q7 | A dedicated, controlled D28 recovery is needed; no operation authorized | `OWNER_POLICY_APPROVED` (direction) | SOP only | the recovery itself |
| DPT-Q8 | One pilot branch: **SPN4**, all treating doctors, optional, detective, independent of NO_CHARGE | `OWNER_POLICY_APPROVED` + designation recorded | see rule 219 §14–§15 | widening |
| DPT-Q9 | Only the officially assigned treating doctor records | `OWNER_POLICY_APPROVED` | already enforced | any handover workflow |
| DPT-Q10 | SATUSEHAT Procedure source unchanged during the pilot | `OWNER_POLICY_APPROVED` | unchanged | re-evaluation |

`MEDIUM-1` (rule 218 §8) stays **`PARTIALLY_MITIGATED`** under every one of these decisions. Never record it closed.

---

## DPT-Q2 — Blocking contract for a confirmed discrepancy

**Question.** When the doctor's record shows a paid treatment that is not on a Rp0 invoice, should "Selesai Tanpa
Biaya" (a) stay allowed with a recorded signal (today), (b) require a written reason by the cashier, or (c) be
refused (`NO_CHARGE_DENIED`)? And when may rule 218 answer `CONSISTENT` (e.g. a declared "Tidak Ada Tindakan" with
no other signal)?

**Current behaviour.** (a). The signal `CBC_PERFORMED_TREATMENT_NOT_BILLED` is recorded in the closure audit
snapshot (version 2), shown to the cashier and in Oversight. Rule 218 never answers `CONSISTENT` or
`CONFIRMED_DISCREPANCY`.

**Recommendation.** Do not choose (c) until DPT-Q3 makes the declaration mandatory — otherwise a missing record
(NOT_RECORDED) and a truthful record would be treated differently in a way that rewards not recording. Re-evaluate
after one month of production data with the flag ON.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q2 |
| APPROVED_POLICY | During the pilot the cross-check stays DETECTIVE: detect, warn, record the audit signal, show it to authorized oversight; never block NO_CHARGE because of a doctor-evidence signal. Once reliable clinical evidence and an approved exception procedure exist, a CONFIRMED paid-treatment discrepancy should block an unauthorized NO_CHARGE. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief (see Provenance) |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED_STAGED` |
| IMPLEMENTATION_SCOPE | none in the pilot; the existing detective path (rule 218 §8, rule 219 §8) is the pilot behaviour |
| CURRENT_RUNTIME_BEHAVIOR | option (a); rule 218 answers neither `CONSISTENT` nor `CONFIRMED_DISCREPANCY` |
| DEFERRED_ENFORCEMENT | the blocking contract `CONFIRMED_PAID_TREATMENT_NOT_BILLED → NO_CHARGE_DENIED` and the approved exception procedure |
| FUTURE_DEPENDENCIES | (1) DPT-Q3 mandatory declaration active; (2) a monitoring period of at least one month of production data with the flag ON (the presented recommendation the Owner approved); (3) documented acceptance criteria for "reliable clinical evidence" — **not yet defined**, an Owner decision of the enforcement sprint; (4) an approved exception procedure; (5) a contract that defines and validates when `CONSISTENT` / `CONFIRMED_DISCREPANCY` may be answered |
| ACTIVATION_STATUS | `NOT_ACTIVATED` — no financial block exists |

## DPT-Q3 — Is the declaration mandatory before "Selesai Pemeriksaan"?

**Question.** Should completing the examination be refused while the state is `NOT_RECORDED`?

**Current behaviour.** Optional. Completion is unchanged; the record is never a new gate.

**Implementation note.** A mandatory gate belongs in `ClinicVisitService::transitionStatus()` (the single
completion chokepoint, rule 109), behind the flag, with a backlog plan for visits already `in_progress` at the
switch-on moment.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q3 |
| APPROVED_POLICY | TARGET workflow: before "Selesai Pemeriksaan" the treating doctor declares either `ADA_TINDAKAN` (with the treatments) or `TIDAK_ADA_TINDAKAN`. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED_STAGED` |
| IMPLEMENTATION_SCOPE | none in the pilot: `DECLARATION=OPTIONAL`, `EXAMINATION_COMPLETION_GATE=UNCHANGED` |
| CURRENT_RUNTIME_BEHAVIOR | optional; completion never consults the record |
| DEFERRED_ENFORCEMENT | the gate in `ClinicVisitService::transitionStatus()` |
| FUTURE_DEPENDENCIES | (1) the pilot operated successfully; (2) doctors trained; (3) a safe plan for visits already `in_progress` at the switch-on boundary; (4) regression + PostgreSQL concurrency tests pass; (5) a SPECIFIC rollout authorization recorded |
| ACTIVATION_STATUS | `NOT_ACTIVATED` |

Historical `NOT_RECORDED` is never reinterpreted as `NO_TREATMENT_DECLARED`.

## DPT-Q4 — Amendment after completion

**Question.** May the record be corrected after "Selesai Pemeriksaan" (a missed treatment, a wrong tooth), by whom,
and must the correction be approved?

**Current behaviour.** Read-only after completion. Inside the encounter a row is voided with a reason and
re-recorded; nothing is ever hard-deleted or edited in place.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q4 |
| APPROVED_POLICY | Authorized clinical amendments after completion are PERMITTED through a controlled, auditable workflow that keeps the original evidence reconstructable. No silent overwrite, no hard delete. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED` (direction only) |
| IMPLEMENTATION_SCOPE | none; the pilot does **not** introduce any post-completion edit path |
| CURRENT_RUNTIME_BEHAVIOR | read-only after completion |
| DEFERRED_ENFORCEMENT | the whole amendment workflow, under its own approved engineering contract |
| FUTURE_DEPENDENCIES | **open detail questions (not decided, not invented):** eligible clinical actors; required reason; whether an independent approval is needed; amendment versioning; relationship to an existing invoice; treatment history; clinical audit; consent; financial implications |
| ACTIVATION_STATUS | `NOT_IMPLEMENTED` |

## DPT-Q5 — Which services may be picked as a "tindakan"

**Question.** Should Konsultasi and Kontrol services (the approved-free service categories of rule 217) be
selectable as a performed treatment?

**Current behaviour (engineering choice, needs confirmation).** Excluded from the picker, together with the excluded
voucher code. Reason: a Konsultasi/Kontrol is a service, not a procedure, so "Tidak Ada Tindakan" stays truthful on
such a visit and the KONTROL classification cannot be contradicted by a row that only names the visit itself.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q5 |
| APPROVED_POLICY | Konsultasi and Kontrol are service categories, not performed procedures (`CONSULTATION_SERVICE != PERFORMED_PROCEDURE`, `CONTROL_SERVICE != PERFORMED_PROCEDURE`). They stay excluded from the structured picker, with the canonical exclusions and the approved-free-service predicate preserved. A free-text label never bypasses the exclusion. A doctor may truthfully declare "Tidak Ada Tindakan" on a Konsultasi or Kontrol visit. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED` (confirms the engineering choice) |
| IMPLEMENTATION_SCOPE | already enforced by `RmePerformedTreatmentService` (rule 219 §7): selection is by `treatment_id` only, there is no free-text treatment field |
| CURRENT_RUNTIME_BEHAVIOR | excluded |
| DEFERRED_ENFORCEMENT | — |
| FUTURE_DEPENDENCIES | — |
| ACTIVATION_STATUS | follows the flag |

## DPT-Q6 — Reporting of operational KONTROL

**Question.** Which reports and KPIs should count operational KONTROL (doctor declaration) vs registration type
`visit_type = control`, and should historical KPIs be restated?

**Current behaviour.** Oversight "Tanpa Biaya (Rp0)" shows the recorded doctor state per closure and counts. No KPI
was redefined and nothing historical was reclassified.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q6 |
| APPROVED_POLICY | `OPERATIONAL_CONTROL != VISIT_TYPE_CONTROL` and `OPERATIONAL_CONTROL != NO_CHARGE`. Historical visit classifications are not rewritten and historical KPIs are not restated. During the pilot operational Kontrol is recorded and exposed through the EXISTING reporting architecture. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED` |
| IMPLEMENTATION_SCOPE | none new; Oversight "Tanpa Biaya (Rp0)" (rule 219 §9) is the authorized surface |
| CURRENT_RUNTIME_BEHAVIOR | as above |
| DEFERRED_ENFORCEMENT | any KPI definition change |
| FUTURE_DEPENDENCIES | a clearly documented reporting contract before any KPI is redefined |
| ACTIVATION_STATUS | follows the flag |

## DPT-Q7 — D28 recovery

**Question.** If the doctor's record shows a paid treatment on a visit whose ORIGINAL invoice is Rp0, D28 forbids
correcting it and there is one active invoice per visit. Which recovery is approved (e.g. a supervised exception,
a separate receivable, or acceptance as a loss)?

**Current behaviour.** SOP only: do not close "Selesai Tanpa Biaya", record an incident, escalate to the Owner. No
SQL fix, no void, no replacement invoice, no write-off.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q7 |
| APPROVED_POLICY | A dedicated, controlled recovery solution is NEEDED for an incorrect Rp0 ORIGINAL invoice. Approving the direction does **not** authorize any particular financial operation. D28 stays in force. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED` (direction only) |
| IMPLEMENTATION_SCOPE | none; the proposal is `docs/decisions/rme-d28-recovery-proposal.md` (status `PROPOSAL_NOT_APPROVED`) |
| CURRENT_RUNTIME_BEHAVIOR | SOP only (rule 219 §10): do not use a workaround, do not force NO_CHARGE, record an incident, escalate to the Owner, follow the existing lawful procedure |
| DEFERRED_ENFORCEMENT | the recovery mechanism |
| FUTURE_DEPENDENCIES | an Owner decision on the proposal; **not authorized:** invoice void, replacement invoice, artificial receivable, write-off, refund, direct financial SQL, changing a historical invoice snapshot |
| ACTIVATION_STATUS | `NOT_IMPLEMENTED` |

## DPT-Q8 — Activation

**Question.** When, where (all branches or one pilot branch) and with which training is
`rme.doctor_performed_treatments` switched on? Is it activated before, together with, or independently of
`rme.zero_value_closure`?

**Current behaviour.** Deployed OFF. Activation is the separate sprint
`ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1`. The two flags are technically independent.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q8 |
| APPROVED_POLICY | Activate `rme.doctor_performed_treatments` for ONE branch initially, independently of `rme.zero_value_closure` (which stays OFF). Doctors are trained. Declarations stay OPTIONAL. The cross-check stays DETECTIVE. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief (policy); in-session answers 2026-11-02 ≈ 05:05 WITA (designation) |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED`; designation `OWNER_DESIGNATION_RECORDED` |
| IMPLEMENTATION_SCOPE | a FAIL-CLOSED, SERVER-SIDE, source-controlled branch allowlist — a global flag alone is NOT a one-branch pilot; built by `ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1` |
| CURRENT_RUNTIME_BEHAVIOR | at ratification: flag OFF in production (runtime `931c716c`), no branch admission exists |
| DEFERRED_ENFORCEMENT | any widening beyond SPN4 (a new Owner decision) |
| FUTURE_DEPENDENCIES | the allowlist deployed and verified; real clinical acceptance observed |
| ACTIVATION_STATUS | `NOT_ACTIVATED` at ratification (see rule 219 §14–§15 for the activation record) |

**Pilot designation (Owner, 2026-11-02).**

| Field | Value |
|---|---|
| PILOT_BRANCH_CODE | `SPN4` |
| PILOT_BRANCH_NAME | Cabang Sunu |
| AUTHORIZED_DOCTOR_COHORT | every doctor who is the officially assigned treating doctor of a visit at SPN4 — no additional identity allowlist; the existing treating-doctor, branch, room and consent rules stay the enforcement |
| OPERATOR_TRAINING | `OWNER_ATTESTED` — the Owner states training was done; no attendance record exists in the repository and none is claimed |
| ACTIVATION_WINDOW | immediately after the activation deploy is verified |
| OPERATIONAL_ACCEPTANCE_OWNER | not separately named; the Owner who made this designation |

**What the designation does not do.** It does not reuse or rely on the Sprint 2 invoice-correction pilot at SPN4
(D1c) or the refund pilot (RF-3): each capability carries its own allowlist and its own decision.

## DPT-Q9 — Covering or non-assigned doctor

**Question.** If a different doctor actually treats the patient than the visit's assigned `doctor_id` (covering,
handover), who may record?

**Current behaviour.** Only the doctor linked to the visit's `doctor_id` (the identity the rest of the RME already
uses). Any other account, including Super Admin, is refused — there is no impersonation path.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q9 |
| APPROVED_POLICY | Only the OFFICIALLY ASSIGNED treating doctor (`AUTHORIZED_DOCTOR_ID = VISIT.DOCTOR_ID`, resolved through `DoctorIdentityResolver`) records performed treatments. A covering doctor must first become the assigned doctor through the canonical authorized workflow. No temporary impersonation; the Doctor role alone never widens access; device, branch, room and consent rules are never bypassed. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED` (confirms the runtime) |
| IMPLEMENTATION_SCOPE | already enforced (rule 219 §4.4) |
| CURRENT_RUNTIME_BEHAVIOR | as approved |
| DEFERRED_ENFORCEMENT | any new handover or reassignment workflow — a separately reviewed design |
| FUTURE_DEPENDENCIES | — |
| ACTIVATION_STATUS | follows the flag |

## DPT-Q10 — SATUSEHAT Procedure source

**Question.** Should SATUSEHAT Procedure resources be built from the doctor's record instead of the invoice line?

**Current behaviour.** Unchanged: SATUSEHAT still maps Procedures from invoice lines.

**Owner decision (2026-11-02).**

| Field | Value |
|---|---|
| DECISION_ID | DPT-Q10 |
| APPROVED_POLICY | The existing SATUSEHAT Procedure source (invoice lines) stays UNCHANGED during the pilot. New clinical data is never auto-submitted. |
| SOURCE_OF_OWNER_APPROVAL | ratification brief |
| APPROVAL_STATUS | `OWNER_POLICY_APPROVED` |
| IMPLEMENTATION_SCOPE | none |
| CURRENT_RUNTIME_BEHAVIOR | unchanged |
| DEFERRED_ENFORCEMENT | any change of source |
| FUTURE_DEPENDENCIES | reliable structured data; validated mapping; approved workflow decisions; passing integration tests; sandbox and production readiness contracts |
| ACTIVATION_STATUS | — |

## Consistency guarantees of this ratification

This package does not state, and no document may infer from it, that any of the following is true:

- the declaration is mandatory (it is optional);
- NO_CHARGE is blocked by a doctor-evidence signal (it is not);
- a post-completion amendment exists (it does not);
- a D28 recovery exists (it does not);
- global activation is approved (only SPN4 is);
- the SATUSEHAT Procedure source changed (it did not);
- MEDIUM-1 is closed (it is `PARTIALLY_MITIGATED`).
