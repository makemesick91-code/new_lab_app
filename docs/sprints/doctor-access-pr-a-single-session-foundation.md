# DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION

**The first of three children of `DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1`.**

**This pull request does NOT create the parent GO tag.** `DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1`
closes only when PR-A, PR-B and PR-C have each shipped and been proven on real hardware.
The tag this pull request earns is its own.

- **Branch:** `feature/doctor-access-pr-a-single-session-foundation`
- **Base:** `215d277d`
- **Type:** `FOUNDATION_SPRINT` (audit level 3)
- **GO tag on merge:** `doctor-access-pr-a-single-session-foundation-go`
- **Flag:** `doctor.single_active_session` (`FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION`), risk
  `critical`, committed **OFF**
- **Rules:** `docs/architecture/doctor-single-active-session-lease.md` (LEASE-R001..R024)
- **Operations:** `docs/runbooks/doctor-session-lease-operations.md`
- **Shared design archive:** `docs/sprints/doctor-access-single-session-branch-lock-1/`

---

## 1. Why this is three pull requests

The combined sprint was implemented and green — 95 new tests, 809 assertions, wide
regression 2489 passed. It was still split, because the DEVFLOW scope auditor returned
**NO-GO** on the whole and the owner accepted that verdict rather than waiving it. Two
capabilities, four permissions, five tables and a fleet-wide device tool in one diff is not
independently reviewable, testable or deployable, and the flag that arms it can refuse a
doctor's login.

| | Scope | Ships |
| --- | --- | --- |
| **PR-A** (this one) | The session lease. One active session per doctor, a race-safe claim, per-request revalidation, logout release, dead-lease recovery, force logout, the audit vocabulary, the middleware and the lease migration. | now |
| **PR-B** | The branch lock, branch transfer, temporary cover, the effective-branch resolver, operational-list narrowing, the approval workflows and the HTTP force-logout surface. | next |
| **PR-C** | The bulk device-authorization command. | after |

The rule that decided every judgement call: **PR-A must build and pass its own tests on
its own base with nothing from PR-B or PR-C present, and must leave no half-dependency
behind** — no class nothing calls, no column nothing writes, no config key nothing reads,
no permission nothing checks.

---

## 2. What ships

### Runtime

`App\Modules\DoctorAccess`:

| File | Role |
| --- | --- |
| `Models/DoctorSessionLease` | The row, the release vocabulary, `isActive()`, `matchesTokenHash()`, `scopeActive()` |
| `Interfaces/DoctorSessionLeaseRepositoryInterface` + `Repositories/DoctorSessionLeaseRepository` | Every read and write of the table, including the row lock |
| `Services/DoctorSessionLeaseService` | **Every** lease decision: claim, deny, reclaim, renew, revalidate, evict, release |
| `Services/DoctorSessionReleaseService` | Force logout — room then lease, the self-release refusal, the preview, two audit rows |
| `Services/DoctorAccessSubjectGuard` | The decidable-subject guard: missing / inactive / unlinked, under a row lock |
| `Support/DoctorAccessSubject` | The locked doctor and their account, as one value |
| `Support/DoctorSessionLeaseVerdict` | The claim outcome, so the transaction can commit before anything is audited or torn down |
| `Support/IncumbentSessionProbe` | "Is the incumbent alive?" and, separately, "can we see at all?" |
| `Middleware/EnsureDoctorSessionLease` | Per-request revalidation and teardown |
| `Listeners/ClaimDoctorSessionLease` | The claim, on the framework `Login` event |

Plus `app/Console/Commands/DoctorSessionForceLogoutCommand.php`,
`config/doctor_access.php`, and the migration
`2026_09_10_100001_create_trx_doctor_session_leases_table.php`.

Touched: `AuthenticatedSessionController` and `ProfileController` (release on logout and on
account deletion), `DoctorDeviceSessionService` (a pre-ticket availability check),
`AppServiceProvider` (the listener), `RepositoryServiceProvider` (one binding),
`bootstrap/app.php` (the middleware, appended to `web`), `PermissionGroupingService`,
`PermissionSeeder`, `RoleSeeder`, `config/feature_flags.php`, `config/ci_runner.php`,
`.github/workflows/foundation-evidence-gates.yml`.

### The one permission

