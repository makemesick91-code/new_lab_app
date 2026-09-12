# DOCTOR-ACCESS-PR-B-BRANCH-LOCK-COVER

**The second of three children** of DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1.
**Runtime authority this builds on:** PR-A merge `80afaad1`, deployed and production-verified.
**Rules:** `docs/architecture/doctor-branch-lock-and-cover.md` (DBL-R001..R025).
**Operations:** `docs/runbooks/doctor-branch-lock-operations.md`.
**Capability flag:** `doctor.branch_lock`, risk `critical`, committed **false**.

The parent GO tag is **not** created here. It is created after all three children are
merged, deployed and production-verified, plus the real-device ceremony.

---

## 1. What ships

One server-authoritative branch per doctor, derived on every request, changed only by an
approval that a second person signs off.

### Runtime

Four additive migrations. `mst_doctor_branch_locks` (UNIQUE `doctor_id`, and also the
serialisation point for cover approval), `trx_doctor_branch_lock_requests` (partial unique on
the pending state), `trx_doctor_branch_covers` (two partial unique indexes), and a fourth
that adds `effective_branch_id` and `effective_cover_id` to `trx_doctor_session_leases` with
**no backfill**.

`DoctorEffectiveBranchResolver` is the one answer to "which branch is this doctor in right
now": an approved cover that is current, else the home lock, else null. Two approval services
own the writes — `DoctorBranchLockApprovalService` for assignment and transfer,
`DoctorBranchCoverApprovalService` for cover — and nothing else writes those tables.

Two consumers, each asking the resolver exactly once: the list scope
(`RmeWorkingBranchScope::resolve()`) and the write chokepoint
(`ClinicVisitService::resolveBranchId()`). `EnsureDoctorSessionLease` records the effective
branch at claim time and compares it on every protected request, which is what makes cover
activation, cover expiry and an approved transfer one mechanism with no scheduler in the
correctness path.

### Three new permissions, four the surface reads

**This pull request adds three:** `view_doctor_branch_locks`,
`manage_doctor_branch_locks`, `approve_doctor_branch_locks`.

**The controller and both policies read four**, because the fourth —
`release_doctor_session_leases` — is PR-A's and appears in this diff only as an unchanged
context line. The distinction matters: PR-A's manifest already claims that permission, so
counting it here too made the two sibling manifests total five where the estate gained four.

Supervisor RME holds all four; Super Admin reaches them through the single global bypass. **No doctor holds any of them**, and a doctor can still file
their own home-branch request because the policy matches on `mst_doctors.user_id`.

### The surface

Twelve routes under `rme.doctor-branch-locks.*` and `rme.doctor-branch-covers.*`: file,
cancel, the approver queue, approve, reject, and the session release. Every route 404s while
the capability is off.

---

## 2. The first obligation, discharged

PR-A shipped `EnsureDoctorSessionLease` as globally appended `web` middleware and verified the
registration **by reading `bootstrap/app.php`**. That is not a regression guard, and PR-A
recorded it as a gap assigned here.

`DoctorSessionLeaseMiddlewareRegistrationTest` closes it with six cases that ask the ROUTER
for the resolved group. Three things about it are worth keeping:

- **The HTTP kernel must be resolved first.** In a console context the router's middleware
  groups are not populated until the kernel is built, so a bare `app('router')` call reports
  an empty `web` group and every assertion would pass vacuously. The first case asserts the
  group is non-empty precisely so that failure mode cannot hide the others.
- **`route:list` structurally cannot see this middleware.** `gatherMiddleware()` returns the
  raw action entries and a group's members are never expanded into them, so counting its
  output proves nothing in either direction. A case pins that constraint too: the middleware
  must never appear on any route, because route-attaching it would trip an existing
  device-middleware contract.
- **The source-level count is the load-bearing witness, and measurement proved it.** A double
  registration was injected into `bootstrap/app.php` and the resolved group **still** reported
  exactly one entry: Laravel de-duplicates a middleware group. Only the source check failed.
  So the runtime risk of an accidental second append is smaller than it looks, and a single
  witness here would have been another unproven guard.

---

## 3. Tests

**75 new cases. 124 passed / 2898 assertions across `tests/Feature/DoctorAccess`**, against a
base of 49.

Every figure below is measured per file, and the table sums to the total on purpose — because
the first two versions of this line did not. I wrote "64 new" and then "65 new" from a carried
estimate without ever summing the table, and an adversarial audit of my own documentation
caught it: 65 is not even a subset sum of the new files' counts.

Wide regression on this tree, every suite the changed files can reach:

| suite | result |
|---|---|
| `tests/Feature/DoctorAccess` | 124 passed |
| `tests/Feature/RME` | 1480 passed |
| `tests/Feature/Branch` | 140 passed |
| `tests/Feature/Deploy` | 131 passed |
| `tests/Feature/AccessControl` | 99 passed, 9 skipped |
| `tests/Feature/Auth` | 55 passed |
| `tests/Feature/Navigation` | 15 passed |
| `--filter=Sidebar\|OnlineContext\|BranchContext\|RolePermissionHardening` | 217 passed, 9 skipped |

`tests/Feature/Deploy` is in that list deliberately. PR-A's runbook shipped a forbidden
production REPL instruction that only `ProductionShellCommandGuardTest` catches, and the
regression that missed it had omitted this directory — so PR-A was reported green when CI
would have failed.

| file | cases | new | what it defends |
|---|---|---|---|
| `DoctorBranchApprovalTest` | 43 | 43 | the three workflows, maker-checker, cover semantics |
| `DoctorHomeBranchLockTest` | 17 | 17 | what a locked doctor sees and may write |
| `DoctorBranchLockGovernanceTest` | 8 | 8 | permissions, classification, the flag, the degraded case |
| `DoctorSessionLeaseMiddlewareRegistrationTest` | 6 | 6 | the PR-A gap above |
| `DoctorSingleSessionLeaseTest` | 18 | 1 | PR-A's, plus U2b (the UNSET budget) |
| `DoctorSessionForceLogoutTest` | 15 | 0 | PR-A's, unchanged |
| `DoctorSessionLeaseGovernanceTest` | 8 | 0 | PR-A's, with one pin inverted |
| `DoctorMultiDeviceAccessTest` | 9 | 0 | PR-A's, unchanged |
| **total** | **124** | **75** | base was 49 |

### Four cases that are ACCEPTANCES, and why that matters

A refusal test can only prove something is forbidden. These prove something ordinary is still
**allowed**, which is the failure mode a suite full of refusals cannot see: an over-strict
guard passes every refusal test in the file and takes the clinic down on a normal Tuesday.

- **Adjacency.** A cover starting exactly where the previous one ends is accepted — the whole
  content of `[starts_at, ends_at)`, and the most ordinary pair of covers an approver files.
- **Per doctor.** Two different doctors hold covers over the same instants for the same target
  branch.
- **After expiry.** A new cover is accepted once the previous period has passed, with the old
  row still present and still APPROVED.
- **Eviction, through HTTP.** Working before the approval, redirected to login on the very
  next request after it, then logging in again and landing on the new branch.

### The adjacency case was wrong when first written

Overlap is two comparisons, `starts_at < :ends AND ends_at > :starts`. Filed in chronological
order only one of them decides anything, so relaxing the other to `<=` left the case passing.
Measured, not reasoned about. The case now files a second pair in **reverse** order, which
puts the other comparison in the deciding position, and both relaxations are confirmed to fail
it.

### Mutation battery — 15 applied, 15 killed, 0 survived

