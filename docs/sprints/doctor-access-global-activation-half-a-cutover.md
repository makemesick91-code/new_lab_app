# DOCTOR-ACCESS-GLOBAL-ACTIVATION-1 — HALF-A FLEET CUTOVER

**Date:** 2026-09-17, window 22:13–23:0x WITA (Thursday, after service)
**Runtime authority:** `21b8a57a` / tree `44fc27ee` — tag
`doctor-access-global-activation-phase0-delta-recheck-1-go`, exact match.
**Nature:** configuration-only activation. **No source commit changed the runtime.**

> ```
> FINAL POSTURE (end of window)
>   single_active_session = TRUE    <- lease engine live, all 15 doctors
>   branch_lock           = FALSE   <- armed, proven, then disarmed by owner decision
>   BRANCH_LOCK_EFFECTIVE = FALSE
>   HALF_B_ACTIVE = NO              GLOBAL_FLEET_ENFORCEMENT_ACTIVE = false
> ```
>
> **Read §12b before quoting anything from §7–§11.** Branch lock was armed at
> 22:19, proven at 22:26, and deliberately disarmed ~22:5x once the owner stated
> the business model. Those sections are a proof of the capability, not a
> description of current behaviour.
>
> Half B was untouched throughout: no attestation signed, `global_permitted`
> unchanged, governance phase still `phase_4a`, browser scope still
> `pilot [9,15,18]`.

---

## 1. Human gates — all four passed before anything moved

| Gate | Answer |
|---|---|
| §4 activation window | **WINDOW READY** |
| §5 primary operator | **YES** |
| §5 rollback operator | **YES** |
| §22 fleet cutover approval | **APPROVE_HALF_A_FLEET_CUTOVER = YES** |
| §30/§31 proof doctor physically present | **YES** — drg Fiitri, ATG3 tablet |

Nothing was inferred from terminal state. The clock said 22:13 on a Thursday,
which *could* have been a post-service window — it was still asked, not assumed.

## 2. §6 — the blocker that held the previous window

`READ_ONLY_PSQL_PATH_AVAILABLE = YES`. The path that disappeared during the first
attempt was available again.

**Honest caveat, recorded rather than glossed:** this is the *application's* DB
credential, not a least-privilege read-only role. The read-only property comes
from discipline (SELECT only), not from the grant. Creating a dedicated role would
itself be a production DDL mutation and was out of scope, so nothing was broadened
— but the distinction is real and should be closed properly if this becomes routine.

## 3. §8/§9 — authority and health, pre-flip

```
PRODUCTION_HEAD = 21b8a57a8f275b697904b4c70a0deb8f51c85bda
PRODUCTION_TREE = 44fc27ee0433db0efe9afdb35dc8f663d00ae228
git describe --exact-match = doctor-access-global-activation-phase0-delta-recheck-1-go
tracked working tree       = clean
NO unexpected runtime code deployed since Phase-0.
PR #415 (docs-only) merged to the base branch but NOT deployed — expected; evidence
commits ride the next deploy.

/login 200 · /health/live 200 · /health/ready 200 (all components ok)
APP_DEBUG off · maintenance OFF · migrations pending 0 · failed jobs 0
laravel.log 1407663 bytes · no log file for today
HEALTH_GATE = PASS
```

## 4. §10 — fresh six-condition clinical gate, 22:15:59 WITA

The 07:34 reading from the earlier window was **discarded, not reused**.

| # | Condition | Result |
|---|---|---|
| 1 | live doctor sessions (`last_activity` within 120 min) | **0** |
| 2 | doctors holding a `clinic_room_id`, `offline_at IS NULL` | **0** |
| 3 | visits `in_progress` | **0** |
| 4 | unsafe active visits — doctor-owned, mid-pipeline, dated today | **0** |
| 5 | approved covers active now / transitioning within 4h | **0 / 0** |
| 6 | unreleased leases backed by a live session | **0** |

`CLINICAL_GATE = PASS`.

**§7 discipline held.** The script ran under `ON_ERROR_STOP on` and ended with a
sentinel (`GATE_QUERIES_COMPLETED_OK`, `PSQL_EXIT=0`), so every zero above is a
**MEASURED_ZERO** and not a swallowed query error. Later in the ceremony a query
*did* error (a wrong column name) — it aborted the script and was fixed and re-run
rather than interpreted. A broken query was never treated as a pass.

