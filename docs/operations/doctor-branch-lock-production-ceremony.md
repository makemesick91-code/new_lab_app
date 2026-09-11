# PR-B production ceremony — doctor branch lock, transfer and temporary cover

**This document is for a human operator at a real clinic tablet.** Every step needs a person.
Nothing in it may be simulated, and no step may be recorded as done from inference.

Rules: `docs/architecture/doctor-branch-lock-and-cover.md` (DBL-R001..R025).
Operations: `docs/runbooks/doctor-branch-lock-operations.md`.

## Before you start

Two accounts are needed and they must be **two different people's accounts**, because the
service refuses `request.created_by == approver` inside the approval transaction and a Super
Admin cannot get past it. One files, the other decides. Either may be Super Admin or
Supervisor RME; nothing requires a particular order.

Confirm with the owner which doctor is the test subject (step A) before arming anything.

### Two things to understand before you agree to run this

**1. ARMING IS NOT FREE, and an earlier draft of this document said it was.** It said "arming is
not the risky step" because an UNSET doctor gets no branch narrowing. That is true of
`doctor.branch_lock` and **false of the other flag you must arm first.**
`DoctorSessionLeaseService::subjectTo()` is `$user->hasRole('Doctor')` — it is scoped by ROLE,
not by lock — so arming `doctor.single_active_session` makes one-session-per-doctor enforcement
live for **all 15 doctor accounts immediately**. Any doctor already logged in somewhere who then
logs in elsewhere is DENIED. On a clinic day that can refuse a real clinician's login, and it has
nothing to do with the branch lock or with UNSET.

So the arming step is a supervised window with somebody reachable who holds
`release_doctor_session_leases`, exactly as the lease runbook says — not a preamble.

**2. STEP B IS A ONE-WAY DOOR.** There is **no path back to UNSET**: no shipped surface deletes a
lock row, and the twelve controller actions contain no unset or clear. Once you approve an initial
assignment, that clinician is permanently branch-locked, and the only movement afterwards is a
**transfer to another branch**. Step P can undo a transfer; nothing can undo the assignment.

That changes what "test" means here. **Get explicit owner agreement that the subject doctor may
stay locked for good, to a branch of the owner's choosing — not a branch picked for testing
convenience.** Choose the destination in step B as if it were a real, permanent operational
decision, because it is one.

| surface | URL |
|---|---|
| approver queue | `/rme/doctor-branch-locks` |
| file a lock request | `/rme/doctor-branch-locks/new` |
| file a cover | `/rme/doctor-branch-covers/new` |

---

## OWNER DECISION, 2026-09-12 — and ONE PART OF IT IS NOT EXECUTABLE TODAY

**Subject and destination, an explicit owner operational assignment** — not inferred from device
branch, prior login, visit history or doctor code:

| | |
|---|---|
| subject | drg Karmila, user 18, doctor 21 |
| permanent home branch | SPN4, Cabang Sunu (branch id 5) |
| reversible to UNSET? | **NO.** Owner accepts this. Later change only via permanent transfer. |

Verified against production: Karmila is active, linked, holds **no** existing lock, and SPN4 is in
her practice pivot (`ATG3,LDK2,SPN4,TLK1`) and is active and RME-enabled. **Step B is executable.**

**Lease flag:** `AUTHORIZE_CEREMONY_ARMING=true`, `doctor.single_active_session=true` for a
CONTROLLED WINDOW ONLY, affecting all 15 Doctor-role accounts, to be returned to `false`
afterwards. `CEREMONY_LEASE_FLAG_FINAL_STATE=false`. This is **not** a fleet rollout and must
never be recorded as one.

### THE FOREIGN-TABLET PROOF CANNOT BE PERFORMED WITH THIS SUBJECT

The owner requires `DEVICE_PHYSICAL_BRANCH != SPN4` while
`EFFECTIVE_CLINICAL_BRANCH = SPN4`, and suggested the LDK2 tablet. Measured on production, that
is impossible today:

- **Karmila's only ACTIVE authorization is device 3, `PHASE4A_PILOT_TABLET_02`, which sits at
  SPN4.** Her other two (devices 1 and 4, both SPN4) are revoked and rejected.
