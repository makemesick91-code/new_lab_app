# PR-C — all trusted tablet authorization

> Third and last child of **DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1**.
> Architecture: `docs/architecture/doctor-device-bulk-authorization.md`.
> Rules: `.cursor/rules/155-doctor-device-bulk-authorization.mdc` (DBA-R001..R012).
> **The parent GO tag is NOT created here.**

## Scope

Close the `(eligible doctor x eligible trusted clinic device)` authorization
matrix in one reviewed action, and make it provable that closing it touched
nothing else.

Deliberately **out** of scope: branch-lock logic, device registration, WebAuthn
credential creation or revocation, session leases, the pilot cohort, enforcement
flags, and any form of automatic authorization.

## Runtime authority

| | |
|---|---|
| Base | `1010d9fb14f5ee4563bd27db423631ebe655a5dc` (PR-B merge) |
| Base tree | `99ed382d281045fb870df15190198c107528385d` |
| Production at start | same commit, same tree, verified on `srv1730088` |
| Flags at start | `doctor.single_active_session=false`, `doctor.branch_lock=true` |
| Pilot cohort | `[9, 15, 18]`, unchanged |
| Global enforcement | `false`, unchanged |

## Files

**New runtime**

- `app/Modules/DoctorAccess/Services/DoctorDeviceBulkAuthorizationService.php`
- `app/Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationPlan.php`
- `app/Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationPair.php`
- `app/Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationOutcome.php`
- `app/Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationRefusal.php`
- `app/Console/Commands/DoctorDeviceBulkAuthorizeCommand.php`

**Edited**

- `config/doctor_access.php` — a `bulk_authorization` ceiling block, literal ints,
  no `env()`, consistent with that file's stated rule.
- `config/ci_runner.php` — the four suites declared mandatory.
- `tests/Feature/DoctorAccess/helpers.php` — `dba*` fixtures. The prefix matters:
  this directory's helpers live in the global namespace beside roughly a hundred
  others, several unguarded, so a name collision is a fatal redeclare.
- `tests/Feature/DoctorAccess/DoctorSessionLeaseGovernanceTest.php` — the new
  command appended to `glgSourceFiles()`. Commands live flat in
  `app/Console/Commands`, outside that file's recursive module walk, so without
  this a console surface that writes authorizations would escape every scan in
  it — including the one proving no surface arms enforcement.

**No migration. No route. No policy. No permission. No seeder step.**

## The two decisions worth reviewing

### Reusing `approve()` rather than writing a second approval path

`approve()` can promote a `pending_approval` device to ACTIVE. PR-C must not
mutate devices. The alternative — a private `approveWithoutDeviceAdmission()` —
would have to re-implement six guards, its own lock, its own re-validation and
its own audit trail, and this estate already carries the scar tissue of exactly
that: three divergent doctor-set predicates that disagree.

Reuse is safe because eligibility pins devices to ACTIVE and **re-asserts it
under the row lock `approve()` re-acquires**, making the promotion branch
unreachable rather than merely unlikely. Three tests pin the consequence, the
guard, and — most importantly — the premise that `active -> pending_approval` is
not a reachable transition anywhere in `app/`.

### A plan digest rather than a prompt

The sprint asked the tool to stop and show the operator the exact delta. It does,
but not with `confirm()`: no command in this repository prompts, and a Laravel
prompt auto-answers with its default under SSH, a deploy script or CI. At fleet
scale that is an unreviewed write.

`--apply --confirm-plan=<digest>` binds consent to the delta instead of to a
moment, and estate drift between preview and apply fails closed.

## Local evidence

| Suite | Result |
|---|---|
| `DoctorDeviceBulkAuthorizationPlanTest` | 18 passed, 66 assertions |
| `DoctorDeviceBulkAuthorizationApplyTest` | 12 passed, 56 assertions |
| `DoctorDeviceBulkAuthorizationCommandTest` | 19 passed |
| `DoctorDeviceBulkAuthorizationGovernanceTest` | 10 passed, 389 assertions |

`sprint:manifest-check` GO. Regression across `tests/Feature/DoctorAccess`,
`tests/Feature/DoctorDevice` and `tests/Feature/DoctorDeviceWebAuthn` recorded in
the pull request.

## Full Suite

```
FULL_SUITE_EXECUTED=NO
FULL_SUITE_RESULT=SKIPPED
FULL_SUITE_CLAIMED_PASS=NO
```

Skipped for this child by owner instruction. One Full Suite runs after all three
children are merged, deployed and production-verified, and it gates the **parent**
tag only. A child skip must never become a parent pass.

## Deploy

No migration, no seeder, no `permission:cache-reset`. Deploy with
`scripts/deploy-vps-runner.sh`, executed **on** `srv1730088`.

Deployment of the tool is not authorization to change the matrix. After deploy,
run the **dry run only** and stop:

```
php artisan doctor:device-bulk-authorize --actor=<id>
```

Applying requires a fresh explicit operator approval of that exact delta, and
then:

```
php artisan doctor:device-bulk-authorize \
    --actor=<id> --reason="<why>" --apply --confirm-plan=<digest>
```

Re-run the dry run afterwards: `PROPOSED_CREATE_COUNT=0` is the production
idempotence proof.

## Rollback

There is no delete and no soft delete, and inbound `RESTRICT` foreign keys make
used rows permanently undeletable. Rollback is status-based: revoke through the
existing route, with a mandatory reason. The dry-run default and the digest gate
are what make that irreversibility acceptable.