`release_doctor_session_leases` — granted to **Supervisor RME** and to Super Admin via the
`'*'` sync. Nobody else. Classified under the **RME** group, not Access Control: the
doctors it is about are an RME population and the sole grantee is an RME role.

It is a separate grant on purpose. It ends a **login session** and nothing else, so it can
be audited and withdrawn on its own without disturbing who may manage a tablet.

### The force-logout surface

The combined sprint reached `DoctorSessionReleaseService` only through an HTTP controller
that belongs to PR-B, so on this base the service would have had no caller. The owner put
force logout in PR-A, so PR-A gives it a real surface: **`doctor:session-force-logout`**.

One doctor at a time (no `--all`, no wildcard). Dry-run unless `--apply`. A named, active
actor holding the permission. A written reason bounded by config. Non-zero exit on every
refusal; "nothing to release" is exit 0 and says so.

---

## 3. The three cross-PR dependencies, and what was done about each

Established by reading the combined sprint's code, not inferred.

### 1. The middleware imported the PR-B resolver

`EnsureDoctorSessionLease` imported `DoctorEffectiveBranchResolver` (line 7), injected it
(55) and called `resolve()` (91) to compare the session's established branch against the
current effective one, evicting on a change.

**PR-A ships the middleware with lease revalidation only** — no resolver dependency, no
effective-branch comparison, no eviction-on-branch-change. PR-B adds that back.

Preserved verbatim: `markOffline()` **before** `evict()` (the room is freed before the
teardown), and the deny path through `tearDown()`, which calls `logoutCurrentDevice()` and
never `logout()`.

### 2. The listener imported the same resolver

`ClaimDoctorSessionLease` resolved the effective branch and passed it into the claim.
**PR-A claims with no branch arguments.** Every other guard — guard name, `enabled()`,
`subjectTo()`, `hasSession()` — is untouched.

### 3. The migration constrained a PR-B table

The combined migration `2026_09_10_100004` constrained `effective_cover_id` to
`trx_doctor_branch_covers`, which PR-A does not create — so **PR-A could not have run it.**

PR-A ships the table **without** `effective_branch_id` and **without** `effective_cover_id`,
without the `effective_branch_id` index, renumbered to `2026_09_10_100001`.

**Kept exactly as written**, because it is the core invariant and was proven on both
engines:

```sql
CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq
ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL
```

Everything the split removed from the runtime went with it, so nothing is left half-wired:
the `DENY_BRANCH_CONTEXT_CHANGED` constant, the branch parameters on `claimOrDeny()` and
`revalidate()`, the two attribute keys, the two audit-metadata additions, the
`establishedUnder()` comparison, the branch release in `evict()`, the branch arm of
`denialMessage()`, two model casts, two relations and two release reasons that nothing
would have written.

---

## 4. Tests

`tests/Feature/DoctorAccess/` — **48 tests**, all green.