- **The LDK2 tablet, device 5 `PILOT_TABLET_04_LDK2`, is authorized to drg Nisa — not Karmila.**
- **She cannot browser-login anywhere.** `DoctorAppLoginGate::denyBrowserSessionReason()` denies
  any in-scope doctor without a device-bound session, and Karmila is in the enforcement cohort
  `[9,15,18]`. A browser has no device binding, so there is no browser fallback for her.

So her only usable tablet is at SPN4, which is also her assigned home branch — the one
configuration in which this proof is vacuous, because device branch and home branch coincide.

**Three ways forward. This needs an owner choice; do not improvise one.**

1. **Authorize Karmila on the LDK2 tablet** (a new `DoctorDeviceAuthorization` for doctor 21 on
   device 5). Gives the clean proof — device at LDK2, home at SPN4. It is a production identity
   write, it is exactly what PR-C automates, and it is not authorized by the current decision.
2. **Use a different subject for the foreign-tablet step only** — drg Nisa is already authorized on
   the LDK2 tablet, so locking her to a branch other than LDK2 would demonstrate the property.
   Splits the ceremony across two doctors and needs owner approval.
3. **Run steps A, B and everything that does not need a foreign tablet, and record the foreign-
   tablet proof as BLOCKED** pending option 1. The device-independence property is already proven
   by automated test (`DoctorHomeBranchLockTest` CASE 3: the same doctor on another branch's tablet
   still sees only their own branch), but that is a test, not the physical evidence the owner
   asked for. **Do not record option 3 as a pass.**

---

## 0. Pre-flight — measured on production 2026-09-11, confirm before you start

Everything below was read read-only from production. **Re-check the counts if a day has
passed**; if any row has moved, the step that depends on it may no longer work.

**RME branches, with how many visits each holds.** The visit count matters for step E: locking
a doctor to a branch with two visits proves very little, so prefer LDK2 or TLK1.

| branch | visits |
|---|---|
| LDK2 Cabang Landak | 17 |
| ATG3 Cabang Antang | 11 |
| TLK1 Cabang Telkomas | 9 |
| SPN4 Cabang Sunu | 2 |

Active clinic rooms exist at all four (TLK1 4, LDK2 3, ATG3 2, SPN4 2), so steps K and N can
select a room wherever you go.

**Candidate subjects — 12 doctors, all active, all linked, none in the enforcement pilot, and
every one already pivoted to all four RME branches** (so any branch is a legal destination;
see the practice-pivot warning in step B):

doctor 15 drg Irwan · 16 drg Fahira · 18 drg Ramadhan · 22 drg Windi · 23 drg Nurmilah ·
24 drg Aisyah · 25 drg Ilmiah · 26 drg Ega · 27 drg Syifa · 28 drg Yudya · 29 drg Syahrul ·
30 drg Wahyuni

**The two approver accounts, and there are exactly two:**

| user id | name | role |
|---|---|---|
| 1 | IT Support | Super Admin |
| 11 | Jene Monika | Supervisor RME |

Maker-checker needs two different user ids, so **both people must be available for the whole
ceremony**. There is no third account to fall back on.

**Patients with cross-branch visit history, for step F:** patient 28 has visits at three
branches; patients 24, 27, 31 and 34 at two. Any of them demonstrates the archive read.

---

## WINDOW 2026-09-12 05:30-06:30 WITA — VERDICT: DO_NOT_ARM / CEREMONY=DEFERRED

Every precondition a machine can check **PASSES**. Re-run at 05:26 WITA, inside the window:

| owner-required value | measured | expected | |
|---|---|---|---|
| `DOCTOR_SESSIONS_ACTIVE` | **0** | 0 | PASS |
| `UNRELEASED_LEASES` | **0** | 0 | PASS |
| `TODAY_ACTIVE_CLINICAL_WORK` | **0 visits today** | none | PASS |
| `GLOBAL_ENFORCEMENT_ACTIVE` | **false** | false | PASS |
| `PILOT_COHORT` | **[9,15,18]** | [9,15,18] | PASS |
| `LOG_WATERMARK` | **1406217 bytes** | anchored | captured |

