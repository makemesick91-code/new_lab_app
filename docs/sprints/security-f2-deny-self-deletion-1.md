# SECURITY-F2-DENY-SELF-DELETION-1

**Status:** IMPLEMENTED AND VERIFIED LOCALLY. Not pushed, not merged, not deployed.

**Type:** `SECURITY_FIX`. **Base:** `19a2ed1c`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`). Audit item F-2,
decision D2 "DENY" (`docs/audit/full-system-audit-2026-10-27/POST-AUDIT-EXECUTION-ROADMAP.md`,
PR-03).

No migration, no permission, no role change.

## 1. Finding

`DELETE /profile` (Breeze scaffolding) let any authenticated user — doctors included — delete
their own account. `ProfileController::destroy()` called `forceDelete()`, unlike administrator
deletion, which soft-deletes. A hard delete nulls every attribution column that names the user
(audit actor, record author and finalizer, consent signer, legacy publish and void actor, device
approver) and cascade-deletes trail tables. Production holds no self-deleted account.

Administrative deactivation already exists and is enforced: `ActiveAccountResolver` refuses
login, `EnsureAccountIsActive` ends live sessions and remember-me logins
(`ActiveAccountSessionTest`, 37 cases, already selected by the critical gate).

## 2. Change

- `routes/web.php`: `profile.destroy` removed.
- `ProfileController::destroy()` and its four now-unused imports removed. It also released the
  doctor session lease and the online context; nothing is deleted any more, so there is nothing
  to release. `DoctorSessionLease::RELEASE_ACCOUNT_DELETED` stays — historical lease rows carry it.
- `profile/edit.blade.php`: the delete section removed; `profile/partials/delete-user-form`
  deleted.
- `EnsureRmeOnlineContext`: the `profile.destroy` exemption removed (the route no longer exists).
- `ProfileTest`: the two deletion cases removed; `AccountSelfDeletionDeniedTest` (2): `DELETE
  /profile` is 405, the user stays signed in and the row is byte-identical and not soft-deleted;
  the profile page offers no deletion and the route name does not exist. Both fail on the base.
- Declared mandatory; `AccountSelfDeletionDenied` token in both critical-gate variants.

## 3. Verification

Auth, Profile, ActiveAccountSession and lease-invalidation suites: 103 passed.

## 4. Production check after deploy

`DELETE https://daengtisia.online/profile` with a valid session returns 405; the profile page has
no delete section.

## 5. Regression

`--filter='OnlineContext|DoctorAccess|DailyBranchContext|BranchChange'` (the middleware exemption
removal): 588 passed, 9 skipped (PostgreSQL-only lock tests on SQLite), 0 failed.

## 6. Rollback

`git revert` + redeploy.

## 7. Follow-up, not in scope

D18 / PR-11: an administrator "delete" soft-deletes the user, and every record display that names
the user then shows nothing (195 relations, none loads soft-deleted users). 0 rows affected today.
