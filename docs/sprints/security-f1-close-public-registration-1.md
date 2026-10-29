# SECURITY-F1-CLOSE-PUBLIC-REGISTRATION-1

**Status:** IMPLEMENTED AND VERIFIED LOCALLY. Not pushed, not merged, not deployed.

**Type:** `SECURITY_FIX`. **Base:** `19a2ed1c`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`). Audit item F-1,
decision D1 "DISABLE" (`docs/audit/full-system-audit-2026-10-27/POST-AUDIT-EXECUTION-ROADMAP.md`,
PR-02).

No migration, no permission, no role change.

## 1. Finding

Laravel Breeze left `GET /register` and `POST /register` enabled. `RegisteredUserController::store()`
created an account and logged it in. It assigned no role, so the account held no permission —
but the only thing between that account and patient data was every authenticated route
remembering to authorize its own action. Production holds no role-less account; nothing links to
the page except an unused welcome view behind `Route::has('register')`.

## 2. Change

- `routes/auth.php`: the two routes removed.
- `RegisteredUserController` and `resources/views/auth/register.blade.php` deleted (unreachable).
- `RegistrationTest` (which asserted that self-registration works) replaced by
  `PublicRegistrationClosedTest` (5):
  1. no route named or addressed `register`;
  2. `GET /register` is 404;
  3. `POST /register` is 404, nobody is logged in, no user row is created;
  4. login and password reset still render;
  5. **role-less sweep** — every parameterless authenticated GET route, requested as an
     authenticated user with no role, reaches a 2xx only for `profile`, `notifications` and
     `confirm-password`, and never a 5xx. This is the permanent guard for D1: whatever creates
     an account in future, an account without a role reaches no data.
- Declared mandatory; `PublicRegistrationClosed` token in both critical-gate variants.

## 3. Verification

Role-less sweep on this tree (MAIN branch seeded, as in production), 232 routes: 224 refused
(403), 3 self-scoped pages (200), 3 redirects (online-context selector, an export without
parameters, email verification), 1 feature-flagged 404, 1 validation 422.

Mutations:

| Mutant | Result |
|---|---|
| registration routes, controller and view restored | killed (3 tests) |
| an authenticated route returning data without authorization | killed |
| `permission:manage users` removed from the user routes | survives — **equivalent**: `UserController` also authorizes through `UserPolicy`, so the route stays refused |

Regression: Auth, PWA, AccessControl and Profile suites; `npm run test:js` (the service-worker
test still lists `/register` as a URL that must never be cached).

## 4. Production check after deploy

`https://daengtisia.online/register` returns 404 for `GET` and `POST`; login and password reset
return 200.

## 5. Rollback

`git revert` + redeploy.

## 6. Noted, not changed

`GET rme/online-context/rooms` validates its `branch_id` input before anything else and returns
room id, name and code (no clinical data) to any authenticated user who supplies one —
verified with a role-less user on this tree. It is
recorded in the Phase 1 device-lock record; it is outside this fix.