**Device 5 passes every eligibility check the owner demanded before mutation:**
`PILOT_TABLET_04_LDK2`, branch_id 2 (LDK2), status **active**, identity
**cryptographically_verified**, enrollment **verified**, **not revoked**, holds a public key.

### Why the ceremony is still deferred — two blockers, neither machine-closable

**1. THE HARD PRECONDITION IS UNCONFIRMED.** The owner made it absolute: an operator holding
`release_doctor_session_leases` must be *actively present for the entire window* and able to
observe and roll back all 15 doctor accounts. No such confirmation has been received. The owner
wrote the consequence themselves: *if that person is not present, DO_NOT_ARM, CEREMONY=DEFERRED.*

**2. THE DEVICE PREREQUISITE CANNOT BE STARTED WITHOUT THE PHYSICAL TABLET AND THE DOCTOR.**
There is **no authorization row for (doctor 21, device 5)** — zero. So there is nothing to
approve: the `doctor-device-authorizations.approve` route acts on an existing pending row, and
that row is created by **drg Karmila attempting login or enrolment on the LDK2 tablet itself**,
then approved within the 15-minute enrolment TTL.

The whole surface is **HTTP/UI only** — `doctor-device-authorizations.{index,show,approve,reject,
revoke}` and `doctor-device-enrollments.{approve,reject}`. There is no artisan command, so it
cannot be driven from a shell. *(Device 5 already carries 2 WebAuthn credentials, 1 of them
active. A credential binds to the DEVICE, not the clinician, so a new credential may not be
needed — but the per-pair `DoctorDeviceAuthorization` is the explicit audit boundary and it is
what is missing.)*

**Arming now would be actively harmful.** One-session-per-doctor would go live fleet-wide for all
15 accounts inside a one-hour window in which nobody can proceed past the first prerequisite, and
the window would expire with the flag armed and unwatched. That is exactly the outcome the
owner's decision forbids.

### The exact sequence, for the operator, in order

Nothing below is optional and nothing below can be reordered.

1. **Confirm you are present** and hold `release_doctor_session_leases`, for the whole window.
2. **Re-run the six pre-arming values** (section 7 of the runbook has the queries). Expect the
   table above. If materially different, STOP.
3. **drg Karmila, physically at the LDK2 tablet (device 5)**, attempts login through the Clinic
   App. She is browser-denied by the enforcement cohort, so it must be the app.
4. **Approve the resulting authorization/enrolment request** at
   `doctor-device-authorizations.approve`, within the 15-minute TTL. Capture the before/after
   device row, authorization rows, credential rows, timestamps and audit rows — and attribute the
   new rows **to this ceremony**, not to identity drift.
5. **Arm `doctor.single_active_session`** only now, with step 1 still true. Fleet-wide for all 15
   Doctor-role accounts — not "UNSET doctors unaffected".
6. **Initial assignment** drg Karmila UNSET → SPN4, maker-checker, canonical workflow, no SQL.
   Remember it also calls `markOffline()` and frees her room; it is not "session only".
7. **Foreign-tablet proof** on device 5 at LDK2: require
   `DEVICE_PHYSICAL_BRANCH=LDK2 != HOME_LOCKED_BRANCH=SPN4` **and**
   `EFFECTIVE_CLINICAL_BRANCH=SPN4`. If the effective branch comes back LDK2 → **STOP, PR-B
   NO-GO**.
8. **Operational lists** with the documented historical date range, never the Today view. An
   empty list is a **FAILURE**.
9. **Cover**, then **expiry**, per the corrected steps G–N.
10. **Disarm `doctor.single_active_session`** before the window ends.
11. **Leave the clinician usable**: correct branch, signed in if operationally required, room
    state correct. Do not leave a real clinician logged out and roomless because a proof ended.

---

## 0b. Pre-arming safety gate — measured 2026-09-12, and it is CLEAN

The owner made arming conditional on provable preconditions. Measured on production:

