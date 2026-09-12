# Runbook — doctor branch lock and cover operations

Rules: `docs/architecture/doctor-branch-lock-and-cover.md` (DBL-R001..R025).
Capability flag: `doctor.branch_lock` (`FEATURE_DOCTOR_BRANCH_LOCK`), risk `critical`,
committed **false**.

This runbook covers **which branch a doctor works in**. The one-session rule has its own
runbook, `docs/runbooks/doctor-session-lease-operations.md`, and arming this capability
requires that one to be armed first — see step 1.

---

## 1. Before you arm anything

1. **Arm the session lease first.** `doctor.branch_lock` refuses to resolve unless
   `doctor.single_active_session` is also on and the session store is observable
   (DBL-R002).

   **What arming this flag alone actually looks like: every one of these screens returns
   404.** `assertCapabilityArmed()` is the first statement of all twelve controller actions
   and it is `abort_unless($resolver->enabled(), 404)`, so with the lease flag off the
   approver queue and both filing forms are simply not there. That is the symptom to expect,
   and it is a *good* symptom — a hard 404 is far easier to diagnose than a surface that
   renders while answering UNSET to everything.

   So if an operator reports "the branch-lock pages are 404 and I definitely enabled the
   flag", the answer is almost always this: the other flag is off, or the session driver is
   not `database`.
2. Confirm the permissions exist in the deployed database. They are seeded, not created by
   the deploy:
   ```
   php artisan db:seed --class=PermissionSeeder --force
   php artisan db:seed --class=RoleSeeder --force
   php artisan permission:cache-reset
   ```
   The four are `view_doctor_branch_locks`, `manage_doctor_branch_locks`,
   `approve_doctor_branch_locks`, `release_doctor_session_leases`.
3. Confirm that **every doctor is still UNSET**, which is the state this ships in. If any
   doctor already holds a lock row, somebody assigned one — find out who before arming.

---

## 2. Arming the flag

**Nothing happens to a doctor who has no lock row.** That is the entire safety property of
this capability: arming it while the fleet is UNSET is inert, and arming it is therefore
NOT the risky step. The risky step is the first approval.

1. Set the override in the deployed environment file and clear the config cache.
2. Confirm the capability is live rather than inert, using a doctor who is still UNSET:
   - the approver queue renders and lists no degraded locks;
   - the doctor's branch selector still offers **every** RME branch they practise at;
   - a visit written by that doctor at any of those branches still succeeds.

   All three are the pre-sprint behaviour, and seeing them is how you know arming did not
   quietly narrow somebody.
3. If any doctor's list narrowed, **disarm first and diagnose afterwards.**

### Rollback

Set the override back to `false` and clear the config cache.

The resolver returns null for everyone, so nothing narrows and nothing is refused. **No
doctor is logged out by disarming**: the session-invalidation comparison fires only when
both sides have an answer (DBL-R016), and after disarming one side is null.

**No data is touched.** Lock rows and cover rows stay exactly as they are, and re-arming
restores the same answers. There is no cleanup, and there is nothing to un-apply.

---

## 3. The two lock workflows

Both are maker-checker: **one person files, a different person decides** (DBL-R011). Either
Super Admin or Supervisor RME may be either party. The service refuses the same account
doing both, inside the transaction, so a Super Admin cannot get past it.

| | from | to |
|---|---|---|
| INITIAL ASSIGNMENT | UNSET | an approved home branch |
| PERMANENT TRANSFER | home branch A | an approved home branch B |

The type is **derived server-side** from the doctor's live lock. A requester does not choose
it and cannot send it.

### Filing

`GET /rme/doctor-branch-locks/new` → `POST /rme/doctor-branch-locks`.

A doctor may file their **own** request with none of the four permissions; the policy
matches on `mst_doctors.user_id`. They can never approve it.

### Deciding

`GET /rme/doctor-branch-locks` is the queue. Approve or reject from there.

**Before approving, read the presence column.** If the subject is ONLINE, approving ends a
session belonging to a clinician who may have a patient in the chair. The screen requires an
acknowledgement and the service re-reads presence inside the transaction, so a screen drawn
before the doctor came online cannot approve past them.

A rejection **requires a written reason** (DBL-R012). An approval note is optional.

### What an approval does, and what it does not

Does: writes the lock, ends the doctor's login session, **frees the doctor's clinic room**,
writes an audit row.

The room is the part people are surprised by. Approval calls `markOffline()`, which nulls
`clinic_room_id` on the doctor's online context, so the consultation room is not left marked
occupied against a clinician who needs it. The doctor does not get it back by logging in — they
re-select it, so say so when you tell them they have been logged out.

Does **not**: touch the device, the device authorization or the WebAuthn credential
(DBL-R017). If you are reaching for a device revocation to undo a branch change, stop — the
undo is a transfer back, filed and approved the same way.

---

## 4. Temporary cover

Cover is time-boxed authority to work at another branch. **It never rewrites the home
lock** — the home branch is retained and returned to.