**§10's warning was respected.** Seven open visits exist — 5 `cashier_pending`
(2026-06-28…2026-09-07) and 2 `registered` (2026-09-02). None is unsafe: the
cashier ones await the cashier, not a doctor, and the two registered rows are
fifteen days stale. Condition 4 is scoped to *today*, so it correctly reads 0
without inventing a zero requirement for a condition whose semantics allow a safe
non-zero value.

## 5. §11–§16 — state rechecks

```
LEASES            8 total / 8 released / 0 unreleased / 0 blocking
KARMILA lease 8   released 2026-09-16 23:34:44 UTC, reason admin_release
                  0 open leases · 0 live session · 0 open presence (orphan closed)
FLEET presence    0 open rows anywhere

ACTIVATION_TEST_COVERAGE = PASS        LEVEL1_ATTESTED = true, contradiction = false
ELIGIBLE_DOCTORS 15 · LOCKED 15 · UNSET 0
ELIGIBLE_DEVICES 4 [3,5,6,7] · AUTHORIZATION 60/60 · missing 0 · duplicate 0 · unlinked 0

DEVICE 7 (TLK1)  active · cryptographically_verified · not revoked
                 1 credential · device-bound · no inadmissible credential

PRE-FLIP CONFIG  single_active_session=false · branch_lock=true (both env-resolved)
                 SESSION_DRIVER=database, sessions table present -> probe observable=true
                 => BRANCH_LOCK_EFFECTIVE = true AND false AND true = FALSE
HALF-B           pilot [9,15,18] · global_active=false · global_permitted=false · verdict GO
```

## 6. §18/§19 — the new baseline

The earlier baseline had been deleted on purpose because it predated the lease
release. A fresh one was captured **after** the lease release and **after** the
fresh gate, immediately before mutation.

```
BASELINE_CAPTURED_AT_UTC  = 2026-09-17T14:17:41Z
BASELINE_CAPTURED_AT_WITA = 2026-09-17T22:17:41+0800
BASELINE_VALID_FOR_THIS_WINDOW = YES

scope.json   e5aef8fb…    flags.json  e5a8a40d…
estate.json  d373976a…    fleet.json  047cc8c4…

HOME_LOCK_HASH     7bee99aec816da958bacfc97b6460a9d
DEVICE_ESTATE_HASH 91a933a03504eded6c1ae7a40eecbb4e
LEASE_STATE_HASH   db18f24e555aceb8cd6113340c765912
AUDIT_WATERMARK    884 / 862 rows
LOG_WATERMARK      1407663 bytes
PRE-FLIP SCOPE     pilot · cohort [9,15,18] · branch SPN4 · global_permitted false
```

No secret-bearing environment capture was taken.

## 7. §24–§26 — the mutation

One key, one line, on `srv1730088:/var/www/asia-dental-lab-v2`:

```
.env line 96   FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=false  ->  true
```

Integrity verified rather than assumed:

```
lines      97 -> 97          (unchanged)
key match   1 -> 1           (exactly one)
diff        exactly one changed line, and it is that key
perms      640 root:daengtisiams   (preserved)
```

**No backup copy of the environment file was made** — it would duplicate secrets
on disk for a single-line change whose inverse is exact. A transient integrity
copy lived in `/dev/shm` for the diff and was shredded immediately.

Config realization as the declared runtime user (`daengtisiams`; the deploy script
refuses `root` and `www-data` by name):

```
optimize:clear · config:cache · route:cache · view:cache   — all exit 0
bootstrap/cache/config.php now daengtisiams:daengtisiams @ 14:19:21 UTC
```

Nothing else was touched: not the browser scope, not `global_permitted`, not
`branch_lock`, not enforcement.

## 8. §27–§29 — effective state, health, leases

```
SINGLE_ACTIVE_SESSION = true
BRANCH_LOCK_FLAG      = true
SESSION_STORE_OBSERVABLE = true
BRANCH_LOCK_EFFECTIVE = TRUE

/login 200 · /health/live 200 · /health/ready 200 (all components ok)
laravel.log BYTE-IDENTICAL at 1407663 — zero new errors
leases 8 total / 0 unreleased — no released row resurrected
HALF-B unchanged: pilot [9,15,18] · global_active false · global_permitted false
```

## 9. §30–§37 — the live proof