| precondition | measured | verdict |
|---|---|---|
| doctors currently logged in | `doctor_role_sessions=0` of 72 server sessions (all guest) | **PASS** — no incumbent to collide with |
| clinical work in progress | 7 non-terminal visits, but latest visit anywhere is **2026-09-07** | **PASS** — stale, nothing today |
| lease baseline | `unreleased_leases=0`, `lease_rows_total=0` | **PASS** — clean slate |
| online-context flags | users 9, 15, 18 flagged online, `last_seen_at` 2026-09-09 | stale flags, **not** active work |
| `GLOBAL_ENFORCEMENT_ACTIVE` | `false` | **PASS** |
| pilot cohort | `9,15,18`, size 3, `SCOPE_VERDICT=GO` | **PASS**, unchanged |
| log watermark | `storage/logs/laravel.log` = **1406217 bytes**, 152 error lines | anchored on a byte offset |
| flag rollback | set the override back to `false` + clear config cache; no data touched | available |

**Arming with zero doctor sessions is the safest possible moment** — one-session-per-doctor
cannot deny anybody when nobody holds a session.

**The gate that is NOT met is organisational, and it is the operator's to close:** a declared
ceremony/maintenance window, with a person present who holds
`release_doctor_session_leases` and can watch all 15 accounts. Arming without the ceremony
following immediately would leave a fleet-wide flag live and unwatched, which is the exact thing
the owner's decision forbids.

**So: `DO_NOT_ARM` until an operator is present.** The technical state is clean and re-checkable
with the queries in section 7 of the runbook; only the human window is missing.

---

## A. Choose the subject

Owner picks **one** real doctor. Record their `mst_doctors.id`, their user id, and their
current state (expected: UNSET — no home lock row).

**Do not pick a doctor in the enforcement pilot cohort (user ids 9, 15, 18)** unless the owner
says otherwise. Those three are already denied browser login and are being verified separately
under section 16; adding a branch change to their day mixes two experiments.

Evidence: doctor id, user id, `locked_branch=UNSET`.

## B. Initial branch assignment

1. Person 1 files at `/rme/doctor-branch-locks/new`: the subject doctor, the destination
   branch, and a reason of at least the configured minimum length.
2. Person 2 opens `/rme/doctor-branch-locks`, reads the **presence column** for the subject,
   and approves.

If the subject shows **SEDANG ONLINE**, the screen requires the impact acknowledgement and the
service re-reads presence inside the transaction. That is not a formality: approving ends a
session that may belong to a clinician with a patient in the chair.

Evidence: the request id, the two distinct user ids, the `DOCTOR_BRANCH_LOCK_REQUESTED` and
`DOCTOR_BRANCH_LOCK_APPROVED` audit rows, and the resulting `home_branch_id`.

## C. Log in from a tablet that sits at a DIFFERENT branch

The doctor logs in **through the browser** on a tablet physically located at a branch that is
**not** their locked home branch.

**Do not look for a "trusted device" here, and do not try to use one.** An earlier draft of this
step asked for an approved, cryptographically verified tablet, and that was wrong in a way worth
stating: **none of the 12 candidate subjects holds a device authorization at all** — only users
9, 15 and 18 do, and those are the three this ceremony avoids. Device trust and branch authority
are independent (DBL-R021), and a non-pilot doctor needs no device authorization because
enforcement is scoped to the pilot cohort. So browser login on any tablet is the correct and
only available path, and it exercises the rule properly: the tablet contributes nothing.

What matters is only that the tablet is **physically at another branch**, because that is the
thing an operator can see and the thing a clinician would intuitively expect to determine their
branch.

Evidence: which tablet, where it physically sits, and that login succeeded.

*(If the owner instead wants the DEVICE path exercised, that means picking a pilot doctor — 9,
15 or 18 — whose browser login is denied, so they must use the Android app. That mixes this
ceremony with the enforcement pilot and evicts a doctor already under observation. Do it only on
an explicit owner instruction, and record that it was requested.)*

## D. Prove the tablet does not decide the branch

Record both, from the running application:

```
DEVICE_BRANCH            = <the tablet's branch>
LOCKED_HOME_BRANCH       = <from step B>
EFFECTIVE_CLINICAL_BRANCH= <what the doctor's session resolves to>
```

