# PR-C production evidence — all trusted tablet authorization

> DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1, third and last child.
> Sprint: `docs/sprints/doctor-access-pr-c-bulk-device-authorization.md`.
> Architecture: `docs/architecture/doctor-device-bulk-authorization.md`.
> **The parent GO tag is NOT created here.**

## Runtime authority

| | |
|---|---|
| Candidate SHA | `21b4639514aa8f83d7c1f49648a342e509e9a4bd` |
| Candidate tree | `6f99364bc0699a086dfe66ec2ed9559b012dd195` |
| Merge SHA (PR #406, squash) | `d7d645cbb9a83403394c9585b199d0a806a40f83` |
| Merge tree | `6f99364bc0699a086dfe66ec2ed9559b012dd195` |
| Production HEAD after deploy | `d7d645cbb9a83403394c9585b199d0a806a40f83` |
| Production tree after deploy | `6f99364bc0699a086dfe66ec2ed9559b012dd195` |

The candidate tree, the merge tree and the deployed tree are the **same object**.
Nothing entered production that was not reviewed and gated.

## CI

All eight checks terminal on the merged candidate.

| Gate | Result |
|---|---|
| CICD-CTRL Gate Classifier | pass — profile `unknown_high_risk` |
| NSF-R012 Quality Gate | pass |
| Phase 3 Android Clinic App Gate | pass |
| CICD-CTRL Selective Module Gate | pass (50m18s) |
| NSF-R011 Critical Test Gate | pass (1h11m17s) |
| NSF-9 Release Safety & Automated Smoke | pass |
| NSF-10 Release Evidence Gate | pass |
| NSF-R011 Full Suite Gate | **SKIPPED** |

```
FULL_SUITE_EXECUTED=NO
FULL_SUITE_RESULT=SKIPPED
FULL_SUITE_CLAIMED_PASS=NO
```

### One real defect, found by CI and not by the local suite

The first critical-gate run was `1 failed, 3866 passed`, and the failure was
PR-C's own. The unique-index test violates the constraint on purpose to prove it
exists, then queries again. **PostgreSQL aborts the entire transaction on a
failed statement**, so that next query died with `SQLSTATE[25P02]`. SQLite does
not, so the test was green locally and red on the only engine production runs.

Fixed by wrapping the violating insert in a nested transaction — Laravel
implements that as a `SAVEPOINT`, so the rollback restores a usable connection on
both engines. Verified against a throwaway `postgres:16.14` container: **all 67
PR-C tests pass on PostgreSQL and on SQLite**.

The surrounding directories were then run on PostgreSQL too. `DoctorAccess` +
`DoctorDevice` gave 749 passed / 8 failed, and those 8 are **byte-identical to
the unmodified base tree on the same engine**, both directions of the diff empty.
They do not appear in CI at all, so they are an artifact of the throwaway local
environment rather than code.

## Deploy

Via `scripts/deploy-vps-runner.sh` on `srv1730088`, detached so it survives a
dropped SSH pipe.

```
DEPLOY_EXIT_CODE=0
DEPLOY_STATUS=DEPLOY RUNNER OK
DEPLOY_HEAD_TARGET_MATCH=YES
MIGRATIONS_PENDING=0
```

No migration, no seeder, no `permission:cache-reset` — PR-C mints no permission.
`manage_doctor_device_authorizations` was verified present on production and
granted to 2 roles **before** the deploy, so the gate could not land inert.

The protected-table digests were byte-identical before and after the deploy: the
deploy itself mutated no domain row.

## Production dry run

Run as **user 11 Jene Monika (Supervisor RME)** — the account that holds the
permission directly, not through the Super Admin bypass.

```
MODE=DRY_RUN
ELIGIBLE_DOCTOR_COUNT=15        ELIGIBLE_DEVICE_COUNT=3
TARGET_PAIR_COUNT=45            EXISTING_ACTIVE_TARGET_PAIRS=4
MISSING_TARGET_PAIRS=41         PENDING_TARGET_PAIRS=0
DUPLICATE_ACTIVE_PAIRS=0        CONFLICTING_TARGET_PAIRS=0
PROPOSED_CREATE_COUNT=41        PROPOSED_REACTIVATE_COUNT=0
PROPOSED_SKIP_COUNT=4           FINAL_EXPECTED_AUTHORIZATIONS=45
UNREACHABLE=0                   PLAN_DIGEST=2b68382ea319
BRANCH_MUTATIONS=0  DEVICE_MUTATIONS=0  CREDENTIAL_MUTATIONS=0
PILOT_SCOPE_MUTATIONS=0  FEATURE_FLAG_MUTATIONS=0
```

### Zero-write proof

Protected-table digests taken immediately before and immediately after the dry
run were identical:

```
AUTHORIZATION_ROW_COUNT_UNCHANGED=YES
AUDIT_ROW_COUNT_UNCHANGED=YES
DEVICE_ROW_COUNT_UNCHANGED=YES
CREDENTIAL_ROW_COUNT_UNCHANGED=YES
BRANCH_STATE_UNCHANGED=YES
```

### Reconciled against an independent computation

The plan was **not** taken on trust. The same matrix was computed directly in SQL
against production before the tool ever ran, and the 41 proposed pairs were
diffed against the tool's 41: **both directions empty, 41 of 41 matching.** Every
counter agreed.

## Owner approval

`APPROVE_PR_C_PRODUCTION_APPLY=YES`, given against the exact write-set above —
15 doctors, 3 devices, 45 target pairs, 4 existing, **41 new**, digest
`2b68382ea319` — with the per-doctor breakdown and both caveats stated: the
grant is genuinely cross-branch, and there is no delete, only revoke.

## Apply

```
APPLY_OPERATOR=user 11 Jene Monika (Supervisor RME)
APPLY_COMMAND_MODE=--apply --confirm-plan=2b68382ea319

APPLIED_CREATED=41
APPLIED_APPROVED_EXISTING=0
REFUSED=0
ORPHAN_PENDING_ROWS_LEFT=0
EXIT_CODE=0
```

The applied write-set matched the approved plan exactly. Nothing was refused, and
no PENDING row was left in anybody's approval inbox.

## Post-apply reconciliation

Recomputed from production, independently of the tool:

```
TARGET_PAIR_COUNT=45            EXISTING_ACTIVE_TARGET_PAIRS=45
MISSING_TARGET_PAIRS=0          BLOCKED_TARGET_PAIRS=0
DUPLICATE_ACTIVE_PAIRS=0        ACTIVE_ON_INELIGIBLE_DEVICE=0
PENDING_INBOX_TOTAL=0
FINAL_ACTIVE_TARGET_PAIRS = EXPECTED_TARGET_PAIRS = 45
```

Every eligible doctor now holds exactly one ACTIVE authorization on every
eligible trusted clinic tablet.

### Production idempotence proof

A second dry run over the closed matrix:

```
PROPOSED_CREATE_COUNT=0
PROPOSED_REACTIVATE_COUNT=0
MISSING_TARGET_PAIRS=0
PROPOSED_SKIP_COUNT=45
PAIR lines: 0
EXIT_CODE=0
```

The digest changed from `2b68382ea319` to `6e30fef2a629`, which is the gate
working: the approved delta no longer exists, so the token that authorised it
cannot be replayed.

## Identity and domain non-mutation

Protected-table digests, pre-deploy versus post-apply — **all eight identical**:

```
UNEXPECTED_DEVICE_CHANGES=0
UNEXPECTED_CREDENTIAL_CHANGES=0
UNEXPECTED_BRANCH_CHANGES=0
UNEXPECTED_SESSION_LEASE_CHANGES=0
UNEXPECTED_PILOT_SCOPE_CHANGES=0
UNEXPECTED_GLOBAL_FLAG_CHANGES=0
```

Intended changes only:

| Table | Before | After | Delta |
|---|---|---|---|
| `mst_doctor_device_authorizations` | 6 | 47 | +41 |
| `sys_audit_logs` | 684 | 767 | +83 |

83 = 41 `DOCTOR_DEVICE_AUTHORIZATION_PENDING` + 41 `..._APPROVED` + 1
`DOCTOR_DEVICE_BULK_AUTHORIZATION_RUN`. **All 83 carry `performed_by = 11`; zero
are unattributable** — the console-actor trap this design was built against.

All 41 new rows carry `request_source = admin`, distinguishing a bulk-provisioned
grant from a doctor's own login tap. PR-C is that constant's first consumer.

### Pre-existing rows untouched

| id | doctor | device | status | source | unchanged |
|---|---|---|---|---|---|
| 1 | 21 | 1 | revoked | app_login | yes |
| 2 | 21 | 3 | active | app_login | yes |
| 3 | 21 | 4 | rejected | app_login | yes |
| 4 | 17 | 5 | active | app_login | yes |
| 5 | 20 | 6 | active | app_login | yes |
| 6 | 21 | 6 | active | app_login | yes |

Authorization 6 is drg Karmila on an **ATG3** tablet while she is home-locked to
**SPN4** — the live proof that device trust and branch authority are independent
boundaries, and untouched by this run.

Session lease 8 remains **UNRELEASED and inert**. It was not released for
housekeeping, and `doctor.single_active_session` was not re-armed.

## Flags and scope

```
FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=false
FEATURE_DOCTOR_BRANCH_LOCK=true
ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID=18
ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=9,15
GLOBAL_ENFORCEMENT_ACTIVE=false
PILOT_COHORT=[9,15,18]
```

Unchanged. No doctor was added to the pilot because they now hold device
authorizations — those are separate boundaries and PR-C crossed neither.

## Health

```
/login=200  /health/live=200  /health/ready=200
APP_ENV=pilot  APP_DEBUG=false  MAINTENANCE=OFF  MIGRATIONS_PENDING=0

LOG_LINES  9114 -> 9114
LOG_ERRORS  163 ->  163
NEW_PR_C_ERRORS=0
NEW_DEVICE_AUTH_ERRORS=0
NEW_AUTHENTICATION_ERRORS=0
```

Watermark byte-identical across both the deploy and the apply.

## What this does not do

No doctor can log in from a new tablet merely because this ran. Each still needs
a device-bound credential enrolled at that tablet under their own biometric. No
mass login ceremony was performed and none is implied: 41 authorizations is 41
rows, not 41 proven logins.

## Parent

```
PARENT_FULL_SUITE_OBLIGATION=OPEN
PARENT_GO_TAGGED=NO
```
