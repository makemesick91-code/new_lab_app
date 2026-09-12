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

