# FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — Daily Working-Branch Lock for Kasir & Admin Klinik

**Type:** FEATURE / ACCESS_CONTROL / MULTI_BRANCH_GOVERNANCE / SECURITY_SENSITIVE
**Module:** `App\Modules\RmeOnlineContext`
**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**GO tag:** `feature-daily-branch-context-lock-1-go`

---

## 1. The defect this closes

Before this sprint a **Kasir** or **Admin Klinik** could change their working
branch as many times as they liked, at any moment, with no record and no
approval. The route was the ordinary online-context selector
(`POST /rme/online-context/kasir`, `.../admin-clinic`), which simply rewrote the
single `trx_user_online_contexts` row for that user.

That row is priority #1 in `BranchContext::forUser()`, and `RmeWorkingBranchScope`
resolves the entire clinic-operations and **cashier financial** workspace from
it. So a free re-selection silently moved:

- which visits, patients and queue entries the operator could see;
- which invoices, receivables and payments the **cashier** could act on;
- which branch a newly registered visit was stamped with.

A probe run against the baseline confirmed it end to end: select branch A,
assert `BranchContext` returns A, select branch B, assert it returns B. Both
assertions passed. That probe is now inverted and lives in
`tests/Feature/AccessControl/DailyBranchContextLockTest.php`.

---

## 2. The contract

```
FIRST branch of a clinical day   → free, any branch the user may work in
SECOND, different branch         → REFUSED until a Super Admin approves it
SAME branch again                → idempotent, always allowed
NEXT clinical day                → one new free selection
```

An approval authorises **exactly one move**:

| Binding | How it is enforced |
|---|---|
| **User** | the request row carries `requester_user_id`; the approval locks *that* user's daily context |
| **Source** | the live context must **still** sit on `source_branch_id`, re-asserted under the lock |
| **Destination** | re-validated for eligibility at decision time, not at filing time |
| **Clinical day** | `clinical_date` must be today, checked without relying on any cron |
| **Single use** | the switch is applied *inside* the approval transaction; there is no token to replay |

**An approval is not a branch grant.** The destination must be an active,
RME-enabled branch at the moment of approval. A Super Admin approving a move
cannot hand someone access to a branch they could not otherwise work in.

---

## 3. Why the authority is a table, not the session

`trx_user_online_contexts` holds **one row per user** and is rewritten by every
session start. It is a *session representation*. Overloading it would make the
lock evaporate the moment the operator went offline and back on — precisely the
bypass this feature exists to close.

The authority is therefore `trx_daily_branch_contexts`, keyed
`UNIQUE(user_id, clinical_date)`, and
`UserOnlineContextService::activeContextBranchId()` now lets that row **override**
the session row. Two consequences fall out of this, both tested:

- a tampered or stale `trx_user_online_contexts.branch_id` does not move the
  scope;
- an approved switch takes effect in **every** one of the operator's existing
  sessions on their next request, not just the one that was realigned.

The override only ever **replaces** a branch; it never resurrects a `null`. An
offline operator still has no working context and must go through the selector,
where the lock holds them to the same branch.

---

## 4. The uniqueness key is `(user_id, clinical_date)` — and nothing else

Deliberately **not** keyed on `role_context`. A user holding both `Kasir` and
`Admin Klinik` would otherwise open an admin-clinic context at one branch and a
cashier context at another on the same day, and neither row would collide.

One human, one clinical day, one branch. `role_context` is recorded for audit
only. This is pinned by
*"it locks the DAY, not the role context, so a kasir+admin klinik user cannot work two branches"*.

---

## 5. The clinical day is the clinic's day

`clinical_date` comes from `ClinicalClock` (Asia/Makassar), never a UTC-derived
`today()`. Between 16:00 and 24:00 UTC it is already tomorrow in the clinic; a
UTC key would file a selection under the wrong day and hand the operator a
second free choice.

The lock interval is a **calendar day** (00:00:00–23:59:59 clinical), not a
rolling 24 hours from the first selection. Both boundaries are pinned with a
frozen clock:

- `2026-08-29 15:59:59 UTC` (23:59:59 WITA) → still locked;
- `2026-08-29 16:00:00 UTC` (00:00:00 WITA) → new day, new free selection;
- a selection at 09:00 WITA does not stay locked until 09:00 the next morning.

---

## 6. Fail closed on the day boundary

A `PENDING` request from a previous clinical day can never authorise a switch —
whether or not any background job has stamped it `EXPIRED`. Security
correctness does not wait for a cron.

`BranchChangeRequest::isStaleForClinicalDay()` is evaluated from the row itself
inside the locked decision, **before** the status check, and refuses. The
`EXPIRED` bookkeeping lives only in the queue listing
(`BranchChangeRequestRepository::expireStale`, called by `index()`).

Both halves of that arrangement were found by testing, not review:

* a write inside the decision transaction is **rolled back by the very exception
  that refuses the decision**, so the stamp could not live there;