Required: `DEVICE_BRANCH != LOCKED_HOME_BRANCH` **and**
`EFFECTIVE_CLINICAL_BRANCH == LOCKED_HOME_BRANCH`.

## E. Operational lists show the effective branch only

**SET AN EXPLICIT DATE RANGE FIRST, or this step proves nothing at all.** Production has **zero
visits dated today** at every RME branch — the most recent visit anywhere is 2026-09-07. If
Daftar Kunjungan opens on its default "today" view the list is EMPTY whether or not the doctor is
locked, and "every row belongs to the locked branch" is then vacuously true. An earlier draft of
this step would have recorded a PASS for an observation that demonstrated nothing.

So widen the date filter to cover real data — **2026-08-01 to 2026-09-11** contains all 39 visits
— and **write down the row counts**, not an impression:

| branch | visits in that range |
|---|---|
| LDK2 | 17 |
| ATG3 | 11 |
| TLK1 | 9 |
| SPN4 | 2 |
| **all four** | **39** |

**Look BEFORE you arm the flags.** An unlocked doctor should see rows from all four branches,
approaching 39. Then, locked, they should see only their home branch's count. If the home branch
is LDK2 the expectation is a fall from ~39 to 17 — a number you can state, not a shape you can
squint at.

**An empty list is a FAILED step, not a passed one.** If either observation is empty, the date
filter is wrong; fix it before drawing any conclusion.

Then, on the locked session, open Daftar Kunjungan, the patient queue, and the room worklist.

Required: every row belongs to the locked home branch, the count matches that branch's figure
above, and the other branches' rows that were there before are gone. Not "mostly" — if one row
from another branch appears, stop and report it.

Evidence: the row COUNT before and after, per list, with the date range you used.

## F. The archive is still cross-branch — this is a REQUIRED PASS, not a leak

Open the Rekam Medis and the odontogram of a patient whose earliest visit was at a **different**
branch. Patient 28 spans three branches; 24, 27, 31 and 34 span two. Pick one whose earliest
visit is NOT at the locked home branch, or the step proves nothing.

Required: it opens and is readable. A doctor must be able to read the history of the patient in
front of them. If this is blocked, that is a FAILURE of the ceremony, not a security win.

## G. Create a time-boxed temporary cover — TWO covers, sequenced, and here is why

**An earlier draft of this ceremony prescribed ONE cover with a 5-minute tail and it would have
failed.** The reasoning was that a short tail lets expiry be observed without a long wait. What
it actually did was give the whole rest of the ceremony — approval, eviction, re-login, room
selection — five minutes of wall clock, because `assertUsableAt()` runs **again inside the
approval transaction against a later clock**
(`DoctorBranchCoverApprovalService.php:263-270`), and `ends_at > now` must still hold when the
approver clicks. Overrun before the click aborts with *"Periode cover sudah berakhir. Ajukan
periode baru."* and burns the clinical window. Overrun after it silently guts steps K and M.

Worse, the failure is **indistinguishable from a real defect**: once the period passes, the
resolver falls through to `DoctorEffectiveBranch::home()`, so selecting the cover branch is
refused with the ordinary home-lock message. Nothing says "expired". An operator would record a
FAIL on a step where the code behaved perfectly.

So the ceremony uses **two covers**, and they do not collide: `approvedForDoctorQuery()` filters
`whereNull('cancelled_at')`, so a cancelled cover neither counts as active nor blocks the next
one.

**Bounds, both real:** minimum 30 minutes, maximum 90 days, measured on the INTERVAL. A
backdated start is legitimate — the only clock rule is that the period must not already be over.
So a long tail costs nothing except the wait.

**The datetime-local field carries no seconds.** A window typed on a whole minute can be up to
59 seconds shorter than you intended. Never size a window so that a minute matters.

### G1 — Cover A, the comfortable one

File at `/rme/doctor-branch-covers/new`: the subject doctor, a target branch that is NOT their
home branch, and a period of **start ≈ 30 minutes ago, end ≈ 45 minutes from now**.

