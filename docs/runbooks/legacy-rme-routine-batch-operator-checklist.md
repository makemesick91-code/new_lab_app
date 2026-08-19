# Legacy RME Routine Batch — Operator Quick Checklist

One page. Use it before, during and after **every** routine batch.
Full detail: `docs/runbooks/legacy-rme-steady-state-operations-runbook.md`.

> Anything ticked from memory is not ticked. Run the command.

---

## APPROVAL

- [ ] Approval reference is **fresh** — not Wave-1's, not Wave-2R's, not a
      previous batch's
- [ ] Branch codes are **named** (never "all branches")
- [ ] Daily quota declared, and ≤ **100** (≤ 25 is the routine default)
- [ ] Planned start and end date recorded
- [ ] Batch code is not a retired identity (`WAVE-3` is refused)

## PEOPLE

- [ ] Maker (importer) identified
- [ ] Checker (reviewer/publisher) identified
- [ ] Maker and checker are **two different humans** with **two different
      accounts** — no shared logins
- [ ] Both assigned to the batch's branches

## BRANCH

- [ ] Every branch to be migrated is active and RME-enabled
- [ ] Every admitted branch is covered by the approval
- [ ] MAIN is not involved (it can never hold RME history)

## BACKUP  *(mandatory — a refusal, not a warning)*

- [ ] `bash scripts/backup-vps.sh`
- [ ] `php artisan foundation:backup-verify`
- [ ] Newest verified backup is **< 24 h** old
- [ ] If either step failed → **DO NOT OPEN**

## SOURCE

- [ ] Candidate document set is **frozen** before opening
- [ ] Every candidate is a genuine historical document, not already migrated

## PRE-FLIGHT  *(the gate)*

- [ ] `php artisan legacy-rme:ops-readiness`
- [ ] `Ready for a routine batch: YES`
- [ ] No `STOP THE LINE` codes
- [ ] Branch matrix shows the intended branches as `READY`

## OPEN

- [ ] Batch registered, approved, activated; branches enrolled
- [ ] Quota and window set on the batch record
      *(the window is enforced — registration is refused without
      `--planned-start-date` / `--planned-end-date`, or the two date fields on
      the form. The end date is inclusive.)*
- [ ] Batch registered by the governance account and **approved by a different
      account** — the server refuses self-approval
- [ ] Operators assigned
- [ ] Admission opened for **exactly** the approved branch codes
- [ ] Re-run pre-flight → still `YES`, batch binds, `BATCH_IN_PROGRESS`

## PER DOCUMENT  *(maker)*

- [ ] Read the Nomor RM **printed on the PDF**
- [ ] Type the source RM **independently** — never copy it from the patient
      record after selecting them
- [ ] Earliest confirmed date → `selected_rme_date`
- [ ] Latest confirmed date → `latest_rme_date`
- [ ] Any refusal → **STOP**, never override, never adjust a digit

## MONITOR  *(at least once per working session)*

- [ ] `php artisan legacy-rme:ops-readiness`
- [ ] `php artisan legacy-rme:migration-status`
- [ ] `IN_FLIGHT`, `UNEXPLAINED`, `QUOTA_DRIFT` all understood
- [ ] Any `STOP THE LINE` → pause admission, preserve evidence, escalate

## CLOSE

- [ ] `IN_FLIGHT = 0`
- [ ] `UNEXPLAINED = 0`
- [ ] `QUOTA_DRIFT = 0`
- [ ] Unresolved failures = 0
- [ ] Sign-off note written
- [ ] Batch completed through governance (never a forced close)
- [ ] Capability **OFF**, admission **EMPTY**, active batch **NONE**
- [ ] `legacy-rme:ops-readiness` → `Resting state: AT_REST`

## EVIDENCE

- [ ] Batch record filled from
      `docs/evidence/legacy-rme/routine-batch-evidence-template.md`
- [ ] Evidence is **PII-free** — counts, codes and timings only
- [ ] Filed with the approval closure
- [ ] **No GO tag** — a routine batch is operations, not a software release

---

## Two things that are always wrong

1. **Selecting the patient first, then copying their RM into the source field.**
   Independent confirmation is the entire control.
2. **Fixing lifecycle state with SQL or Tinker.** Use
   `legacy-rme:import-admin` (dry-run first). A hand-edited row is an unaudited
   transition.
