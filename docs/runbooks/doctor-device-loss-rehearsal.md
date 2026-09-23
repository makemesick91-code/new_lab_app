# Doctor device-loss rehearsal — Stage 3 ceremony runbook

`REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1` Stage 3.

This is the runbook a human follows in a clinic. It is the thing
`device_loss_runbook_rehearsed` is about, and running it is the only way that
prerequisite stops being UNVERIFIED.

**Nothing in this document may be recorded as done from reading it.** The
producer refuses a pass without `--performed-by` and `--confirm-performed`, and
refuses a drill id carrying the `TEMPLATE` marker, precisely so a rehearsal
cannot be completed on paper.

---

## 0. Before you start

| | |
|---|---|
| Environment | pre-live pilot (`APP_ENV=pilot`) |
| Enforced cohort today | 3 doctors — user ids 9, 15, 18 |
| Everyone else | still logs in with a password; **this drill must not change that** |
| Break-glass approver | a **Supervisor RME** account (`grant_doctor_break_glass_access`) |

**DO NOT** revoke or factory-reset the only usable tablet at a branch. §120 is
explicit: model the safest meaningful scenario. "Unavailable" is simulated by
*withholding* the tablet from the clinician, not by destroying trust in it.

Capture the starting posture, so you can prove you returned to it:

```
php artisan doctor:rollout-readiness
php artisan webauthn:readiness --report-only   # capturing starting posture, not gating
php artisan doctor:half-b-readiness
```

Expected at the start: `ENFORCEMENT_SCOPE_MODE=pilot`,
`GLOBAL_ENFORCEMENT_ACTIVE=false`, WebAuthn `VERDICT=ARMED`.

---

## 1. The scenario

One doctor **inside the enforced cohort** arrives for a clinical session and
their trusted tablet is unavailable — flat battery, left at another branch,
physically failed. They cannot complete a WebAuthn assertion, and under
PWA-only there is no password fallback.

## 2. Steps

### 2.1 — Establish the denial (the thing break-glass exists for)

1. The chosen doctor attempts to log in **without** the tablet.
2. **Expected:** denied. They reach no clinical session.
3. Record the time and the on-screen message.

> If they get in, **stop the drill** and record `--outcome=failed`. A cohort
> doctor who can log in without a device means enforcement is not doing what
> this programme claims, and that is the single most valuable thing this
> rehearsal could discover.

### 2.2 — Confirm the blast radius is one doctor

4. A **different** doctor, also in the cohort, logs in normally with their own
   trusted tablet.
5. **Expected:** unaffected.
6. Confirm nobody disabled enforcement to get through step 2.1:

```
php artisan doctor:rollout-readiness | grep -E 'ENFORCEMENT_FLAG_ARMED|GLOBAL_ENFORCEMENT_ACTIVE'
```

> `ENFORCEMENT_FLAG_ARMED=true` and `GLOBAL_ENFORCEMENT_ACTIVE=false` must both
> still hold. Turning the flag off "just to get the doctor working" is the
> fleet-wide rollback this capability was built to replace — if it happened,
> the drill has failed and should be recorded as such.

### 2.3 — Grant break-glass

7. The Supervisor RME opens **Konteks Kerja → Akses Darurat Dokter**
   (`/rme/doctor-break-glass`).
8. They select the affected doctor, enter a **real reason** (what actually
   happened, not "darurat"), and choose the **shortest** window that covers the
   session — not the maximum by reflex.
9. Submit.

### 2.4 — Prove continuity

10. The affected doctor logs in again.
11. **Expected:** they reach their clinical session.
12. Note that the console now shows the grant as **dipakai** (first use stamped).

### 2.5 — Prove the window is real

Pick **one** of these, and record which:

- **Revocation:** the Supervisor revokes the grant with a reason. The doctor's
  **already-open session** must stop working on their next action — not at the
  next login.
- **Expiry:** let a deliberately short window lapse and confirm the same.

> This is the step most worth doing carefully. A grant that only takes effect
> at the next login would leave an emergency session alive long after the
> emergency ended.

### 2.6 — Restore normal service

13. Return the tablet to the doctor.
14. They log in through the normal WebAuthn path.
15. **Expected:** normal login works, with no grant involved.

### 2.7 — Leave nothing behind

16. Confirm no active grant remains on the console.
17. Confirm the posture matches step 0.

---

## 3. Record the result — honestly

A rehearsal that ran and **failed** is evidence, and it is evidence of the
opposite thing. Record it either way.

```bash
php artisan doctor:device-loss-drill --record \
  --drill-id="SPN4-2026-09-18" \
  --runbook="docs/runbooks/doctor-device-loss-rehearsal.md" \
  --outcome=passed \
  --clinician-regained-access=yes \
  --performed-by="<name of the person who ran it>" \
  --notes="<what actually happened, including anything that surprised you>" \
  --confirm-performed
```

Then confirm the gate moved:

```bash
php artisan doctor:device-loss-drill --show
php artisan doctor:half-b-readiness
```

`device_loss_runbook_rehearsed` should read **PASS**. If it does not, read
`GATE_EVIDENCE` — the validator says exactly what it objected to.

### What must NOT be done

- Do **not** record `--outcome=passed` for a drill that was not performed.
  Nothing in the system can detect it; the whole integrity of this gate rests
  on that attestation being true.
- Do **not** run `--create-template` and treat it as a rehearsal. It writes the
  `TEMPLATE` marker deliberately so the gate reports UNVERIFIED.
- Do **not** relax the validator to make a record pass.

---

## 4. What this drill does and does not unblock

**Closes:** `device_loss_runbook_rehearsed`, one of the two prerequisites
currently blocking activation.

**Does not close:** `spare_device_available_per_branch`, which measures **FAIL**
and stays FAIL until a staffed branch actually holds a spare. The owner's
pre-live risk acceptance is recorded beside that measurement, not on top of it.

**Does not authorize:** Stage 4 scope widening. That needs its own checkpoint.
