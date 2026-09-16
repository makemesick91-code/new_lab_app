# Doctor global activation — the activation window

`DOCTOR-ACCESS-GLOBAL-ACTIVATION-1`. Written by the Phase-0 delta recheck
(2026-09-17) against production `55830629` / tree `a9b92cb3`.

> **This runbook is not an approval.** Nothing in it may be executed until the
> owner records `APPROVE_DOCTOR_GLOBAL_ACTIVATION_APPLY=YES`. Until then
> `GLOBAL_ACTIVATION_APPLY_AUTHORIZED = NO`.

---

## 0. What you are about to arm

**Half A** — `FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=true`. One host variable plus a
config-cache rebuild. No source change. It is a **fleet-wide cutover**: every
Doctor-role account, no cohort, no cap, no staged mechanism. Never call it a pilot
or a wave.

Its two effects land at **different moments**, and the plan depends on knowing which:

| Effect | When |
|---|---|
| Branch narrowing — lists and cross-branch write refusal | **immediately, for everyone**, including doctors already logged in |
| Lease enforcement and session eviction | **at each doctor's next login**; nobody already logged in is evicted |

**Half B** — fleet-wide browser/device enforcement. **BLOCKED.** It needs a reviewed
change to `global_permitted`, a governance-phase move, and five attestations of which
two are measurably or knowably untrue. It belongs to a dedicated sprint and must not
be folded into a Half A window.

---

## 1. Before the window opens

| # | Item | How |
|---|---|---|
| 1 | Window scheduled, operators present | outside this document |
| 2 | Owner APPLY approval recorded | outside this document — **hard gate** |
| 3 | Rollback owner identified and reachable by SSH | `srv1730088` |
| 4 | Decide the lease order (§3 or §4 below) | — |

---

## 2. Health and authority (read-only)

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://daengtisia.online/login
curl -s https://daengtisia.online/health/live
curl -s https://daengtisia.online/health/ready
```

Expect `200` and `status: ok` with every component `ok`. An IP request returns `301`
to HTTPS — that is the redirect, not a failure.

```bash
ssh daengtisiams-vps
cd /var/www/asia-dental-lab-v2
git rev-parse HEAD && git describe --exact-match HEAD
runuser -u daengtisiams -- php artisan migrate:status | grep -c Pending   # expect 0
runuser -u daengtisiams -- php artisan queue:failed                        # expect none
```

---

## 3. The clinical hard gate

`NO_UNSAFE_ACTIVE_CLINICAL_WORK = YES` requires **all six**, measured within minutes
of the cutover. **`online = false` alone is not proof** — the presence table can hold
an open row for a session that no longer exists.

| # | Condition | Target |
|---|---|---|
| 1 | live doctor sessions — `sessions` × Doctor-role users, `last_activity >= now() - SESSION_LIFETIME` | **0** |
| 2 | doctors holding a room — `trx_user_online_contexts` `offline_at IS NULL AND role_context='doctor' AND clinic_room_id IS NOT NULL` | **0** |
| 3 | examinations in flight — `trx_clinic_visits.status = 'in_progress' AND deleted_at IS NULL` | **0** |
| 4 | visits dated today (WITA) | **0**, or each explicitly accepted by the operator |
| 5 | unreleased leases backed by a live session — `IncumbentSessionProbe::isLive()` per row | **false** for every row |
| 6 | approved covers overlapping the window | **0** |

Conditions 1–3, 5 and 6 are hard. Condition 4 is a recorded judgement.

---

## 4. Lease reconciliation — inside the window, not before

Read every lease fresh. Do not assume the Phase-0 inventory still holds.

```sql
SELECT id, user_id, doctor_id, session_id, claimed_at, released_at,
       effective_branch_id, effective_cover_id
  FROM trx_doctor_session_leases ORDER BY id;
```

For each row with `released_at IS NULL`, decide:

- **LEGITIMATE_LIVE** — the doctor is working. Do not release. Either wait, or ask
  them to log out through the UI.
- **STALE_ORPHAN** — no live session row for that user. Release it, audited.
- **AMBIGUOUS** — resolve it before proceeding. Never guess.

```bash
# dry run FIRST — writes nothing
runuser -u daengtisiams -- php artisan doctor:session-force-logout \
    --doctor=<mst_doctors.id> --actor=<id|email> --reason="<10-1000 chars>"

# then, only after reading the dry run
runuser -u daengtisiams -- php artisan doctor:session-force-logout \
    --doctor=<mst_doctors.id> --actor=<id|email> --reason="<10-1000 chars>" --apply