**Subject chosen deliberately, not for convenience.** drg Fiitri (user 15, doctor
20) is locked to **TLK1** while every observed signal about her work — her visits,
her online context, her proven tablet — is **ATG3**. Karmila was explicitly not
chosen. Pre-login she held 0 sessions, 0 leases, 0 presence rows.

```
DEVICE 6  PILOT_TABLET_05_ATG3 · status active · cryptographically_verified
          not revoked/disabled · physical branch ATG3
CRED 5    not revoked · user_verified · backup_eligible FALSE
          device_bound_verdict = device_bound        (not a synced passkey)
AUTHZ 5   doctor 20 <-> device 6 · status ACTIVE · approved 2026-09-09
```

### §34 first session

```
ACTIVE_SESSIONS 1 · ACTIVE_LEASES 1
LEASE 9 · user 15 · doctor 20 · session kJmcKtrF… (session_row_matches = 1)
claimed_at 14:26:55 UTC — AFTER the 14:19:21 cutover
effective_cover_id = none
```

### §35 cross-branch branch-lock proof — **PASS**

```
HOME_BRANCH            TLK1
DEVICE_PHYSICAL_BRANCH ATG3      <- different
ACTIVE_COVER           0
EFFECTIVE_BRANCH       TLK1 (branch_id 1)    <- NOT ATG3
```

She logged in on a tablet physically at ATG3 and her clinical branch resolved to
her home lock. **Device location is not branch authority** — demonstrated on live
production, and the exact mirror of the 2026-09-13 experiment that produced SPN4
while the resolver was disarmed.

### §36 second concurrent login — **DENIED**

```
AUDIT 888  DOCTOR_DEVICE_LOGIN_REQUESTED   by 15
           {"status":"active","doctor_id":20,"device_status":"active","doctor_device_id":3}
           authz 18 = doctor 20 <-> device 3 PHASE4A_PILOT_TABLET_02 @ SPN4, ACTIVE
AUDIT 889  DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED   performed_by = NULL
           {"reason": "active_session_elsewhere"}
```

A **fully valid authorization on an active device** was refused for exactly one
reason. Not a device fault, not an authorization problem.

**§38 earned its keep.** The Clinic App said only *"sesi perangkat tidak dapat
dibuat"* — a string naming no cause. Reading it as a device or transport failure
would have been wrong. The reason came from `sys_audit_logs`, and note the shape:
the **rejection row carries `performed_by = NULL`** while the request row carries
the user id, so a per-user audit query alone would have missed the refusal entirely.

### §37 incumbent preservation — **PASS**

```
FIRST_SESSION_ROWS 1 · session unchanged · last_activity unchanged
LEASE 9 STILL_OPEN · session_id unchanged · effective branch TLK1 · reason "-"
FIRST_SESSION_EVICTED = NO
```

Deny-the-second, never evict-the-first.

### §42 canonical logout — **PASS**

```
FIITRI_SESSIONS 0 · OPEN_LEASES 0 · OPEN_PRESENCE 0 · ORPHAN_PRESENCE_FLEET 0
LEASE 9 released 14:32:09, reason "logout"     <- canonical terminal state
AUDIT 890 DOCTOR_SESSION_LEASE_RELEASED by 15
```

## 10. §43 — fleet sanity after the proof

```
FLEET_OPEN_LEASES 0 · ANY_DOCTOR_SESSION 0
HOME_LOCK_HASH     7bee99ae…  == baseline    (unchanged)
DEVICE_ESTATE_HASH 91a933a0…  == baseline    (unchanged)
ACTIVE_AUTHZ 60 · UNREVOKED_CREDS 4 · COVERS_APPROVED_ACTIVE 0
```

Audit moved **884 -> 890**: six rows, every one of them this ceremony
(885 request, 886 lease claimed, 887 auth success, 888 second request,
889 rejected, 890 lease released). Nothing else moved on production.

## 11. §44 — state after success

```
single_active_session = true      branch_lock = true
BRANCH_LOCK_EFFECTIVE = true
GLOBAL_FLEET_ENFORCEMENT_ACTIVE = false
CURRENT_BROWSER_ENFORCEMENT_SCOPE = pilot [9,15,18]
HALF_B_MUTATIONS = 0
```

## 12. Rollback, still ready

```
FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=true -> false
optimize:clear ; config:cache ; route:cache ; view:cache      (as daengtisiams)
verify: single_active_session=false · BRANCH_LOCK_EFFECTIVE=false · health 200/200/200
```

