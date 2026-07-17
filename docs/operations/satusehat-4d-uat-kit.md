# SATUSEHAT-4D — Human Operator UAT Kit

Status: **READY TO RUN — pending real human operator sessions.**
SATUSEHAT-4D reaches an operational **GO** only after this UAT is executed by real
operators, all material findings are fixed and re-tested, and every required role
records an **approved** sign-off. Automated tests do **not** substitute for this.
No GO tag is created until this kit is completed and signed off.

> **Hard rules for every session**
> - Use **synthetic / non-PII** data only. Never enter a real patient NIK/KTP or real clinical notes.
> - SATUSEHAT-2 stays **WATCH**; external submission stays **disabled**; production stays **blocked**.
> - Evidence references must not contain PII (use synthetic ids / screenshots with masked data).

## How to run

1. Facilitator (Supervisor RME or IT Operator) opens **SATUSEHAT → UAT Operator** (`/rme/satusehat/uat`).
2. Create a UAT run (optionally link the rollout wave under test).
3. For each scenario below, the named operator performs the steps; the facilitator records the result (pass/fail/blocked), operator name/role, and a PII-safe evidence reference.
4. Each required role records an **approved** or **rejected** sign-off.
5. Any `fail` → log a finding, fix, re-test, then re-record.
6. When all required roles approve **and** no scenario is `fail`, click **Sign Off Penuh** (`finalize`). The run becomes `signed_off`; the wave's branches are stamped UAT-passed.

Required sign-off roles: `admin_klinik`, `doctor`, `supervisor_rme`, `clinical_reviewer`, `it_operator`, `owner`.

## Scenario catalog

Each row: `scenario ID | role | branch | precondition | steps | expected result`.

### Admin Klinik
- `AK-01` | admin_klinik | own | logged in, branch-pinned | open Matriks Multi-Cabang | sees only own branch(es); cannot see other-branch detail
- `AK-02` | admin_klinik | own | issue exists | assign a patient-readiness remediation issue | assignment saved, audited; only own-branch issues selectable
- `AK-03` | admin_klinik | other | — | attempt to open another branch's detail URL | 404 (branch scope)
- `AK-04` | admin_klinik | own | hard issue open | attempt to reach pilot_ready | blocked; hard blocker not bypassable
- `AK-05` | admin_klinik | — | — | attempt to approve clinical terminology | denied (403)

### Doctor
- `D-01` | doctor | own | visit with room | enter structured primary + secondary diagnosis | saved; primary count = 1
- `D-02` | doctor | own | diagnosis issue open | resolve the diagnosis issue by revalidation | resolves only when data actually fixed
- `D-03` | doctor | own | informational/warning rollout | experience the mode banner | correct banner; no hard block in informational
- `D-04` | doctor | — | — | attempt to approve terminology / open another branch | denied

### Supervisor RME
- `SR-01` | supervisor_rme | all | — | open the readiness board + matrix | all RME branches visible; PII-free
- `SR-02` | supervisor_rme | all | overdue issues | assign + escalate an issue; bulk-assign a page of issues | escalation up-only; bulk bounded, out-of-scope dropped
- `SR-03` | supervisor_rme | one | drift present | verify source-drift revocation behavior | approval revoked on drift
- `SR-04` | supervisor_rme | one | internal_ready + no hard | promote branch | promotion succeeds (INTERNAL GO); external still blocked
- `SR-05` | supervisor_rme | one | — | create + approve a wave; run multi-branch rehearsal | wave approved; rehearsal ends BLOCKED_EXTERNAL_CREDENTIAL, no external send

### Clinical Reviewer
- `CR-01` | clinical_reviewer | — | draft mapping | approve a valid mapping | activated with official source
- `CR-02` | clinical_reviewer | — | invalid mapping | reject / deprecate with replacement | rejected/deprecated; historical readable
- `CR-03` | clinical_reviewer | — | — | attempt unrelated branch admin | denied

### IT Operator / Super Admin
- `IT-01` | it_operator | — | — | create + approve wave; evaluate eligibility | works; single-active-wave enforced
- `IT-02` | it_operator | — | — | run `satusehat:multi-branch-rehearse --wave=<id>` (dry-run) | no network; final state BLOCKED_EXTERNAL_CREDENTIAL
- `IT-03` | it_operator | — | — | run `satusehat:production-guard-check` and `satusehat:governance-audit` | production blocked; audit GO/WATCH, no FAIL
- `IT-04` | it_operator | — | — | attempt to fabricate external verification | not possible (no path)
- `IT-05` | it_operator | one | — | suspend + resume a branch | reversible; transition + audit recorded

### Owner / Management
- `OW-01` | owner | all | — | open the Executive Dashboard | aggregate only; no patient-level PII
- `OW-02` | owner | — | — | inspect wave progress + blocker summary | visible; external blocker shown
- `OW-03` | owner | — | — | attempt to configure a wave / record UAT | denied (read-only)

## Findings log (fill during the session)

| # | scenario | severity | description | fix commit | re-test result | owner |
|---|----------|----------|-------------|------------|----------------|-------|

## Sign-off record (fill during the session — real humans only)

| role | operator name | decision | date | notes |
|------|---------------|----------|------|-------|
| admin_klinik | | | | |
| doctor | | | | |
| supervisor_rme | | | | |
| clinical_reviewer | | | | |
| it_operator | | | | |
| owner | | | | |

**GO gate:** all six approved + zero open `fail` findings → `finalize` the run → then (and only then) the SATUSEHAT-4D operational GO tag may be created.
