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

**Arming is not the risky step.** While every doctor is UNSET the capability is inert. The
first approval is what changes a real clinician's day.

| surface | URL |
|---|---|
| approver queue | `/rme/doctor-branch-locks` |
| file a lock request | `/rme/doctor-branch-locks/new` |
| file a cover | `/rme/doctor-branch-covers/new` |

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

**Look at the lists BEFORE you arm the flag, and write down what you see.** Production holds 39
visits across the four RME branches, so an unlocked doctor sees rows from all of them. Without
that "before", a narrowed list is just a short list and proves nothing — which is why this step
now asks for the contrast rather than for a single observation.

Then, on the locked session, open Daftar Kunjungan, the patient queue, and the room worklist.

Required: every row belongs to the locked home branch, and the rows from other branches that
were there before are gone. Not "mostly" — if one row from another branch appears, stop and
report it.

Evidence: what each list showed before, and what it shows after, naming branches.

## F. The archive is still cross-branch — this is a REQUIRED PASS, not a leak

Open the Rekam Medis and the odontogram of a patient whose earliest visit was at a **different**
branch. Patient 28 spans three branches; 24, 27, 31 and 34 span two. Pick one whose earliest
visit is NOT at the locked home branch, or the step proves nothing.

Required: it opens and is readable. A doctor must be able to read the history of the patient in
front of them. If this is blocked, that is a FAILURE of the ceremony, not a security win.

## G. Create a time-boxed temporary cover

Person 1 (or 2 — but see H) files at `/rme/doctor-branch-covers/new`: the subject doctor, a
target branch, a start and an end on the **clinic wall clock**, and a reason.

**The minimum cover duration is 30 minutes** (`doctor_access.cover.min_minutes`), and the
maximum is 90 days. A period "a few minutes long" is REFUSED at both filing and approval, so do
not plan one — you would only be testing the bound.

So plan the window deliberately: **start the cover about 30 minutes in the past and end it
about 5 minutes from now.** That satisfies the 30-minute minimum, is active the moment it is
approved, and expires inside the ceremony window so steps L, M and N can be observed without
waiting half an hour. Backdating the start is legitimate — the only rule is that the period
must not already be over, which `assertUsableAt()` checks against the clock at both filing and
approval.

The period is half-open, so a cover ending at 15:00 does not cover 15:00.

## H. Maker and checker are different user ids

Required before approving: `requester_user_id != reviewer_user_id`.

**Do not create a self-approval on production to test the refusal.** The negative cases are
already proven by automated test, including that `Gate::before` does not bypass the invariant.
Recording the two distinct ids is the whole of the production evidence needed here.

## I. Approve the cover

Person 2 approves from the queue.

Evidence: `DOCTOR_BRANCH_COVER_REQUESTED` and `DOCTOR_BRANCH_COVER_APPROVED` audit rows, the
two user ids, and the stored period.

## J. The previous session is invalidated

The doctor's open session must stop working on its **next request** — not instantly, and not in
place. Have them tap anything.

Required: they land on the login screen with a message they can act on.

Evidence: what the doctor actually saw, in their words.

## K. A fresh login uses the cover branch

The doctor logs in again and selects their working context.

Required: the cover branch is what they get; the home branch is refused while the cover runs.

## L. Expiry is enforced from server time

Wait for the period to pass. **Run no command.** There is no expiry job and nothing to trigger;
if you find yourself looking for one, that is the point of this step.

## M. A stale cover session cannot continue

If the doctor still has a session open from step K, have them tap anything after the period
ends.

Required: that session stops working, the same way as step J.

## N. A fresh login returns to the home branch

The doctor logs in again.

Required: the **home** branch, not the cover branch. The home lock was never rewritten.

Evidence: `home_branch_id` unchanged from step B.

## O. Permanent transfer — ONLY with explicit owner approval

Skip this step unless the owner says otherwise in writing. If approved, file and approve a
transfer the same maker/checker way, and confirm the session is invalidated and a fresh login
lands on the new branch.

Also confirm: a transfer is **refused** while a cover is current. If you still have a live
cover, try it and record the refusal.

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

A branch decision ends a login session and nothing else. If anything here moved, stop and
report it; that is the invariant DBL-R017 exists for.

*Reading it needs VPS shell access:* `php artisan db:show --counts` gives the totals, and the
per-row detail needs the read-only queries in section 7 of
`docs/runbooks/doctor-branch-lock-operations.md`. **Run every artisan command as the runtime
user** (`runuser -u daengtisiams -- php artisan …`); as root it writes root-owned cache files,
which has broken production login before.

## If anything fails

Disarm `FEATURE_DOCTOR_BRANCH_LOCK` and clear the config cache. Nobody is logged out by
disarming, no data is touched, and lock rows stay as they are. Then diagnose.

Do not attempt a fix by editing a lock row, a cover row, or a lease row directly.