```

This command carries **no feature-flag guard** — that is exactly what makes cleanup
possible while Half A is still off. It ends a login session and frees the clinic
room; it revokes no device, no authorization and no credential. The actor needs
`release_doctor_session_leases`.

> **Why not earlier.** `IncumbentSessionProbe::isLive()` keys on `sessions.user_id`,
> not on the lease's recorded `session_id`. A doctor who logs in **while the flag is
> off** writes a session row and claims no lease — so arming afterwards turns their
> old lease into a live incumbent that refuses their next login. A lease released
> days early is simply replaced.

Re-verify: every remaining unreleased lease must be `isLive() == false`.

---

## 5. Capture the rollback baseline

Read-only. **No destructive backup** — hashes and watermarks, not dumps.

```bash
cd /var/www/asia-dental-lab-v2
runuser -u daengtisiams -- php artisan android:phase4a-pilot-scope --json  > /tmp/pre-scope.json
runuser -u daengtisiams -- php artisan foundation:feature-flags   --json  > /tmp/pre-flags.json
runuser -u daengtisiams -- php artisan doctor:estate-resilience   --json  > /tmp/pre-estate.json
runuser -u daengtisiams -- php artisan doctor:fleet-readiness     --json  > /tmp/pre-fleet.json
sha256sum /tmp/pre-*.json
stat -c '%s' storage/logs/laravel.log          # LOG_WATERMARK
```

Plus `MAX(id)` and `MAX(performed_at)` from `sys_audit_logs` (AUDIT_WATERMARK), and
the health baseline from §2.

**The cohort is the union of two host variables** — a singular `..._DOCTOR_USER_ID`
and a plural `..._DOCTOR_USER_IDS`. Restoring only one restores the wrong cohort.
`android:phase4a-pilot-scope` reports the resolved union; restore **that**, never a
remembered constant.

`declared_pilot_doctor_user_id` reads `null` whenever the cohort has more than one
member. That is by design, not a missing variable.

---

## 6. Half A cutover

```bash
# 1. edit the environment file — this alone changes NOTHING while config is cached
FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=true

# 2. realize it
runuser -u daengtisiams -- php artisan optimize:clear
runuser -u daengtisiams -- php artisan config:cache
runuser -u daengtisiams -- php artisan route:cache
runuser -u daengtisiams -- php artisan view:cache

# 3. verify EFFECTIVE state, not the file
runuser -u daengtisiams -- php artisan foundation:feature-flags --json
```

Never run these as `root` or `www-data`. The deploy script refuses those users by
name (`DMS_FORBIDDEN_RUNTIME_USERS`); do the same by hand.

Expected immediately after: `doctor.single_active_session = true`,
`doctor.branch_lock = true`, and the branch resolver `enabled()` now true — so every
locked doctor's lists are narrowed **at once**, while no open session is evicted.

---

## 7. Half A verification

1. **Branch narrowing** — a locked doctor's branch selector offers only their home
   branch plus an active cover. Verify against a **locked** doctor; there is no UNSET
   doctor left to use.
2. **Cross-branch write refusal** — a visit create outside the effective branch is
   refused, and `ClinicVisitService::auditEffectiveBranchWriteRefusal()` writes one
   audit row per attempt.
3. **Second-login denial** — log in as one doctor, then attempt a second session
   elsewhere. Expect `DENY_ACTIVE_SESSION_ELSEWHERE`. **The first session is never
   evicted.** Use a doctor who logged in *after* the cutover — a pre-cutover session
   holds no lease token and will not demonstrate this.
4. **HTTP force-logout is now reachable** — the `release-session` route stops 404ing
   the moment the resolver arms.

---

## 8. Rollback

**Half A.** Set the override back to `false` and rebuild the cache (§6 step 2).

```
single_active_session = false
branch_lock flag      = true    (unchanged)
branch_lock_effective = false
HOME locks · covers · authorizations · credentials · audit history — all preserved
```

The claim listener and per-request revalidation both return immediately: no login is
refused and **no open session is torn down**. Lease rows are untouched; a stale lease
whose session row is gone is reclaimed by the next claim.

**Half B** (if it is ever armed). Set enforcement off, **restore the exact captured
scope from §5**, rebuild the cache, re-verify with `android:phase4a-pilot-scope --json`.

**Incident path.** While Half A is off the HTTP force-logout surface 404s, so
recovery is SSH-capable only. `doctor:session-force-logout` works in either state and
is the canonical tool. Never rely on HTTP as the sole rollback control, and never fix
a lease with a manual `UPDATE`.

---

## 9. What this runbook will not do

- It will not arm Half B. That is blocked on hardware, a reviewed source change and
  attestations that are untrue today.
- It will not attest a prerequisite to make a gate green. Four of the five global
  prerequisites have nothing able to contradict them, which is exactly why signing
  one casually is unsafe.
- It will not write to production to make a document true.