That satisfies the 30-minute floor with ~75 minutes of interval, is active the moment it is
approved, and leaves the approval, the eviction and the re-login entirely unhurried. Steps H
through K run against this cover.

The period is half-open, so a cover ending at 15:00 does not cover 15:00.

## H. Maker and checker are different user ids

Required before approving: `requester_user_id != reviewer_user_id`. On production there are
exactly two eligible accounts — user 1 (Super Admin) and user 11 (Supervisor RME) — so one files
and the other decides, in either order.

**Do not create a self-approval on production to test the refusal.** The negative cases are
already proven by automated test, including that `Gate::before` does not bypass the invariant.
Recording the two distinct ids is the whole of the production evidence needed here.

## I. Approve cover A

Person 2 approves from the queue.

Evidence: `DOCTOR_BRANCH_COVER_REQUESTED` and `DOCTOR_BRANCH_COVER_APPROVED` audit rows, the two
user ids, and the stored period.

## J. The previous session is invalidated

The doctor's open session must stop working on its **next request** — not instantly, and not in
place. Have them tap anything.

Required: they land on the login screen with a message they can act on.

Evidence: what the doctor actually saw, in their words.

## K. A fresh login uses the cover branch

**Before asking the doctor to log in, check cover A is still current.** With a 45-minute tail it
will be, but check anyway, because if it has closed the next paragraph's expected outcome
inverts and you would be recording a FAIL against correct behaviour.

The doctor logs in again and selects their working context.

Required: the cover branch is what they get, and the **home** branch is refused while the cover
runs. Repeat the step-E list check here: the lists should now show the COVER branch's rows, not
the home branch's — which is the clearest single demonstration that the effective branch, not the
home lock, drives what a doctor sees.

## K2. While cover A is still live: a permanent transfer is REFUSED

Do this now, because it is the only moment in the ceremony when a cover is current. It was
previously bundled into step O, which after the resequencing happens when both covers are
already finished — so the check would have been unreachable.

Person 1 files a permanent **transfer** for the same doctor to any other branch. Person 2 opens
it in the queue and attempts to approve.

Required: the approval is **refused** because a cover is active (DBL-R010). The refusal is
evaluated from current timestamps under the locks the approval already holds, so it is not
advisory.

Then **cancel that transfer request** (the requester may withdraw their own while it is pending),
so it is not left sitting in the queue. This step is read-only in effect: nothing about the
doctor's branch changes.

Evidence: the refusal message, and that the request ended cancelled rather than approved.

## L. Cancel cover A, then file cover B — the short one, for expiry

Person 2 cancels cover A from the queue, **with a reason**. That is the documented approver
escape hatch, and it also frees the doctor for the next cover.

Then file **cover B**: same doctor, a target branch that is not home, **start ≈ 30 minutes ago,
end ≈ 8 minutes from now**. Approve it immediately.

Eight minutes is deliberate: the approval only has to land inside the window, and eight minutes
is comfortable for that while still being a short wait. Do not shorten it to save time — that is
the mistake this section exists to prevent.

Confirm the doctor's session is invalidated again by cover B's approval, the same way as step J.

**Then have the doctor log in once more and go online at cover B's branch.** Step M needs a live
session established UNDER cover B in order to have anything to invalidate when the window
closes — without this, M has no session to test and would pass vacuously.

## M. Expiry is enforced from server time, and a stale session cannot continue

**Wait for cover B's end to pass. Run no command.** There is no expiry job and nothing to
trigger; if you find yourself looking for one, that is the point of this step.

Then have the doctor tap anything on the session they hold from cover B.

Required: that session stops working, exactly as in step J — with no scheduler having run, no
command issued, and nothing but the clock having changed.

## N. A fresh login returns to the home branch

The doctor logs in again.

Required: the **home** branch, not either cover branch. The home lock was never rewritten by
either cover.

Evidence: `home_branch_id` unchanged from step B.

## O. Permanent transfer — ONLY with explicit owner approval

Skip this step unless the owner says otherwise in writing. If approved, file and approve a
transfer the same maker/checker way, and confirm the session is invalidated and a fresh login
lands on the new branch.