Each applied by copy and reverted by copy, each verified to have actually changed the file
before its result was banked.

| mutation | killed by |
|---|---|
| a second `resolve()` per protected request | the query budget (delta 8 → 13) |
| cover overlap `starts_at '<'` → `'<='` | adjacency |
| cover overlap `ends_at '>'` → `'>='` | adjacency |
| overlap query drops `doctor_id` | two doctors, same hours |
| overlap query drops the period predicate | a new cover after expiry |
| middleware tolerates a revalidation denial | the evicted session |
| maker-checker invariant disarmed | five cases |
| operational scope replaces instead of intersects | the hybrid account |
| effective-branch write guard removed | three cases |
| transfer-through-active-cover guard neutralised | two cases |
| identity revoked as part of a branch change | the device trail |
| rejection reason made optional again | the reason case |
| lease partial index flattened | two lease cases |
| three exempt route names removed | a doctor filing their own request |
| the UNSET short-circuit removed | the UNSET budget test |

`MUTATIONS_APPLIED=15  MUTATIONS_KILLED=15  MUTATIONS_SURVIVED_ACTIONABLE=0`

---

## 4. Two corrections to PR-A's own claims

Both are inherited assertions that PR-B's arrival makes wrong. Neither was deleted.

### The query budget was an understatement

PR-A published a ceiling of 6 added queries against a measured 3. Correct for the lease engine
— and not a statement about production, because the shipped capability arms **two** flags and
that test re-armed only one after its disarm, so the resolver never ran inside the measurement
it published.

Re-measured with both armed: **8**. The enumeration is the schema probe, the doctor identity,
the active cover, the home lock, the branch-health read, the lease select and the drifting-
session update. The test now asserts three things instead of one — the lease-only leg at PR-A's
unchanged ceiling, the whole capability at a ceiling of 12, and the **increment** between them,
so a resolver regression cannot hide in the headroom. Every branch table is pinned to at most
one read per request, which is real rather than trivially true: the resolver does not memoise,
so a second consumer shows up immediately.

### An UNSET doctor was paying for an answer that could not change

The resolver read `mst_branches` before checking whether there was anything to check, and
`rmeEnabledIds()` is not cached. With no cover and no lock the verdict is UNSET whatever that
table says, so the read was one wasted query per protected request — paid by the **entire
fleet**, because every doctor is UNSET the day this arms, and by nobody who benefits.
Short-circuited, and pinned by its own test at zero added branch reads against the locked
case's at-most-one.

The first version of that pin was wrong in a way worth recording: it was a second leg of the
locked test, and two logins in one test are one browser re-authenticating, so no second lease
was claimed and it failed on its own precondition. The suite's own trap list says exactly
that. The absolute claim that replaced it needs no second session to be true.

### The flag-absence pin is inverted, not removed

PR-A pinned `doctor.branch_lock` as absent from the registry, on the rule that a flag
registered ahead of its runtime promises something nothing implements. This pull request ships
that runtime. What stays load-bearing is that the two are **separate keys**: one switch serving
both capabilities would make DBL-R002 unexpressible.

---

## 5. A defect found only because the migration was run both ways

Adding a foreign-key column makes SQLite rebuild `trx_doctor_session_leases`, and Laravel
re-creates that table's indexes from introspection **without the predicate**. PR-A's
`UNIQUE(user_id) WHERE released_at IS NULL` was flattened to a plain unique on `user_id`, which
would make every doctor's second login after any logout raise a unique violation — **on SQLite
only, invisible to a green PostgreSQL gate**.

The migration now re-asserts the index with its predicate in both `up()` and `down()`, verified
across a full round trip: predicate intact after adding the columns, after rolling back, and
after re-migrating; a released plus an unreleased lease for one doctor both accepted; a second
unreleased one refused.

One correction to my own account of this, recorded because it was reported before it was
checked: I initially reported that the rollback failed and left the schema damaged. That was
caused by probe rows inserted with foreign keys disabled. On a clean schema the rollback
succeeds, drops both columns and preserves the predicate.

---

## 6. HONEST LIMITS

### Concurrency is proven on PostgreSQL only

`lockForUpdate()` compiles to an empty string on SQLite, so the local suite proves the ordering
logic and only the PostgreSQL critical gate proves the serialisation. The overlap invariant
cannot be expressed as an index on either engine — measured by attempting the write: identical
periods were rejected, merely overlapping ones were accepted.

### The pilot cohort is not asserted in a test, deliberately

Owner requirement "existing pilots [9,15,18] regression" is a **production verification** item,
not a unit test. Those ids are host values; pinning them in a test would assert somebody's
environment rather than this diff, and would fail on any checkout configured like production.
What is asserted is that this pull request **wrote no cohort**, and the cohort is verified on
the deployed host during the ceremony.

### What only a real-device ceremony can prove

That an approver reading the queue on a real screen understands what approving will do to a
doctor who is online. That the degraded-lock row is legible to whoever has to act on it. That a
doctor evicted by an approval sees a sentence they can act on rather than a blank login form.

### Deliberately not delivered

The bulk device-authorization tool (PR-C). Any change to global doctor WebAuthn enforcement,
which stays false. Any backfill. Any narrowing of per-record reads or the legacy archive.

### Full Suite

`FULL_SUITE_EXECUTED=NO`. `FULL_SUITE_RESULT=SKIPPED`. `FULL_SUITE_CLAIMED_PASS=NO`.

One Full Suite runs after all three children are merged, deployed and production-verified, on
the final immutable tree, and it gates the parent tag alone. **This child skip must never be
read as a parent pass.**

---

## 7. Handover to PR-C

- **Migration slots.** PR-A holds `2026_09_10_100001`; PR-B holds `100002`–`100005`. PR-C adds
  no table.
- **What PR-C adds:** `DoctorDeviceBulkAuthorizeCommand`,
  `DoctorDeviceBulkAuthorizationService` and `DoctorDeviceBulkAuthorizationPlan`, plus the four
  governance cases for them that were deliberately left out of
  `DoctorBranchLockGovernanceTest`.
- **What PR-C must not do:** create a WebAuthn credential, mutate a branch lock, revoke an
  existing authorization as part of synchronisation, touch a feature flag, or widen the pilot
  scope. Dry-run is the default and the apply step stops for an explicit operator approval
  showing the exact row delta.
- **The source-file scan already covers it.** `DoctorSessionLeaseGovernanceTest`'s file
  enumeration walks the whole `Modules/DoctorAccess` tree recursively, so PR-C's files are
  inside the enforcement-flag scan and the sprint-boundary pin the moment they are added. PR-C
  adds the command path to that list.

---

## 8. Production evidence, 2026-09-11

Recorded after the deploy, not inferred from it. Every artisan command ran as
`daengtisiams`, the real php-fpm pool user — never as root, because root-owned cache files
are what broke production login once before.

### Merge and deploy identity

| | |
|---|---|
| candidate | `e4b0850c` tree `99ed382d` |
| merge | `1010d9fb` tree `99ed382d` (squash changed no content) |
| merge parent | `0e16c920`, the previous base tip |
| production head | `1010d9fb` tree `99ed382d` |
| deploy | `scripts/deploy-vps-runner.sh start` ON `srv1730088`, exit 0, `DEPLOY OK` |

The deploy also carried PR-A's evidence commit `0e16c920`, which was on the base and not
deployed with PR-A. That is this repository's established pattern.

### The schema, read from production PostgreSQL