* moving the stamp to a step *before* the transaction then made the staleness
  guard **unreachable** — the refusal a test observed actually came from the
  `isPending()` check a few lines later. Deleting the guard entirely left every
  test green. Mutation M13 caught it.

The test now asserts the row is still `PENDING` at the moment of the attempt, so
the refusal can only be the guard, and the housekeeping is asserted separately as
its own property.

---

## 7. Self-approval, and why the policy is not the boundary

`Gate::before` grants a Super Admin **every** ability before any policy runs. So
for the one actor who could conceivably be both requester and approver, a
`decide()` policy clause never executes. Writing the check only there and calling
it done would have been a self-approval hole with a comment claiming otherwise.

The enforced boundary is in `BranchChangeApprovalService`, which compares
requester and approver on every decision and cannot be short-circuited by a gate.
The policy clause is defence in depth for non-Super-Admins, and it documents the
intent at the authorization surface.

Structurally the case is already impossible — `UserOnlineContextService`
exempts Super Admin, so such a user never obtains a daily context to move — but a
boundary that holds only by side effect is one refactor away from not holding.

---

## 8. Concurrency

| Race | Guard | Proven by |
|---|---|---|
| Two sessions, first selection, different branches | `UNIQUE(user_id, clinical_date)` + `lockForUpdate` + unique-violation re-read | PostgreSQL suite |
| Two Super Admins approving the same request | `lockById()` + `isPending()` re-assert under the lock | `change_count` stays 1 |
| Double-submitted change request | partial unique index `WHERE status = 'pending'` | raw insert raises |

The loser of a first-selection race is **re-judged against the committed
winner**: it is either idempotent (it wanted the same branch) or refused (it
wanted a different one). Never a second context, never a silent overwrite.

Row-lock behaviour is only observable on PostgreSQL — SQLite compiles
`lockForUpdate()` to nothing — so
`tests/Feature/AccessControl/DailyBranchContextConcurrencyTest.php` runs against
PostgreSQL 16 and **skips out loud** elsewhere. A concurrency test that "passes"
on a driver with no row locks is worse than no test, because it reads as
evidence.

---

## 9. Schema

Two additive tables. Nothing dropped, no column altered, no data rewritten.

**`trx_daily_branch_contexts`** — `UNIQUE(user_id, clinical_date)`,
`initial_branch_id` (the free first choice, kept for audit),
`current_branch_id` (**the authority**), `first_selected_at`, `last_changed_at`,
`change_count`.

**`trx_branch_change_requests`** — requester, clinical date, role context,
source and destination branches, reason, status, decision fields, `applied_at`,
plus the partial unique index `trx_branch_change_req_pending_uq`.

Branch foreign keys are `restrictOnDelete` — a branch with working history is not
deletable out from under the audit trail.

---

## 10. Mass assignment

`status`, `decided_by_user_id`, `decided_at`, `decision_note` and `applied_at`
are absent from `BranchChangeRequest::$fillable`, so a forged
`status=approved` in a payload has nowhere to land. That is verified by an HTTP
test that posts all of them at once.

This has a consequence the tests caught immediately: `update()` honours
`$fillable`, so it would silently **discard** exactly the fields a decision has
to write — a decision that appeared to succeed and changed nothing. The
repository therefore uses `forceFill`, and `create()` states the initial
`pending` status explicitly rather than leaving it to the column default (a
mass-assigned create returns a model with **no** `status` attribute at all,
so `isPending()` would answer false on a row that is genuinely pending).

---

## 11. Surfaces

| Route | Who |
|---|---|
| `GET rme/branch-change-requests/new` | an operator whose day is locked |
| `POST rme/branch-change-requests` | same |
| `POST rme/branch-change-requests/{id}/cancel` | the requester, while pending |
| `GET rme/branch-change-requests` | Super Admin (`can:branch-change-request.approve`) |
| `POST .../{id}/approve`, `.../{id}/reject` | Super Admin |

**No new permission.** Approval authority is the canonical Super Admin role,
published once as the `branch-change-request.approve` gate and consumed by both
the route group and the sidebar — the same shape as the existing
`satusehat.access` gate, so the menu and the server-side boundary cannot drift.
Inventing a permission would have let approval authority be granted to someone
the lock is meant to constrain.

The request routes are added to `EnsureRmeOnlineContext::EXEMPT_ROUTE_NAMES`.
Without that a locked-but-offline cashier is bounced to the selector, where the
only branch on offer is the one they are trying to get away from — a dead end.
The exemption widens nothing: `BranchChangeRequestPolicy::create` still requires
a daily context, which only a completed selection creates.

---

## 12. Blast radius

One existing test changed:
`FixClinicOpsWorkingBranchContextTest > "follows the cashier to a new branch when
the working context changes"`. Its **intent** — cashier scope follows the working
branch — is unchanged and still asserted; only the route to a switch is different.
It is now two tests: the switch is refused without an approval, and it takes
effect with one.

Nothing else in the RME, cashier, permission or clinic-visit suites changed
behaviour.

---

## 13. Deployment

```
php artisan migrate --force
```

Two additive tables. **No seeder, no permission, no role change** — nothing to
re-seed, nothing to `permission:cache-reset`.