Preserved by rollback: HOME locks · covers · devices · authorizations ·
credentials · audit history. No session is torn down; no lease row is altered.
**An environment edit alone is not a rollback** — production runs cached config
and skips the environment file entirely when cached.

## 12b. THE MODEL CORRECTION — branch lock disarmed the same night

**Roughly forty minutes after the cutover the owner stated the business model:**

> *"dokter bisa login/bekerja di semua cabang dengan catatan dokter wajib login di
> device cabang yang sudah terdaftar di sistem"*

That is the **opposite** of what branch lock had just been proven to do. Branch
lock pins a doctor to their home branch regardless of which registered device they
use; the model says the doctor may work anywhere, with the device requirement as
the control. Left armed, it would have narrowed all 15 doctors at the next
morning's clinic.

The two mechanisms turned out to be **separable in production**, which made the
correction small rather than a rollback:

```
DoctorSessionLeaseService::enabled()      = single_active_session AND probe
DoctorEffectiveBranchResolver::enabled()  = branch_lock AND single_active_session AND probe
```

The lease does **not** consult `branch_lock`. So:

```
.env line 97   FEATURE_DOCTOR_BRANCH_LOCK=true  ->  false
               (line 96, FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=true, untouched)
optimize:clear ; config:cache ; route:cache ; view:cache   as daengtisiams
```

Integrity verified the same way: 97 lines before and after, exactly one changed
line, perms `640 root:daengtisiams` preserved, transient diff copy shredded.

### Result

```
single_active_session = TRUE      <- lease engine still live, proof still valid
branch_lock           = FALSE
BRANCH_LOCK_EFFECTIVE = FALSE     <- nothing narrows
trusted_device_enforcement = TRUE    pwa_webauthn_device_login = TRUE
health 200/200/200 · laravel.log byte-identical 1407663
leases 9 total / 0 unreleased — nobody evicted, nothing resurrected
HOME LOCKS: 15 RETAINED           <- stored, inert, re-armable with one flag
HALF B unchanged: pilot [9,15,18] · global_active false · global_permitted false
```

**Nothing about the Half-A proof was invalidated.** Single session, the lease
binding, the denied second login and the un-evicted incumbent all still hold —
they ride `single_active_session`, which never moved. What was withdrawn is the
branch-narrowing half, and its proof stands as evidence the capability *works*,
not as a description of current behaviour.

### The honest gap

The stated model has two halves, and only one is enforced:

| Half of the model | Status |
|---|---|
| doctor may work at any branch | **enforced** — branch lock off, nothing narrows |
| only on a registered device of that branch | **3 of 15 doctors only** |

`trusted_device_enforcement` is true but its scope is `pilot [9,15,18]`. The other
twelve doctors can still log in by browser with no registered device at all.
Making that requirement fleet-wide **is Half B**, which is blocked on real
hardware and on attestations that are untrue today.

**This gap was not closed by signing an attestation to make a gate green.** It is
recorded as rule **AW-R17**: never report a half-enforced model as enforced.

## 13. A finding the proof surfaced, and how it was actually resolved

drg Fiitri's home lock is **TLK1**, but every real signal about where she works is
**ATG3**. Arming branch lock narrowed her to TLK1 — correct behaviour, and
invisible for as long as the resolver was disarmed.

Three remedies were available: a governed home-branch transfer, an approved cover,
or disarming the capability. **The owner chose the third**, and the reason is the
part worth keeping: the mismatch was not one doctor's data being wrong, it was the
*model* being wrong. No number of per-doctor transfers expresses "doctors work at
any branch on a registered device" — that is a statement about the instrument, not
about Fiitri.

Recorded as **AW-R16**: when arming surfaces a contradiction, ask whether the data
or the model is wrong **before** writing transfers; and re-check the home-branch
matrix against real working location before any future re-arm.

Note what was *not* done: no transfer was written, no cover was created, no lock
row was edited, and no flag was flipped to paper over a data problem. The 15 locks
sit exactly as the owner approved them on 2026-09-13, ready if the model changes
back.

## 14. Scope discipline

Out of scope and untouched, per §2: Half B, global browser/device enforcement,
`global_permitted`, Half-B attestations, browser scope, tablet provisioning,
`DoctorDeviceAuthorization`, credentials, HOME locks, covers, transfers,
15-doctor ceremonies, and unrelated runtime fixes.

`HALF_B_AUTHORIZED = NO`. Half A's success authorizes nothing about Half B.
