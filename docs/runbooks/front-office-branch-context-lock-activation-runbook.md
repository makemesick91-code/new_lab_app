# Front Office branch-context lock — activation runbook

Capability: `REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1`
Flag: `front_office.branch_context_lock` (`FEATURE_FRONT_OFFICE_BRANCH_CONTEXT_LOCK`)
Cohort key: `FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT`

This is a **supervised, per-account ceremony**. It is NOT part of a deploy.

---

## 0. Read this first — the shared-cohort rule

The branch-context lock and the branch-DEVICE lock read the **same cohort**. The
env key's name is historical: it predates the split.

```
BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT
DEVICE LOCK IMPLIES BRANCH PIN
```

| context flag | device flag | effect on an armed account |
|---|---|---|
| OFF | OFF | nothing |
| ON  | OFF | branch pinned, **no WebAuthn** ← this runbook |
| OFF | ON  | branch pinned **and WebAuthn demanded** |
| ON  | ON  | branch pinned **and WebAuthn demanded** |

> **Hazard.** An account armed here will ALSO be required to present a WebAuthn
> credential the moment `front_office.branch_device_lock` is switched on, because
> both layers read the same cohort. Never flip the device flag as a side effect
> of this ceremony. Confirm it is OFF before you start and after you finish.

## 1. The approved cohort — four accounts, no more

| Alias | user_id | branch code | branch id |
|---|---|---|---|
| Admin Landak | 30 | LDK2 | 2 |
| Admin Antang | 31 | ATG3 | 3 |
| Admin Telkomas | 32 | TLK1 | 1 |
| Admin Sunu | 29 | SPN4 | 5 |

Out of scope and untouched: **7, 8, 16, 17**. A fifth id exceeds the committed
`max_cohort_size` and fails **closed for every armed account** — it does not
widen the lock, it breaks it. Adding a fifth account needs explicit owner
approval and a new ceremony.

## 2. Order, and why

**Landak (30) → Antang (31) → Telkomas (32) → Sunu (29) LAST.**

Users 30, 31 and 32 have never selected an online context. User 29 is in live
daily use and held an online SPN4 context on 2026-09-21, so it carries the real
operational risk and goes last — when the mechanism is already proven on three
quieter desks.

## 3. Pre-flight (read-only)

```bash
php artisan tinker --execute="..."   # DO NOT. Never run tinker on production.
```

Use read-only SQL and the flag inspector instead:

```sql
-- the account is active, holds the role, and is the one you think it is
SELECT u.id, u.name, u.is_active FROM users u WHERE u.id = <ID>;

-- its branch is active and RME-enabled
SELECT id, code, name, is_active, is_rme_enabled FROM mst_branches WHERE code = '<CODE>';

-- any online context it already holds
SELECT user_id, branch_id, role_context, status FROM trx_user_online_contexts WHERE user_id = <ID>;

-- DAY-CONFLICT CHECK (see below) — today's committed branch, if any
SELECT user_id, clinical_date, current_branch_id
FROM trx_daily_branch_contexts
WHERE user_id = <ID> AND clinical_date = CURRENT_DATE;
```

Confirm the device flag is OFF before arming anything.

### The day-conflict check, and why it is not optional

The branch pin and `FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1` are two INDEPENDENT
narrowing rules, and the pin deliberately does **not** outrank the daily lock —
otherwise arming an account would hand it a same-day branch move that the daily
lock exists to require Super Admin approval for.

So if an operator has **already committed today to a branch OTHER than the one
you are about to pin them to**, arming them mid-day leaves them unable to select
anything: the selector offers only the pinned branch, and the daily lock refuses
it. That is a dead end until the clinical day rolls over.

Verified 2026-09-22: users 30, 31 and 32 hold no daily context at all, and user
29's context for today is already branch 5 (SPN4) — its pinned branch. So no
approved account is in conflict right now. Re-run the query anyway on the day you
arm; the answer changes daily.

If the query returns a DIFFERENT `current_branch_id`, do not arm that account
today. Arm it before its first selection on a later clinical day, or have the
operator complete a Super Admin branch-change request first.

## 4. Arm ONE account

Append exactly one `<user_id>:<BRANCH_CODE>` pair to the cohort, leave every
previously armed pair in place, then rebuild the config cache.

```
FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT=30:LDK2
FEATURE_FRONT_OFFICE_BRANCH_CONTEXT_LOCK=true
```

```bash
php artisan config:clear && php artisan config:cache
```

## 5. Verify before moving to the next account

1. The armed operator logs in. They are **not** sent to a WebAuthn ceremony.
2. The selector shows **Cabang terkunci: <branch>** and offers that branch only.
3. A crafted POST of any other branch id to `rme.online-context.admin-clinic`
   is refused with *"Akun Front Office ini terkunci pada cabangnya sendiri..."*.
4. Visit lists, the queue and any new operational record use the pinned branch.
5. **An account NOT yet armed still selects branches freely.** Check one of
   7/8/16/17 — this is the regression that matters most.

Stop and roll back if any step disagrees. Do not arm the next account.

## 6. Rollback

Remove the account's pair from the cohort, or set the flag to false, then
`config:clear && config:cache`. The account immediately resolves its branch
through the ordinary online-context tiers again. No session is invalidated and
no login is affected, because this flag never gated a login.

If `front_office.branch_device_lock` is ALSO on, branches stay pinned by that
layer — a full unpin needs both flags off.

## 7. What this capability does NOT do

- It does not require, enrol, or check any device or credential.
- It does not deny a login, ever.
- It does not touch the other four Front Office accounts, or any other role.
- It does not narrow global patient identity lookup, which stays global by
  design — branch scope applies to operational actions, not identity discovery.
- It does not remove legitimately authorized historical cross-branch reads.