| File | Tests | Subject |
| --- | --- | --- |
| `helpers.php` | — | Shared fixtures. Every helper names the measured trap it exists to avoid. |
| `DoctorSingleSessionLeaseTest.php` | 16 | Claim, refused-not-evicted, the shared remember token, logout release and re-claim, dead-vs-idle and the exact liveness boundary, the `user_id` liveness predicate, the pass-through, fail-inert, next-request eviction, three doctors at three chairs, the remember-me recaller path (U1), the database cardinality invariant, and the hot-path query budget (U2). |
| `DoctorMultiDeviceAccessTest.php` | 9 | Owner decision O4: serial access across every trusted tablet, with real EC P-256 cryptography through the real login route. The tablet's branch is not a login predicate. Every device refusal that must survive multi-tablet access. |
| `DoctorSessionLeaseGovernanceTest.php` | 8 | The permission is seeded (read out of the command's own source), classified out of the Other bucket, and held by Supervisor RME and nobody else. The flag is OFF, critical and fully described. The engine fails inert. Nothing here reads the device enforcement flag. The sprint boundary has not moved. The reason bounds come from source control, never the environment. |
| `DoctorSessionForceLogoutTest.php` | 15 | **New to the sprint.** The command had no coverage anywhere, because the service's only caller was PR-B's controller. |

`tests/Feature/Auth/SupervisorRmeRolePermissionTest.php` gained the single entry
`release_doctor_session_leases` to its exact-list pin — one entry, not the four the
combined sprint added.

`DoctorAccess` is a **required critical-gate filter token** (`config/ci_runner.php`), and
the token was added to both critical variants in the workflow. That is not decoration: the
PostgreSQL gate is the only place the partial unique index is exercised under a real lock.

### Two carried fixtures had no caller, and now have a real one

`daInsertSessionRow()` and `daDeleteSessionRow()` were used by the combined sprint's
branch-approval suites and arrived here with nothing calling them — dead fixtures, which the
no-half-dependency rule forbids as much as it forbids dead runtime.

Rather than delete them, they were given the test they enable, which nothing in the combined
sprint pinned: **LEASE-R006, the `user_id` liveness predicate.** It turned out to measure
something stronger than the rule as written. The session id the lease records has **no row at
all** by the time the response is written — the claim runs before the login's own
`regenerate(true)` — so a probe keyed on `leases.session_id` would not merely be fragile, it
would report **every** incumbent dead and hand the lease to the second browser every time.
The test asserts that (`daDeleteSessionRow()` on the recorded id deletes zero rows), then
proves the account is still refused while any row survives, then kills the rest and watches
the same login reclaim instead.

`daSetFlag()` and `daActiveLeaseCount()` have no direct suite caller either, but both are
called by their sibling helpers (`daArmSingleActiveSession()` and
`daAssertActiveLeaseCount()`) — internal composition, not dead code.

### Four public members with no caller

`DoctorSessionLeaseVerdict::outcome()`, `DoctorSessionLease::releasedBy()`,
`::scopeActive()` and `::matchesTokenHash()` had no caller after the split. They are not
half-dependencies created by it — only `matchesTokenHash()` had one in the combined sprint,
in its own lease test. Rather than ship unread public API, **all four are now asserted**:
the token digest and its near miss, the release relation resolving the operator,
`scopeActive()` against the predicate the partial index uses, and the verdict's outcome
through the claim paths that produce it.

---

## 5. HONEST LIMITS

### Concurrency is not proven by the suite

`RefreshDatabase` wraps every test in one transaction on one connection, so a cross-
connection race is **not expressible**. `lockForUpdate` compiles to an **empty string** on
SQLite, so the racing claim is never actually contended locally — and even on the
PostgreSQL critical gate the claim runs one savepoint deeper than production.

The database-cardinality test proves the **logic** of the partial unique index: a second
unreleased row for one user is refused by the database, and the enclosing transaction
survives because the conflicting INSERT is nested so the framework emits a SAVEPOINT.
It does **not** prove that two concurrent connections cannot both win. Only the PostgreSQL
gate exercises the lock at all.

What that means in practice: if the lock were wrong, the local suite would stay green.
Treat a PostgreSQL-only failure in `DoctorAccess` as a real concurrency finding, never as
a flake.

### The branch columns arrive in PR-B, and no backfill may be added

`trx_doctor_session_leases` has no `effective_branch_id` and no `effective_cover_id` on this
base. PR-B adds both additively.

**Leases claimed between the two deploys carry nulls, and PR-B's comparison tolerates a
null — so nobody is evicted by the PR-B deploy.** A backfill would have to invent an
effective branch for a session established before the concept existed, which is a
fabricated clinical fact, and it would evict every doctor whose guess did not match. **Do
not add one.**

### What only a real-device ceremony can prove

Everything below is untested by any suite and must be demonstrated in a supervised window
on real hardware, with a doctor present:

- **That the refusal is legible on a clinic tablet.** The denial renders through the login
  view's single `email` error-bag key, which is the shape proven to render inside the
  Android WebView — but nobody has read it on the device, and a refusal a doctor cannot
  understand is an outage with extra steps.
- **That the remember-me path terminates on a real browser.** The anti-loop mechanism is a
  `Set-Cookie` that expires the recaller the browser presented. The test client does not
  honour `Set-Cookie`, so the forget cookie is asserted directly and the clean hops are
  issued with an explicit empty cookie jar. **A browser that ignores the header would
  loop**, and only a browser can show it does not.
- **That the renew write really is periodic.** The test client forwards no session cookie,
  so the session id drifts on every request and `renewIfDue()` writes every time. The
  measured local delta of 3 queries per request therefore includes an UPDATE that
  production pays on re-authentication and roughly every five minutes. The production
  figure is unmeasured.
- **That a doctor walking between tablets is not refused.** Proven serially in the suite;
  the concurrent case needs two real tablets.
- **How long "the next request" actually is.** LEASE-R018 says a released session ends on
  its next request, which for an idle tablet can be minutes. Nobody has measured what a
  clinic tablet's idle request interval is, and the runbook says so rather than quoting a
  number.

### Deliberately not delivered

- **Ruling P17's "on site, without an SSH session".** The operator surface here is a
  console command, so it needs server access. PR-B's HTTP controller closes that, sitting
  on this service without restating one of its decisions. The service docblock says so
  rather than implying otherwise.
- **`countAll()` on the device-authorization repository.** It belongs to PR-C's bulk tool
  and was not taken; it has zero references here.

---

## 6. Deploy

**No seeding of the flag, and no arming during the deploy.**

```
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan permission:cache-reset
```

One additive migration; one table; no alter, no drop, no backfill. Permissions before
roles.

`FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION` stays **false**. Arming can refuse a doctor's login,
so it belongs to a supervised activation window — §2 of the runbook.

**Before arming, confirm `session.driver` is `database`.** On any other driver the engine
disarms itself (LEASE-R007) and arming the flag changes nothing.

---

## 7. Handover to PR-B

- **Migration slots.** PR-A occupies `2026_09_10_100001`, which in the combined sprint was
  `create_mst_doctor_branch_locks_table`. PR-B renumbers its three tables (locks, lock
  requests, covers) and adds `effective_branch_id`, `effective_cover_id` and the
  `effective_branch_id` index to `trx_doctor_session_leases` in a **separate additive**
  migration.
- **Restore in the model:** the two casts, `effectiveBranch()`, `effectiveCover()`,
  `establishedUnder()`, and the `RELEASE_EFFECTIVE_BRANCH_CHANGED` /
  `RELEASE_BRANCH_TRANSFER_APPROVED` reasons with their `RELEASE_REASONS` entries.
- **Restore in the service:** `DENY_BRANCH_CONTEXT_CHANGED`, the branch parameters on
  `claimOrDeny()` and `revalidate()`, the two attribute keys, the two audit-metadata
  additions, the `revalidate()` comparison, the branch release in `evict()`, and the branch
  arm of `denialMessage()`.
- **Restore the resolver injection:** three points in the middleware, two in the listener.
- **Restore in `DoctorAccessSubjectGuard`:** `assertRequestableSubject()`,
  `tryLockDecidableSubject()` and `assertOnlineImpactAcknowledged()`, each of which had no
  filing, cancellation or approval surface here.
- **Config, not a second file.** `config/doctor_access.php` is named after the module. PR-B
  should add its cover bounds as a `cover` block **in that file** rather than introducing
  `config/doctor_branch_lock.php`, or one module ends up with two config files. The reason
  bounds are unchanged from the combined sprint (10 / 1000), so folding the block in
  changes nothing.
- **The permission check stays at the surface.** The service performs the operation and each
  surface authorizes its own caller. PR-B's controller must gate
  `release_doctor_session_leases` through its policy; the service does not assert it. The
  **self-release refusal is different** — it lives inside the service's transaction and
  PR-B inherits it for free.

## 8. Production evidence, 2026-09-11

Recorded after the deploy, not inferred from it.

### Merge and deploy identity

| | |
|---|---|
| `PR_A_CANDIDATE_SHA` | `819a42d2eabb1767b36af08397f2bd713791bded` |
| `PR_A_MERGE_SHA` | `80afaad1f01fab7cc027c7a68c3a06a60ef5cf2d` |
| Candidate tree | `88333b58618604fbc0f7f27b0f273154d8e7970e` |
| Merge tree | identical to the candidate tree — the squash changed no content |
| `PRODUCTION_HEAD` | `80afaad1…` — equals the merge SHA |
| `PRODUCTION_TREE` | `88333b58…` — equals the merge tree |
| `DEPLOY_HOST` | `srv1730088` |
| `DEPLOY_EXECUTION_LOCATION` | VPS, never the workstation |
| `DEPLOY_SCRIPT` | `scripts/deploy-vps-runner.sh`, pid 1473545, detached |
| `DEPLOY_EXIT_CODE` | 0 |
| `DEPLOY_STATUS` | `DEPLOY RUNNER OK` / `DEPLOY OK: 20260911-111127` |
| `DEPLOY_HEAD_TARGET_MATCH` | YES |

### The schema, and the invariant actually present on PostgreSQL

`MIGRATION_APPLIED=YES` (batch 70), `MIGRATIONS_PENDING=0`. The cardinality guard is live with
its predicate intact, read back from `pg_indexes`:

```
CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq
  ON public.trx_doctor_session_leases USING btree (user_id) WHERE (released_at IS NULL)
```

### Inert, which is the point of this deploy

Production runs the **database** session driver, so the engine is observable there. The flag
default is therefore the only thing holding enforcement off — not a second accidental safety net,
which is why this was verified rather than assumed.

- `SINGLE_SESSION_FEATURE_FLAG=false` — `enabled=false default=false via=default`, no environment override present
- `SINGLE_SESSION_ENGINE_EFFECTIVE=false`
- `SESSION_STORE_OBSERVABLE=YES` (driver `database`, `sessions` table present)
- `ACTIVE_LEASE_ROWS_CREATED_BY_NORMAL_EXISTING_TRAFFIC=0` — total lease rows 0, active 0
- `UNEXPECTED_ACTIVE_DOCTOR_LEASES=0`
- `MIDDLEWARE_DUPLICATED=NO` — one import and one registration, first in the web group, ahead of the presence-touch middleware

### Pilot untouched, global still off

`DECLARED_COHORT=[9,15,18]`, `RESOLVED_COHORT=[9,15,18]`, `COVERED_COHORT=[9,15,18]`, cohort
`all_ready=true`. Fleet unchanged at 15 target / 3 ready / 12 not ready; devices 5 total, 3 active,
2 revoked, 3 usable credentials. `GLOBAL_ENFORCEMENT_ACTIVE=false`,
`global_scope_permitted=false`, posture `bounded_pilot`, scope `pilot`.
`EXISTING_AUTH_SECURITY_PRESERVED=YES` — no device, authorization, credential or branch changed.

### Health and the error delta

`/login`, `/health/live` and `/health/ready` all 200 over the canonical domain. `APP_DEBUG=false`,
maintenance off, `FAILED_JOBS=0`, queue worker active.

`PRE_DEPLOY_ERROR_COUNT=0`, `POST_DEPLOY_ERROR_COUNT=0`, `ERROR_COUNT_DELTA=0`,
`NEW_SINGLE_SESSION_ERRORS=0`, `NEW_AUTH_ERRORS=0`. This is a true zero and not a coincidental
total: the log is byte-identical across the deploy (1406217 bytes, 9114 lines), its last entry
predates the deploy by two days, and it contains no mention of the lease engine at all.

### Two pre-existing conditions, neither caused by this deploy

1. The automated smoke returns **WATCH** on one check: a probe to `http://127.0.0.1/login` gets 404.
   The identical warning appears in all six preceding deploy logs back to 2026-09-08. The canonical
   entry point is healthy — the domain returns 200 — and the probe uses loopback, which is not the
   canonical entry point. A smoke-probe weakness, not a production fault, and out of scope here.
2. The newest log entry, `2026-09-09 23:00 pilot.ERROR: Writing to directory …`, predates this
   deploy.

### Known gap, assigned to PR-B

**No automated test asserts the middleware is registered exactly once.** Registration was proven on
the deployed tree by source plus tree-identity with the tested candidate, and a throwaway probe
asserted it during development, but that probe was deleted and the shipped suite only mentions the
ordering in comments. `route:list` structurally cannot see a group-appended middleware, and a REPL is
forbidden on production, so there is no canonical runtime reporting mechanism to lean on. PR-B edits
this middleware anyway and must add the assertion, resolving the web middleware group through the
HTTP kernel and asserting exactly one occurrence.

### Full Suite, recorded permanently

`FULL_SUITE_EXECUTED=NO`. `FULL_SUITE_RESULT=SKIPPED`. `FULL_SUITE_CLAIMED_PASS=NO`.

One Full Suite runs after all three children are merged, deployed and production-verified, on the
final immutable tree, and it gates the parent tag alone. This child skip must never be read as a
parent pass.