`GET /rme/doctor-branch-covers/new` → `POST /rme/doctor-branch-covers`, then approve or
reject from the same queue. A doctor can neither create nor approve a cover, including their
own.

### The period

Instants, half-open `[starts_at, ends_at)` (DBL-R006). Operator input is read on the
**clinical** wall clock.

- 08:00–12:00 then 12:00–16:00 — **allowed**, they do not overlap.
- 08:00–12:00 then 10:00–14:00 — refused.
- Two different doctors over the same hours — **allowed** (DBL-R007).
- A new cover after the previous one's period has passed — **allowed** (DBL-R005).

### Activation and expiry are the same mechanism

Both are decided from current timestamps on every protected request. **There is no
scheduler in the correctness path** (DBL-R005): if a housekeeping job is late, an expired
cover is still expired. Do not "fix" a stuck cover by running a command — there is no
command to run, and there is nothing stuck.

Both activation and expiry end the doctor's session, so the doctor logs in again and lands
on the branch that is effective now. A branch never changes underneath an
already-authenticated session.

### A permanent transfer during a cover

Refused while the cover is **current** (DBL-R010). A future-dated cover does not block and
is shown to the approver instead. Either wait for the cover to end or cancel it, with a
reason, then file the transfer.

---

## 5. The audit trail

Read from `sys_audit_logs`.

| action | written when |
|---|---|
| `DOCTOR_BRANCH_LOCK_REQUESTED` | somebody filed an assignment or transfer |
| `DOCTOR_BRANCH_LOCK_APPROVED` | the lock moved; carries `request_type` and `session_released` |
| `DOCTOR_BRANCH_LOCK_REJECTED` | refused, with the required reason |
| `DOCTOR_BRANCH_LOCK_CANCELLED` | the requester withdrew it |
| `DOCTOR_BRANCH_COVER_REQUESTED` | a cover was filed |
| `DOCTOR_BRANCH_COVER_APPROVED` | the cover is granted |
| `DOCTOR_BRANCH_COVER_REJECTED` | refused, with the required reason |
| `DOCTOR_BRANCH_COVER_CANCELLED` | withdrawn or called off |
| `DOCTOR_EFFECTIVE_BRANCH_WRITE_REFUSED` | a visit write was refused for being outside the effective branch; `entity_id` is NULL because no visit was created |

There is deliberately **no audit row for cover expiry and none for a degraded lock**. Both
are standing conditions recomputed on every request, and auditing them would write a row per
page view (DBL-R003). Expiry is visible as the absence of a current period; a degraded lock
is visible on the queue.

---

## 6. A degraded lock — "Kunci Cabang Tidak Berlaku"

A doctor's locked branch lost `is_active` or `is_rme_enabled`, so the lock no longer applies
and the doctor is working under pre-sprint rules. **They are not locked out**, and there is
no outage to fix in a hurry.

The queue shows the retained home branch, a reason **code** and a sentence. Quote the code
in a ticket:

| code | meaning |
|---|---|
| `home_branch_not_rme_enabled` | the home branch is no longer an RME branch |
| `cover_branch_not_rme_enabled` | a current cover's target is no longer an RME branch |

Two honest ways out, and both are decisions somebody has to make:

1. Re-enable the branch, if it was disabled by mistake.
2. File a **transfer** to a branch that is active and RME-enabled.

Do not edit `mst_doctor_branch_locks` by hand. It leaves no trail and no approver.

---

## 7. Diagnosing without the screens

Read-only, and safe on production.

```sql
-- One doctor's home lock. There can be at most one row.
SELECT doctor_id, home_branch_id, established_via, established_at, established_by_user_id
FROM   mst_doctor_branch_locks
WHERE  doctor_id = :doctorId;

-- Is a cover current RIGHT NOW? This is the same predicate the runtime uses.
SELECT id, target_branch_id, starts_at, ends_at
FROM   trx_doctor_branch_covers
WHERE  doctor_id = :doctorId
  AND  status = 'approved'
  AND  cancelled_at IS NULL
  AND  starts_at <= :now
  AND  ends_at   >  :now;

-- What the doctor's open session was established under. A mismatch against the
-- two answers above is exactly why the next request ends that session.
SELECT id, effective_branch_id, effective_cover_id, claimed_at
FROM   trx_doctor_session_leases
WHERE  doctor_id = :doctorId AND released_at IS NULL;
```

`:now` must be a **UTC** instant. The columns store UTC, and a clinical-zone reading of the
same moment is eight hours away from them, so it matches nothing and the query answers "no
cover" for a doctor who has one (DBL-R024).

A lease whose `effective_branch_id` is NULL was claimed before this capability was deployed.
It is never compared, so it evicts nobody, and **no backfill may be added** — inventing a
branch for a session established before the concept existed would be a fabricated clinical
fact.

---

## 8. Related

- `docs/architecture/doctor-branch-lock-and-cover.md` — the rules.
- `docs/runbooks/doctor-session-lease-operations.md` — the capability this one depends on,
  and the force-logout escape hatch.
- `docs/sprints/doctor-access-pr-b-branch-lock-cover.md` — what was and was not proven.