All three new tables present. Both lease columns present and nullable. And the verification
that mattered most, because PR-B's fourth migration drops and recreates it:

```
CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq
  ON public.trx_doctor_session_leases USING btree (user_id)
  WHERE (released_at IS NULL)
```

**The predicate survived.** Had it not, every doctor's second login after any logout would
raise a unique violation. All four partial unique indexes are present with their predicates:
the pending-cover one, the identical-approved-period one, the pending-request one, and the
plain one-lock-per-doctor unique.

### Inert, which is the point of this deploy

```
locks=0  requests=0  covers=0  leases_with_effective_branch=0
doctor-access rows written today = 0
doctor-access audit rows written today = 0
doctor.branch_lock            enabled=false default=false risk=critical
doctor.single_active_session  enabled=false default=false risk=critical
```

`UNEXPECTED_INITIAL_BRANCH_ASSIGNMENTS=0`. No doctor was assigned a branch, and nothing
inferred one.

### Identity untouched, proven by timestamp rather than by count

Devices 5, authorizations 5, credentials 5 — unchanged across the deploy. Stronger than equal
counts: **every revocation timestamp predates the deploy.** The one revoked authorization was
revoked 2026-09-07; the two revoked credentials on 2026-09-08 and 2026-09-09. Nothing was
revoked on 2026-09-11.

### The pilot cohort

`9,15,18` unchanged, `GLOBAL_ENFORCEMENT_ACTIVE=false`, `SCOPE_VERDICT=GO`, 3 browser-denied
and 12 browser-allowed. All three doctors keep their online contexts, untouched since
2026-09-09, and none holds a branch lock.

### Health and the error delta

`/login`, `/health/live`, `/health/ready` all **200** over the canonical entry point. The three
PR-B routes 302 as a guest, never 500.

**The production log did not grow by a single byte across the entire deploy** — anchored on a
byte offset rather than on content, so it cannot be certified by injected text.
`ERROR_COUNT_DELTA=0`, and no new dated log file was created.

The deploy's own smoke reported `WATCH` on one check: `SMOKE-HTTP-HEALTH` probing
`http://127.0.0.1/login` returned 404. Diagnosed rather than waved through — the same probe
with the canonical `Host` header returns 301 and follows to 200, and the `default_server` vhost
is this application's. The 404 is the probe's missing Host header meeting an HTTPS-redirecting
vhost, not an unhealthy app. PR-A's deploy produced the identical WATCH.

### Section 17 — rules synced

A six-lens contradiction audit over the merged tree, each claimed contradiction then put to
three independent adversarial refuters. Ten claims raised, six refuted as absences or layer
confusions, **four survived and all four were real defects in my own work**:

1. **The test count was wrong twice.** I wrote "64 new" then "65 new" from a carried estimate
   without ever summing the table. The truth is **75**, measured per file against a base of 49.
   One refuter enumerated every subset sum of the new files' counts and showed 65 is not among
   them.
2. **The manifest over-claimed the permissions.** PR-B adds **three**; the fourth is PR-A's and
   appears here only as a context line. Counting it made the two sibling manifests total five
   where the estate gained four — exactly the attribution error a three-child split exists to
   prevent.
3. **The runbook named the wrong failure symptom.** I wrote that arming the branch-lock flag
   alone leaves "the screens appear" — in fact `assertCapabilityArmed()` is the first statement
   of all twelve controller actions and aborts **404**, so the screens are simply not there.
   An operator diagnosing that state would have been misled.
4. **PR-B made two of PR-A's shipped rules false, and I had not updated them.** LEASE-R013
   said "eviction releases no lease row" and justified it by enumerating the only two reasons
   that could reach `evict()`; PR-B added a third that DOES release it. LEASE-R011 said
   revalidation answers "two conditions only, do not invent a third"; PR-B answers three. Both
   are amended in place, with their cursor mirror, and so is the now-crossed "PR-B seam"
   section that still described this capability as future.

`RULES_SYNCED=YES`, `CONTRADICTORY_RULES=0` after the four fixes.

### What is NOT verified here

The operator ceremony. Steps A–P of the authorization need a human at a real clinic tablet and
cannot be simulated. `docs/operations/doctor-branch-lock-production-ceremony.md` is the script.
Until it runs, `LOCKED_BRANCH_VERIFIED`, `TEMP_COVER_VERIFIED`,
`PERMANENT_TRANSFER_VERIFIED` and `SESSION_INVALIDATION_VERIFIED` are **PENDING OPERATOR**, and
PR-B is not production-verified.

**A direct runtime enumeration of the lease middleware on production is not available.**
`route:list` structurally cannot see a group-appended middleware and a REPL is forbidden, so
the four registration facts rest on three things: the deployed `bootstrap/app.php` bytes
(exactly one `::class`, first in the append list, no route alias), tree identity with the
candidate CI ran the six-case registration test against, and the fact that a console-context
group read demonstrably works on this host. A read-only artisan command that reports the
resolved group would make this directly observable and is worth a follow-up.

`FULL_SUITE_EXECUTED=NO`. `FULL_SUITE_RESULT=SKIPPED`. `FULL_SUITE_CLAIMED_PASS=NO`.

---

## 9. A deploy step that had never run — found after the deploy was called OK

**The four permissions were absent from the production database, and PR-A's was absent too.**
Discovered while pre-flighting the operator ceremony, hours after both deploys were recorded
green.

`scripts/deploy-vps.sh` runs migrations but **not seeders** — by design, because seeding is a
named post-deploy step in every sprint's deploy note. PR-B's note says it. PR-A's note says it.
Neither had been executed. The newest permission in the table was `id 161` from an earlier
sprint; `release_doctor_session_leases` (PR-A, deployed that morning) and PR-B's three were
simply not there.

**What that would have cost.** Every PR-B route is gated on
`permission:view_doctor_branch_locks|approve_…|manage_…`. With the permissions absent, the
Spatie route middleware refuses **Supervisor RME** outright; only Super Admin passes, through
the single global `Gate::before`. Maker-checker needs two distinct accounts, so the ceremony
would have been **impossible** — and the operator would have found out mid-window, with a 403
that looks like a bug in the feature.

**Why the test suite did not catch it, which is the part worth keeping.**
`DoctorBranchLockGovernanceTest` has a case for exactly this — ruling C6, "no surface may be
gated on a permission nobody seeded". It asserts the permission is in
`PermissionSeeder::PERMISSIONS` **and** present in the database after calling the seeder. Both
assertions are true and always were. The gap is that the test proves the seeder **contains** the
permission; nothing proves the seeder **ran on production**. A green C6 test and a broken
production are perfectly compatible.

**The repair, with its blast radius computed before it was applied.** `RoleSeeder` uses
`syncPermissions()`, which resets each managed role to the seeder's list, so running it blind on
a live clinical system could revoke manual grant drift. So the delta was computed first — the
seeder's expected grants by reflection against a read-only dump of production's 444 existing
grants:

```
roles the seeder manages            17
roles NOT managed (left untouched)  Front Office, Tester RME
WOULD-GRANT                          8   (4 permissions x Super Admin + Supervisor RME)
WOULD-REVOKE                         0
```

Purely additive. Then, in order: a fresh canonical backup
(`auto_backup_20260911-154650.sql`), `PermissionSeeder`, `RoleSeeder`, `permission:cache-reset`
— every command as `daengtisiams`.

**The result matched the prediction exactly.** Permissions `162..165` created; both approver
roles hold all four; grants 444 → 452, exactly +8; `Front Office` still 5 and `Tester RME`
still 6, nothing revoked; no Doctor-role grant. The log did not grow by a byte, both flags are
still off, all four branch tables are still empty, and `/login`, `/health/live` and
`/health/ready` are still 200.