The transfer-during-active-cover refusal is **not** checked here — it is step K2, which runs
while cover A is still live. By this point both covers are finished, so there is nothing to
refuse against.

## P. Restore, through the workflow

If O was only a test, restore the original branch by filing and approving a **transfer back**.

**Never restore with raw SQL, and never edit `mst_doctor_branch_locks` by hand.** A hand-edited
lock has no approver and no audit row, and the next person cannot tell it from a real decision.

---

## Identity accounting — compare against these exact values, not against blanks

**Equal counts are the weak form of this check.** A revoke-and-recreate leaves the count
identical, so compare the per-row state. This is production as measured on 2026-09-11, before
the ceremony:

| table | rows | detail |
|---|---|---|
| `mst_doctor_devices` | 5 | ids 1,3,4 at SPN4; 5 at LDK2; 6 at ATG3. 1 and 4 already revoked. |
| `mst_doctor_device_authorizations` | 5 | id 1 revoked 2026-09-07, id 3 rejected, ids 2/4/5 active |
| `trx_doctor_device_webauthn_credentials` | 5 | ids 1 and 4 already revoked (2026-09-08, 2026-09-09) |

**Required after the ceremony: identical, row for row.** No new revocation, no new row, no
status change. Every revocation above predates the ceremony, so **any `revoked_at` carrying
today's date is a failure** — that is the single clearest signal, and it is easier to check than
a count.

A branch decision touches **no identity row**. If anything in the table above moved, stop and
report it; that is the invariant DBL-R017 exists for.

**It is not true that it "ends a login session and nothing else", and an earlier draft said so.**
Every approval also calls `markOffline()`, which **vacates the doctor's clinic room** — nulls
`clinic_room_id` on their online context — deliberately, so an evicted doctor does not leave a
consultation room marked occupied and blocking the clinician taking over. That is correct
behaviour and it is a second effect, so the accurate sentence is: a branch decision ends a login
session and frees the room, and touches no device, authorization or credential.

## Put the doctor back — the ceremony does not end itself

After step N the subject doctor is **logged out and holds no clinic room**, because the last
approval evicted them and freed it. Nothing in steps A–P brings them back.

So finish deliberately: have the doctor log in, go online at their home branch, and take a room —
and confirm they can. If this is skipped, a real clinician arrives to an account that is signed
out with no room, on a branch they did not choose, and no record explains why.

*Reading it needs VPS shell access:* `php artisan db:show --counts` gives the totals, and the
per-row detail needs the read-only queries in section 7 of
`docs/runbooks/doctor-branch-lock-operations.md`. **Run every artisan command as the runtime
user** (`runuser -u daengtisiams -- php artisan …`); as root it writes root-owned cache files,
which has broken production login before.

## If anything fails — and read this BEFORE you need it

**Order matters, because disarming takes away the tools.** `assertCapabilityArmed()` 404s all
twelve actions while the branch flag is off — including **cancel** and **reject**. So if a step
failed leaving a PENDING request or an unwanted cover behind, **cancel or reject it FIRST, while
the surface still answers.** Disarm second. Get that order wrong and the pending row is stranded:
no screen can reach it, and the pending partial-unique index then refuses a second request for
that doctor until somebody re-arms to clear it.

1. **First**, cancel or reject anything pending, through the queue.
2. **Then** disarm `FEATURE_DOCTOR_BRANCH_LOCK` and clear the config cache. Nobody is logged out
   by that, no data is touched, and lock rows stay as they are.
3. **Decide about the second flag separately.** Disarming the branch lock leaves
   `FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION` armed, and that one is fleet-wide for every Doctor-role
   account (see "Two things to understand", point 1). If you armed it for this ceremony and the
   ceremony is over, disarm it too — otherwise one-session-per-doctor stays live estate-wide on a
   posture nobody reviewed.
4. **Put the doctor back** — see the section above. A failed ceremony still leaves them logged
   out and roomless.

Remember that any lock already approved **cannot be undone**, only transferred. Disarming hides
it; it does not remove it.

Do not attempt a fix by editing a lock row, a cover row, or a lease row directly.