**First-day note.** On the day of deployment every locked operator's *next*
selection becomes their free first selection for that clinical day, because no
daily context exists yet. That is the intended behaviour of the first day, not a
gap: from the following selection onward the lock holds.

---

## 14. Test coverage

| Suite | Property |
|---|---|
| `DailyBranchContextLockTest` | first selection free, idempotent re-selection, second branch refused, multi-role hole closed, Doctor/Perawat untouched, WITA day boundary, calendar-day not rolling-window, ineligible branch does not consume the free choice |
| `BranchChangeApprovalTest` | server-derived bindings, pending leaves the branch alone, atomic apply, re-lock at destination, second switch needs a new approval, rejection, single-use, stale source, previous-day refusal, destination re-validation, self-approval, cancellation, user binding, audit trail |
| `DailyBranchContextBypassTest` | logout, second session, tampered context row, offline, deployment-day path, deactivated locked branch fails closed, unlocked role not pinned, raw POST, payload tampering, the approve gate asserted directly, sidebar, operator surface |
| `DailyBranchContextConcurrencyTest` | first-selection race, DB-level uniqueness, row locks held, approval race, pending-request race (PostgreSQL 16 only) |

---

## 15. Adversarial mutation evidence

Fifteen mutations, each removing one guard, run against the three SQLite suites;
the lock/transaction one re-run against PostgreSQL 16.

| | |
|---|---|
| Attempted | 15 |
| Killed | **15** |
| Equivalent | 0 |
| Real survivors | **0** |

Two of them earned code changes rather than a green tick:

* **M13** (delete the clinical-day staleness guard) survived the first run. The
  guard was unreachable behind a pre-transaction `EXPIRED` stamp — see §6. Fixed,
  then killed.
* **M9** (widen the approve gate to `fn () => true`) survived because
  `BranchChangeRequestPolicy` refused the action a layer later. Defence in depth
  is why nothing broke, but the gate is what the **sidebar** reads, so a widened
  gate would advertise the approver menu to operators who cannot use it and would
  remove one of two layers with nothing turning red. The gate is now asserted
  directly for every non-Super-Admin role. Then killed.

**M11** (remove `lockForUpdate`) survives on SQLite, where it compiles to
nothing, and is killed by `DailyBranchContextConcurrencyTest` on PostgreSQL 16 —
verified by applying the mutation and watching *"holds a row lock on the daily
context while a selection is in flight"* fail. It is recorded as killed, not as
an equivalent mutant.

**The harness itself had a defect worth recording.** Its restore list named five
of the seven files the cases touch, so M9's mutation of
`RepositoryServiceProvider.php` was never reverted and stayed live for M10–M15.
Every verdict after M9 was measured against a weakened baseline. The list is now
the union of all touched files, each case asserts a clean tree before it runs,
and a mutation that fails to apply is reported rather than counted as survived.

---

## 16. The PostgreSQL trap SQLite cannot show you

CI failed on the first authoritative run with a single test, in both the Critical
gate (`1 failed, 2537 passed`) and the Selective Module gate:

```
SQLSTATE[25P02]: In failed sql transaction: current transaction is aborted,
commands ignored until end of transaction block
```

**The rule.** PostgreSQL aborts the ENTIRE transaction the moment any statement
raises. Every later statement fails with `25P02` until a rollback. SQLite does
not behave this way, so "catch the unique violation and carry on" — the obvious
shape, and the one every local run was green on — silently leaves a **poisoned
connection** on the database production actually runs.

**Two sites, unequal severity.**

* `DailyBranchContextService::assertSelectable()` is the serious one. Its INSERT
  is already inside `DB::transaction`, so the re-read that decides whether the
  loser of a first-selection race is idempotent or refused ran on an aborted
  transaction. **That is the race handler itself, broken on production
  PostgreSQL.**
* `BranchChangeApprovalService::request()` is milder: outside a transaction
  PostgreSQL autocommits and nothing is poisoned, so it only broke when called
  within an outer transaction — which is every test, and any future caller.

**Why the PostgreSQL concurrency suite missed it.** Its cases are refused by the
lock check *before* they ever reach the INSERT, so the catch path never executed
there. The suite that was built to prove concurrency correctness could not reach
the branch that was wrong.

**The fix.** Both INSERTs are wrapped in a nested `DB::transaction`, which
Laravel implements as a `SAVEPOINT`. A violation now discards only that
statement and leaves the enclosing transaction usable.

**Verification.** Reproduced locally against `postgres:16.14` before the fix
(identical `25P02`, same test), then after: the whole `tests/Feature/AccessControl`
directory is **108 passed on PostgreSQL, where nothing skips** — strictly more
coverage than the SQLite run, which reports 9 skipped. Two new tests provoke each
violation and then keep querying on the same connection, so the trap cannot
return silently.

**Standing lesson for this repo:** the suite defaults to SQLite; production is
PostgreSQL 16. Any code that *catches* a database error and continues must be
exercised against PostgreSQL before it is believed.