**The capability is still inert.** `assertCapabilityArmed()` 404s every action while the flags
are off, so nothing became reachable. What changed is that when the operator does arm it,
Supervisor RME can act — which was not true before.

One correction to my own analysis along the way: a first `comm`-based diff reported that
`Front Office` and `Tester RME` would be stripped. That was wrong. Those roles are absent from
`ROLE_PERMISSIONS`, so `syncPermissions()` never runs for them and their grants are untouched.
An exact per-role diff replaced the flawed one before anything was written.

---

## 10. Production ceremony, 2026-09-12 — foreign-tablet proof PASS, flags LEFT ARMED

Window 05:30–07:00 WITA, operator present throughout. Every step below is production evidence,
captured against anchors taken before the operator touched anything.

### FOREIGN_TABLET_PROOF = PASS (owner-accepted)

```
DEVICE_PHYSICAL_BRANCH      ATG3   device 6 PILOT_TABLET_05_ATG3
HOME_LOCKED_BRANCH          SPN4
EFFECTIVE_CLINICAL_BRANCH   SPN4   working context SPN4 / Ruangan A
ATG3 != SPN4                DEVICE_BRANCH_IS_BRANCH_AUTHORITY = NO
lease 5  ACTIVE  effective_branch_id = 5 (SPN4)  — the lease recorded the LOCK, not the tablet
```

The strongest form of the proof arrived by accident: earlier the same night, **while still UNSET,
she freely chose ATG3 — the tablet's own branch — and took a room there.** After the lock she
could only be at SPN4 from that same tablet. Before and after on one device.

### The authorization prerequisite, and the gate refusing first

The owner chose to authorize Karmila on the ATG3 tablet. The audit shows the per-pair boundary
actually refusing before approval, which is better evidence than a first-try success:

```
664 AUTHORIZATION_PENDING       auth 6  by 18   22:12:14
666 APP_LOGIN_AUTHORIZATION_REJECTED  auth 6  by 18  22:12:14   ← denied, no authorization yet
667 AUTHORIZATION_APPROVED      auth 6  by  1   22:12:30        ← canonical approval
669 APP_LOGIN_AUTHORIZATION_SUCCESS   auth 6  by 18  22:12:42   ← then permitted
```

`EXPECTED_NEW_AUTHORIZATIONS=1`, `EXPECTED_NEW_CREDENTIALS=0` — she reused device 6's existing
device-bound credential, because a credential proves the DEVICE and not the clinician.

### The permanent assignment

```
request 1  doctor 21  initial_assignment  UNSET -> SPN4  approved
           maker=1 (Super Admin)  checker=11 (Supervisor RME)   maker != checker
           requested 22:44:16   applied 22:49:44
lock       doctor 21 -> SPN4, established_via=initial_assignment, by user 11
```

**`markOffline()` observed, not argued.** Her context flipped to `offline` with the room
**VACATED** at exactly 22:49:44, the approval instant. Ruangan A was genuinely occupied first,
which is why the side effect was visible at all.

### PR-A's core rule, witnessed on production for the first time

```
677 DOCTOR_SESSION_LEASE_DENIED  users 18  22:45:31
```

She held lease 2, attempted a second login, and was **DENIED**. Refused, not evicted — the first
session kept working until she logged out of it herself.

### WHAT WAS NOT CAPTURED, and will not be recorded as a pass

**`OLD_SESSION_NEXT_PROTECTED_REQUEST=DENIED` is MISSING.** There is no lease release at
22:49:44. By the time the approval landed she had no active lease: lease 2 had been released by
her own logout at 22:45:48, and leases 3 and 4 were claimed then device-invalidated at 22:46 and
22:47. The approval therefore had nothing to invalidate. She was staged with a live lease at
06:24 and it was gone by 06:49.

This is the vacuity trap this sprint has been fighting all along, and it caught the ceremony.
Recoverable at the **cover approval**, which releases a lease the same way — provided the subject
is leased and online at that moment.

**Also not run:** temporary cover, cover expiry, the stale-cover session check, the non-empty
operational-list proof, the archive cross-branch read, and the optional permanent transfer.

### A REQUIREMENTS DISAGREEMENT, not a defect

The operator, on seeing the PASS, said a doctor should only be able to log in on a tablet
matching their branch. That is the opposite of the authorised design, in three places: the
founding owner decision ("a doctor may authenticate from ANY approved trusted clinic tablet
regardless of which branch owns it"), DBL-R021, and the ceremony script's own §23/§24 where an
effective branch of ATG3 would have been the FAIL.

No code was changed. The owner accepted the result as PASS and the tablet-matching expectation is
recorded here as a **separate future decision**, not a bug. Worth noting it is a defensible
stronger posture — a misplaced tablet could then reach nothing — traded against clinical
flexibility, and a naive implementation would lock out a doctor working under a cover.

### FLAGS LEFT ARMED BY OWNER DECISION

The owner's own script said `CEREMONY_LEASE_FLAG_FINAL_STATE=false` and conditioned arming on the
rollback operator remaining present for the entire armed interval. At the end of the window the
owner instructed: **do not disarm.** Recorded truthfully rather than to the template:

```
CEREMONY_LEASE_FLAG_ARMED=YES
CEREMONY_LEASE_FLAG_SCOPE=ALL_DOCTOR_ROLE_ACCOUNTS
CEREMONY_LEASE_FLAG_FINAL_STATE=true        <-- LEFT ARMED, contrary to the earlier instruction
BRANCH_LOCK_FLAG_FINAL_STATE=true
GLOBAL_ENFORCEMENT_ACTIVE=false             <-- untouched
```

What that means in production, stated plainly: one session per doctor is now enforced for **all
15 Doctor-role accounts**, and a second concurrent login is denied — as audit 677 demonstrates.
**drg Karmila is now a live branch-narrowed clinician**: her lists, her writes and her branch
selection resolve to SPN4 only. The other 14 doctors remain UNSET and therefore unnarrowed.

Rollback stays one line each, and both keys were absent beforehand so removal restores the prior
state exactly: drop `FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION` / `FEATURE_DOCTOR_BRANCH_LOCK`,
`config:cache` as `daengtisiams`, reload php8.3-fpm.

### Closing state

```
identity   devices=5  authz=6  creds=5  revocations_today=0
log        1406217 bytes, 152 errors — BYTE-IDENTICAL to the pre-deploy anchor across the whole ceremony
health     /login=200
Karmila    online at SPN4, Ruangan A — left operationally usable
```

`PR_B_STATUS = MERGED / DEPLOYED / FOREIGN-TABLET PROOF PASS / CEREMONY INCOMPLETE`
`PR_C_SAFE_TO_START = NO`

---

## 11. Safe disarm, 2026-09-12 08:37 WITA — single-session OFF, branch lock KEPT

Owner decision after the window: disarm `doctor.single_active_session`, keep
`doctor.branch_lock` armed. Executed in the prescribed order.

**Pre-disarm capture, 08:36:27.** Zero pending rows of any kind
(`PENDING_INITIAL_ASSIGNMENTS=0 PENDING_TRANSFERS=0 PENDING_COVERS=0`), so nothing could be
stranded behind a disabled surface and the branch workflow never had to be touched.

The env key was set explicitly to `false` rather than removed, so the file records a deliberate
disarm rather than an absence. `doctor.branch_lock=true` was not touched. Config cache rebuilt as
`daengtisiams` (`config.php` owner verified), php-fpm reloaded.

### Post-disarm hard gates — all pass, read from effective runtime

```
DOCTOR_SINGLE_ACTIVE_SESSION_CONFIG=false   DOCTOR_SINGLE_ACTIVE_SESSION_EFFECTIVE=false
DOCTOR_BRANCH_LOCK_CONFIG=true              DOCTOR_BRANCH_LOCK_EFFECTIVE=true
GLOBAL_ENFORCEMENT_ACTIVE=false             PILOT_COHORT=[9,15,18]  SCOPE_VERDICT=GO
KARMILA_HOME_LOCKED_BRANCH=SPN4             context SPN4, room 17, online, 1 session
LOCKS_TOTAL=1  DOCTORS_UNSET=14             LOCKS_CREATED_SINCE_DISARM=0
devices=5  authz=6  creds=5                 revocations_today=0
LOG_BYTES_NOW=1406217  BYTES_SINCE_ANCHOR=0  ERRORS=152
NEW_SINGLE_SESSION_ERRORS=0   NEW_BRANCH_LOCK_ERRORS=0
/login=200  /health/ready=200
```

Karmila was **not** logged out by the disarm, her lock is untouched at SPN4, and the other 14
doctors remain UNSET — so branch-lock arming alone narrows nobody, which is the point.

### ONE THING THE NEXT WINDOW MUST KNOW, or it will be wasted

**Lease 5 is still UNRELEASED.** That is PR-A's documented rollback behaviour — existing rows stay
as they are and are harmless while nothing claims one — not a leak. But it has a sharp consequence
for step 4 of the next ceremony sequence ("fresh-login Karmila so a new active lease definitely
exists"):

> The moment `doctor.single_active_session` is re-armed, lease 5 becomes a live incumbent again,
> because `IncumbentSessionProbe` will find Karmila's surviving `sessions` row and read it as
> alive. A "fresh login" attempted at that point is a SECOND login and will be **DENIED** —
> correctly, and confusingly.

So the next window must run in this order: **re-arm, then have her LOG OUT** (which releases lease
5 with reason `logout`), **then log in** — and only that login produces the fresh lease the cover
approval needs to invalidate. Logging in before logging out will produce a denial that looks like a
defect and is not one.

### Remaining PR-B gates, unchanged

`SESSION_INVALIDATION_VERIFIED` · `TEMP_COVER_VERIFIED` · `COVER_EXPIRY_VERIFIED` ·
`STALE_COVER_SESSION_DENIED` · `RUANG_PERAWATAN_NARROWING` · `ARCHIVE_CROSS_BRANCH_READ`

```
PR_B_STATUS = MERGED / DEPLOYED / FOREIGN-TABLET PROOF PASS / CEREMONY INCOMPLETE
PR_C_SAFE_TO_START = NO
```

---

## 12. PR-B PRODUCTION CEREMONY CLOSED — all six remaining gates PASS, 2026-09-12

Window 10:04–11:32 WITA, operator present, drg Karmila (user 18 / doctor 21) on
`PILOT_TABLET_05_ATG3` (device 6, authorization 6), a tablet whose physical branch is **ATG3**
while her permanent home lock is **SPN4**. Production head `1010d9fb`, exact match, throughout.

**Every claim below is server-side evidence.** Where the operator reported "all step done" without
answering the specific question asked, the outcome was taken from the database and from the nginx
access log instead. Two such reports turned out to be partly untrue (a room list never reported, a
self-approval attempt not yet made at that point), which is why nothing here rests on them.

### The six gates

| gate | verdict | the evidence, not the assertion |
|---|---|---|
| SESSION_INVALIDATION_VERIFIED | **PASS** | 11:06:21 `GET /rme/medical-records` → 302, referer the odontogram page she was reading; audit 699 `DOCTOR_SESSION_LEASE_EVICTED` on `users:18`, `{"reason":"lease_missing"}` |
| TEMP_COVER_VERIFIED | **PASS** | lease 7 claimed 11:07:18 with `effective_branch_id=3`, `effective_cover_id=1`; home lock still SPN4 |
| COVER_EXPIRY_VERIFIED | **PASS** | at 11:29:07, 247s past `ends_at`, the cover row still `approved` with `updated_at == decided_at` — **nothing rewrote it** — yet effective covers = 0 and effective branch = SPN4 |
| STALE_COVER_SESSION_DENIED | **PASS** | 11:26:36 → 302 then `/login` 200; audit 703 `DOCTOR_SESSION_LEASE_EVICTED` on `trx_doctor_session_leases:7`, `{"reason":"branch_context_changed"}` |
| RUANG_PERAWATAN_NARROWING | **PASS** | home: selector offered only Cabang Sunu, server granted room 17 SPN-A. cover: only Cabang Antang, server granted room 12 ATG-A |
| ARCHIVE_CROSS_BRANCH_READ | **PASS** | from an SPN4 session: visit 23 (LDK2) → 302 → visit 9 (ATG3) 200, `handwritings/9/image` 200 twice, `visits/23/odontogram` 200, zero denial audits |

### The round trip, on one tablet that belongs to ATG3

```
lease 5 | 06:51:47 WITA | SPN4 | cover -  | released admin_release            (operator, user 11)
lease 6 | 10:38:03 WITA | SPN4 | cover -  | released effective_branch_changed (cover approval)
lease 7 | 11:07:18 WITA | ATG3 | cover 1  | released effective_branch_changed (cover expiry)
lease 8 | 11:30:19 WITA | SPN4 | cover -  | ACTIVE
```

SPN4 → ATG3 → SPN4, and `mst_doctor_branch_locks.home_branch_id` never moved off 5.

### Two different eviction reasons, which is what proves two different mechanisms

The invalidation gate and the stale-cover gate are easy to conflate, and a single reason code
appearing twice would not have distinguished them. They produced different codes AND different
audit shapes, each matching its documented path:

- **approval** had already released the lease, so the next request found none: reason
  `lease_missing`, audit keyed on `users:18`, because a missing lease has nothing to write to;
- **expiry** left the lease in place and made its recorded `(branch, cover)` unreconcilable with
  the resolver's answer: reason `branch_context_changed`, audit keyed on the lease row itself,
  which is also released with `effective_branch_changed`.

### Maker-checker, observed rather than asserted

`approve_doctor_branch_locks` is held by both tiers, so the only thing separating maker from
checker is an actor-id comparison inside the locked transaction, which a Super Admin's global gate
bypass cannot reach. Observed on production:

```
03:03:06 UTC  POST /rme/doctor-branch-covers/1/approve  -> refused  (cover still undecided)
03:03:26 UTC  POST /logout
03:03:32 UTC  POST /login                                          (different account)
03:03:47 UTC  POST /rme/doctor-branch-covers/1/approve  -> approved by user 11
```

Maker user 1, checker user 11, `requester_user_id <> decided_by_user_id`. The refusal wrote
nothing. Audit 698's payload also records `online_impact_acknowledged: true` and
`doctor_online_at_decision: true`: she was online, and the approval carried the acknowledgement the
subject guard re-reads inside the transaction.

### Three corrections this ceremony forced

1. **The cover form takes WITA, not UTC.** Storage is UTC, but the input string is parsed in the
   clinical timezone. Proven by the row itself: typed 10:25/11:25 clinic time, stored 02:25/03:25.
   An instruction given in UTC would have dated the window eight hours away and the cover would
   never have come into force.
2. **Ruang Perawatan is NOT the room-set proof surface for a doctor.** On
   `/rme/treatment-room-worklist` the room selector is not rendered at all for a room-scoped
   doctor, and the room list that page builds is scoped to *every* RME-enabled branch rather than
   to the effective branch. The narrowed, doctor-visible surface is the **working-context
   selector**, which intersects her practice branches with the effective branch and is re-asserted
   server-side. §4's corrected surface contract is right that the evidence is the room set; it is
   the page that was wrong.
3. **"16 rooms" was a row count including inactive rooms.** Active rooms: SPN4 two, ATG3 two,
   eleven across the four RME branches. Because home and cover both have exactly two and all four
   are named *Ruangan A* / *Ruangan B*, **a count or a name proves nothing** — the evidence has to
   be the room codes SPN-A/SPN-B against ATG-A/ATG-B, or the granted room id.

### Anti-vacuity preconditions asserted before each gate was scored

Every gate in this sprint has a way to pass for the wrong reason, and each was closed first:

- the post-arm lease was proven claimed **after** the flag was armed, so §24 could not pass on a
  token-less pre-arm session;
- lease 7 was proven claimed **strictly inside** the cover window and to carry both the cover id
  and the target branch, so §30 could not pass on a session that never held cover authority;
- SPN4 and ATG3 were both confirmed `is_active` and `is_rme_enabled` before and after, because a
  degraded branch makes the resolver decline to answer and a declined answer never evicts — which
  would have looked like a clean 200;
- ATG3 was confirmed present in `mst_doctor_branches` for doctor 21 **before** the cover was filed;
  had it not been, the approval would have left her unable to go online anywhere;
- she holds four active RME practice branches, so narrowing to one is a real exclusion rather than
  an artefact of having only one.

### Final state

```
DOCTOR_SINGLE_ACTIVE_SESSION_FINAL = false   (config AND effective, via env)
DOCTOR_BRANCH_LOCK_FINAL           = true
GLOBAL_ENFORCEMENT_ACTIVE          = false
PILOT_COHORT                       = 9,15,18   SCOPE_VERDICT=GO
KARMILA_HOME_LOCKED_BRANCH         = SPN4      final context SPN4 / SPN-A / online
OTHER_DOCTORS_LOCK_STATE           = UNSET     28 of 29 doctor records, 14 of 15 Doctor accounts
devices / authorizations / credentials = 5 / 6 / 5, authorization 6 active, 0 revocations
PENDING initial assignments / transfers / covers = 0 / 0 / 0
/login /health/live /health/ready /health/lb = 200
APP_ENV pilot, APP_DEBUG false, maintenance OFF, migrations pending 0, failed jobs 0
audit 690 -> 706, sixteen rows, every one attributable
production log BYTE-IDENTICAL at 1406217 with 152 errors across the entire ceremony
```

**Zero new errors, zero unexpected identity mutations, zero stranded rows.**

### Lease 8 is unreleased and inert — and the re-arm trap applies again

Same as the previous window: with the flag off nothing claims or checks a lease, so lease 8 simply
sits there. It is PR-A's documented rollback behaviour, not a leak. But she is **online right now**
with a live session, so if `doctor.single_active_session` is ever re-armed, lease 8 becomes a live
incumbent and her next login is a SECOND login and will be correctly DENIED. Any future re-arm must
be followed by a logout, or by the audited release, **before** a fresh login.

### The production tree object, stated properly

Owner correction, 2026-09-12: an earlier handoff summary of mine put a BRANCH NAME in the
`PRODUCTION_TREE` field. A ref name is not a tree object. **The repository record above was already
correct** — section 8 has carried `tree 99ed382d` since the merge — so the defect was in my
reporting line, not in the evidence. Resolved again, deliberately by three independent routes and
from both authorities:

```
PRODUCTION_HEAD  = 1010d9fb14f5ee4563bd27db423631ebe655a5dc   type commit
PRODUCTION_TREE  = 99ed382d281045fb870df15190198c107528385d   type tree

on production (srv1730088)          git rev-parse HEAD^{tree}        -> 99ed382d2810...
on production, from the literal SHA git rev-parse <sha>^{tree}       -> 99ed382d2810...
on production, commit object header git cat-file commit HEAD | head  -> tree 99ed382d2810...
in the repository, from the SHA     git rev-parse <sha>^{tree}       -> 99ed382d2810...
object type verified                git cat-file -t <tree sha>       -> tree

PRODUCTION_HEAD_MATCH     = YES
PRODUCTION_TREE_VALID_SHA = YES
production tracked dirty files = 0
```

### Permanent operational warning — lease 8 and any future re-arm

Lease 8 is **unreleased but inert** while `doctor.single_active_session` is false, and drg Karmila
is online at SPN4 in room 17. Her session is deliberately **not** being force-logged-out, and
lease 8 is deliberately **not** being released, because neither would serve a clinical purpose and
housekeeping is not a reason to end a clinician's session.

The consequence, which must be carried into any future window:

> If `doctor.single_active_session` is re-armed while that session still survives, lease 8 becomes
> an incumbent lease again. Therefore before any subsequent fresh Karmila login after a re-arm,
> **either** (A) a normal logout followed by verifying the lease retired, **or** (B) the canonical
> audited release or force logout, must occur first. Otherwise the new login is correctly treated
> as a second concurrent login and is denied.

That denial would be PR-A behaving exactly as specified. It is not a leak and not a defect.

```
PR_B_STATUS = MERGED / DEPLOYED / PRODUCTION VERIFIED / CLEAN
PR_C_SAFE_TO_START = YES  (owner-granted on cleanup completion; PR-C still must not start
                           automatically and requires an explicit owner instruction)
FULL_SUITE_CHILD_RESULT = SKIPPED
PARENT_FULL_SUITE_OBLIGATION = OPEN
PARENT_GO_TAGGED = NO
```

### Cleanup, verified 2026-09-12 11:46 WITA

```
PR_B_WORKTREE_REMOVED = YES   33 registered worktrees -> 32, directory absent, nothing to prune
PR_B_SCRATCH_REMOVED  = YES
LEFTOVER_PR_B_PROCESSES = 0
LEFTOVER_DEBUG_FILES    = 0
LEFTOVER_SECRET_FILES   = 0
SPRINT_CREATED_SECRET_BACKUPS_LEFT = 0
UNRELATED_CHECKOUT_PRESERVED = YES
  branch ci-evidence/cicd-ctrl-3-db-guard-matrix, HEAD b18188c2, 0 staged,
  same two dirty paths with identical sha256 and byte size, shared stash untouched at 1 entry
branch pr-b-evidence preserved, local == origin == 49dcca1a
```

The worktree's local artifacts were inspected before deletion rather than assumed disposable:
`.env` was `APP_ENV=local` on a sqlite file inside the worktree with only a locally generated
`APP_KEY` and no reference to production; `local.sqlite` held 148 tables and **zero** rows in
patients, visits, medical records and doctors; the two clinical directories held fourteen files
totalling about 1 KB, every one a 10x10 pixel test PNG. No real clinical data and no production
credential was destroyed. One further leftover, a 674 KB PR-B source diff sitting world-readable in
the system temp directory since 2026-09-11, was found by the sweep and removed; it contained no
secret and no identifier and is reproducible from git.

Production was re-verified after cleanup and had not moved: both flags as required, home lock SPN4,
Karmila online at SPN4 room SPN-A, identity 5/6/5 with authorization 6 active and zero revocations,
audit still at 706, and the log still byte-identical at 1406217 bytes with 152 errors. **No cleanup
operation mutated production state.**

---

## 13. Primary raw evidence, preserved in the repository

Captured read-only from production so that the ceremony's scratch files hold nothing unique and can
be destroyed without losing evidence. Client addresses are masked to the /24; the distinction that
matters is only **which actor** made the request.

### Audit trail, rows 690 to 706, with payloads

```
690 | 00:10:59Z | DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED | trx_doctor_device_login_tickets:13 | by=18 | {"reason": "active_session_elsewhere"}
691 | 02:35:01Z | DOCTOR_SESSION_LEASE_REVOKED | trx_doctor_session_leases:5 | by=11 | {"reason": "admin_release", "outcome": "revoked", "user_id": 18}
692 | 02:35:01Z | DOCTOR_SESSION_RELEASED_BY_APPROVER | mst_doctors:21 | by=11 | {"reason": "Akhiri sesi pra-arming untuk gate ceremony PR-B", "user_id": 18, "doctor_id": 21, "devices_touched": false, "session_released": true, "doctor_online_at_decision": false, "webauthn_credentials_touched": false}
693 | 02:37:53Z | DOCTOR_DEVICE_LOGIN_REQUESTED | mst_doctor_device_authorizations:6 | by=18 | {"status": "active", "doctor_id": 21, "device_status": "active", "doctor_device_id": 6}
694 | 02:38:03Z | DOCTOR_SESSION_LEASE_CLAIMED | trx_doctor_session_leases:6 | by=18 | {"reason": null, "outcome": "granted", "lease_id": 6, "incumbent_lease_id": null, "effective_branch_id": 5}
695 | 02:38:03Z | DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS | mst_doctor_device_authorizations:6 | by=18 | {"doctor_id": 21, "doctor_device_id": 6}
696 | 02:52:50Z | DOCTOR_BRANCH_COVER_REQUESTED | trx_doctor_branch_covers:1 | by=1 | {"status": "pending", "ends_at": "2026-09-12T03:25:00+00:00", "cover_id": 1, "doctor_id": 21, "starts_at": "2026-09-12T02:25:00+00:00", "decided_at": null, "cancelled_at": null, "target_branch_id": 3, "requester_user_id": 1, "decided_by_user_id": null, "cancelled_by_user_id": null, "source_home_branch_id": 5}
697 | 03:03:47Z | DOCTOR_SESSION_LEASE_REVOKED | trx_doctor_session_leases:6 | by=11 | {"reason": "effective_branch_changed", "outcome": "revoked", "user_id": 18}
698 | 03:03:47Z | DOCTOR_BRANCH_COVER_APPROVED | trx_doctor_branch_covers:1 | by=11 | {"status": "approved", "ends_at": "2026-09-12T03:25:00+00:00", "cover_id": 1, "doctor_id": 21, "starts_at": "2026-09-12T02:25:00+00:00", "decided_at": "2026-09-12T03:03:47+00:00", "cancelled_at": null, "session_released": true, "target_branch_id": 3, "requester_user_id": 1, "state_at_decision": "active", "decided_by_user_id": 11, "live_home_branch_id": 5, "cancelled_by_user_id": null, "source_home_branch_id": 5, "doctor_online_at_decision": true, "online_impact_acknowledged": true}
699 | 03:06:21Z | DOCTOR_SESSION_LEASE_EVICTED | users:18 | by=18 | {"reason": "lease_missing", "outcome": "evicted"}
700 | 03:07:17Z | DOCTOR_DEVICE_LOGIN_REQUESTED | mst_doctor_device_authorizations:6 | by=18 | {"status": "active", "doctor_id": 21, "device_status": "active", "doctor_device_id": 6}
701 | 03:07:18Z | DOCTOR_SESSION_LEASE_CLAIMED | trx_doctor_session_leases:7 | by=18 | {"reason": null, "outcome": "granted", "lease_id": 7, "incumbent_lease_id": null, "effective_branch_id": 3}
702 | 03:07:18Z | DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS | mst_doctor_device_authorizations:6 | by=18 | {"doctor_id": 21, "doctor_device_id": 6}
703 | 03:26:36Z | DOCTOR_SESSION_LEASE_EVICTED | trx_doctor_session_leases:7 | by=18 | {"reason": "branch_context_changed", "outcome": "evicted"}
704 | 03:30:18Z | DOCTOR_DEVICE_LOGIN_REQUESTED | mst_doctor_device_authorizations:6 | by=18 | {"status": "active", "doctor_id": 21, "device_status": "active", "doctor_device_id": 6}
705 | 03:30:19Z | DOCTOR_SESSION_LEASE_CLAIMED | trx_doctor_session_leases:8 | by=18 | {"reason": null, "outcome": "granted", "lease_id": 8, "incumbent_lease_id": null, "effective_branch_id": 5}
706 | 03:30:19Z | DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS | mst_doctor_device_authorizations:6 | by=18 | {"doctor_id": 21, "doctor_device_id": 6}
```

**Row 690 corrects something I said during the ceremony.** I described that 08:10:59 WITA rejection
as a device-login ticket being refused. It is not. Its payload reads
`{"reason": "active_session_elsewhere"}` — it is **PR-A's single-session denial**, fired while the
flag was still armed from the previous window and lease 5 was the live incumbent. The HTTP trace
agrees: the login was refused, she was bounced, and her existing session still answered 200. So the
refuse-never-evict rule was observed on production one more time than this record previously
claimed, and my explanation of the mechanism was wrong.

### Every lease this account has ever held

```
1 | claimed 22:18Z | released 22:18Z | reason device_invalidated | by - | effbr - | cover -
2 | claimed 22:22Z | released 22:45Z | reason logout | by - | effbr - | cover -
3 | claimed 22:46Z | released 22:46Z | reason device_invalidated | by - | effbr - | cover -
4 | claimed 22:47Z | released 22:47Z | reason device_invalidated | by - | effbr - | cover -
5 | claimed 22:51Z | released 02:35Z | reason admin_release | by 11 | effbr 5 | cover -
6 | claimed 02:38Z | released 03:03Z | reason effective_branch_changed | by 11 | effbr 5 | cover -
7 | claimed 03:07Z | released 03:26Z | reason effective_branch_changed | by - | effbr 3 | cover 1
8 | claimed 03:30Z | released - | reason ACTIVE | by - | effbr 5 | cover -
```

### The cover row, as stored

```
id 1 | doctor 21 | requester 1 | decided_by 11 | source 5 -> target 3 | starts 2026-09-12 02:25:00Z | ends 2026-09-12 03:25:00Z | status approved | decided 2026-09-12 03:03:47 | cancelled NULL | updated 2026-09-12 03:03:47
```

`starts_at` and `ends_at` are UTC in storage and were typed as 10:25 and 11:25 clinic time.
`updated_at` equals `decided_at`, which is the evidence that **nothing rewrote the row** after the
decision, and therefore that expiry was evaluated live rather than by a scheduled job.

### Active room inventory, the four RME branches

```
TLK1 | 1 | RM-LDKB | Ruang Landak B | inactive
TLK1 | 2 | RM-LDKC | Ruang Landak C | inactive
TLK1 | 3 | RM-TND | Ruang Tindakan | active
TLK1 | 4 | RM-LDKA | Ruang Landak A | inactive
TLK1 | 5 | RM-STR | Ruang Sterilisasi | active
TLK1 | 6 | TKM-A | Ruangan A | active
TLK1 | 7 | TKM-B | Ruangan B | active
TLK1 | 8 | TKM-C | Ruangan C | inactive
LDK2 | 9 | LDK-A | Ruangan A | active
LDK2 | 10 | LDK-B | Ruangan B | active
LDK2 | 11 | LDK-C | Ruangan C | active
ATG3 | 12 | ATG-A | Ruangan A | active
ATG3 | 13 | ATG-B | Ruangan B | active
ATG3 | 14 | ATG-C | Ruangan C | inactive
SPN4 | 17 | SPN-A | Ruangan A | active
SPN4 | 18 | SPN-B | Ruangan B | active
```

Eleven active rooms across the four RME branches, not sixteen. Both SPN4 and ATG3 have exactly two,
and all four of those are named *Ruangan A* and *Ruangan B*, which is why only the room code or the
granted room id can serve as narrowing evidence.

### HTTP evidence, with actor attribution

```
02:26:53Z | operator | GET /dashboard -> 302
02:26:53Z | operator | GET /login -> 200
02:31:34Z | operator | POST /login -> 302
02:31:34Z | operator | GET /dashboard -> 200
02:34:30Z | operator | GET /rme/doctor-branch-locks -> 200
02:35:01Z | operator | POST /rme/doctor-branch-locks/21/release-session -> 302
02:35:01Z | operator | GET /rme/doctor-branch-locks -> 200
02:37:53Z | tablet   | POST /device-api/v1/doctor/challenge -> 200
02:37:53Z | tablet   | POST /device-api/v1/doctor/login -> 200
02:38:03Z | tablet   | GET /device-login/045b8ae8bf88240ad514da7194caf842b8550a3fe0362a45ba50c72c98e06451 -> 302
02:38:09Z | tablet   | GET /rme/online-context/select -> 200
02:42:24Z | tablet   | POST /rme/online-context/doctor -> 302
02:42:25Z | tablet   | GET /dashboard -> 200
02:42:40Z | tablet   | GET /rme/medical-records -> 200
02:43:01Z | operator | GET /rme/visits/23/medical-record -> 302
02:43:02Z | operator | GET /rme/visits/9/medical-record -> 200
02:43:02Z | operator | GET /rme/handwritings/9/image -> 200
02:43:02Z | operator | GET /rme/handwritings/9/image -> 200
02:43:23Z | tablet   | GET /rme/visits/23/medical-record -> 302
02:43:24Z | tablet   | GET /rme/visits/9/medical-record -> 200
02:43:40Z | tablet   | GET /rme/visits/23/medical-record -> 302
02:43:48Z | tablet   | GET /rme/visits/23/medical-record -> 302
02:43:51Z | tablet   | GET /rme/visits/9/medical-record -> 200
02:43:51Z | tablet   | GET /rme/visits/23/medical-record -> 302
02:43:52Z | tablet   | GET /rme/visits/23/medical-record -> 302
02:43:52Z | tablet   | GET /rme/visits/9/medical-record -> 200
02:43:55Z | tablet   | GET /rme/visits/9/medical-record -> 200
02:43:58Z | operator | GET /dashboard -> 200
02:43:59Z | tablet   | GET /rme/handwritings/9/image -> 200
02:44:00Z | tablet   | GET /rme/handwritings/9/image -> 200
02:44:07Z | tablet   | GET /rme/visits/23/odontogram -> 200
02:50:47Z | operator | GET /dashboard -> 200
02:50:50Z | operator | POST /logout -> 302
02:50:50Z | operator | GET / -> 302
02:50:50Z | operator | GET /login -> 200
02:50:54Z | operator | POST /login -> 302
02:50:54Z | operator | GET /dashboard -> 200
02:51:02Z | operator | GET /rme/doctor-branch-covers/new -> 200
02:52:50Z | operator | POST /rme/doctor-branch-covers -> 302
02:52:50Z | operator | GET /rme/doctor-branch-covers/new -> 200
02:56:52Z | operator | GET /rme/doctor-branch-covers/new -> 200
02:56:59Z | operator | GET /rme/doctor-branch-covers/new -> 200
02:57:39Z | operator | GET /rme/doctor-branch-covers -> 405
03:02:58Z | operator | GET /rme/doctor-branch-locks -> 200
03:03:06Z | operator | POST /rme/doctor-branch-covers/1/approve -> 302
03:03:06Z | operator | GET /rme/doctor-branch-locks -> 200
03:03:26Z | operator | POST /logout -> 302
03:03:26Z | operator | GET / -> 302
03:03:26Z | operator | GET /login -> 200
03:03:32Z | operator | POST /login -> 302
03:03:33Z | operator | GET /dashboard -> 200
03:03:42Z | operator | GET /rme/doctor-branch-locks -> 200
03:03:47Z | operator | POST /rme/doctor-branch-covers/1/approve -> 302
03:03:48Z | operator | GET /rme/doctor-branch-locks -> 200
03:06:21Z | tablet   | GET /rme/medical-records -> 302
03:06:24Z | tablet   | GET /rme/medical-records -> 302
03:06:24Z | tablet   | GET /login -> 200
03:07:16Z | tablet   | POST /device-api/v1/doctor/challenge -> 200
03:07:17Z | tablet   | POST /device-api/v1/doctor/login -> 200
03:07:18Z | tablet   | GET /device-login/3f2622ecd9756ab1bb84645fe9e05fcaa28d08744f53769f2bfde68e5c174a74 -> 302
03:07:30Z | tablet   | GET /rme/medical-records -> 302
03:07:31Z | tablet   | GET /rme/online-context/select -> 200
03:07:54Z | tablet   | POST /rme/online-context/doctor -> 302
03:07:55Z | tablet   | GET /dashboard -> 200
03:13:11Z | tablet   | GET /rme/medical-records -> 200
03:13:14Z | tablet   | GET /rme/treatment-room-worklist -> 200
03:13:15Z | tablet   | GET /rme/reports/doctor-performance -> 200
03:13:23Z | tablet   | GET /dashboard -> 200
03:26:36Z | tablet   | GET /rme/medical-records -> 302
03:26:37Z | tablet   | GET /login -> 200
03:30:18Z | tablet   | POST /device-api/v1/doctor/challenge -> 200
03:30:18Z | tablet   | POST /device-api/v1/doctor/login -> 200
03:30:19Z | tablet   | GET /device-login/30ced9e0e1bba454b7d05bc6df22fee3770f04020e5add442cb66c87b9d3d77b -> 302
03:30:19Z | tablet   | GET /rme/online-context/select -> 200
03:30:29Z | tablet   | POST /rme/online-context/doctor -> 302
03:30:29Z | tablet   | GET /dashboard -> 200
```

**Gate 6 rests on the `tablet` lines only, and this matters.** The operator's browser also opened
patient 28's record at 02:43:01Z, but that browser was signed in as the Supervisor RME, a
**governance actor** whose archive scope is the whole RME branch set. A cross-branch read by a
governance actor proves nothing about a doctor's scope. The evidence for
`ARCHIVE_CROSS_BRANCH_READ` is the tablet's own sequence at 02:43:23Z through 02:44:07Z, made by
drg Karmila's session while her effective branch was SPN4: visit 23 at LDK2 redirecting to the
canonical sheet at ATG3, the handwriting image served twice, and the LDK2 odontogram opening
directly.

The two `POST /rme/online-context/doctor` lines are the branch narrowing being accepted
server-side, once per phase. The two bounces at 03:06:21Z and 03:26:36Z are the two evictions, and
they are the only 302s on protected paths in the whole window.
