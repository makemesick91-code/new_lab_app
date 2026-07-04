# NSF-7 Evidence Gate Automation — Sprint Evidence

## Sprint status

**GO** — PR merged, GO tag pushed, VPS deployed, smoke pass.

## Delivery

| Item | Value |
| --- | --- |
| Feature branch | `feature/nsf-7-evidence-gate-automation-r011-r012-ci` |
| PR | [#169](https://github.com/makemesick91-code/new_lab_app/pull/169) |
| Merge commit | `c984973` |
| GO tag | `nsf-7-evidence-gate-automation-r011-r012-ci-go` |
| Workflow | `.github/workflows/foundation-evidence-gates.yml` |

## GitHub Actions

| Run | Event | Result |
| --- | --- | --- |
| [28698180123](https://github.com/makemesick91-code/new_lab_app/actions/runs/28698180123) | PR #169 | **pass** — NSF-R012 Quality Gate, NSF-R011 Critical Test Gate |
| [28698277520](https://github.com/makemesick91-code/new_lab_app/actions/runs/28698277520) | push merge | in progress / full_suite_gate on base push |

PR checks (pre-merge):

- NSF-R012 Quality Gate: **pass** (1m8s)
- NSF-R011 Critical Test Gate: **pass** (2m45s)
- NSF-R011 Full Suite Gate: skipped on PR (by design)

## CI artifacts

- `storage/ci-evidence/nsf-r012-build-pint.txt`
- `storage/ci-evidence/nsf-r011-critical-tests.txt`
- `storage/ci-evidence/foundation-summary.txt`
- `storage/ci-evidence/dq-audits.txt`
- Full suite artifact on push/schedule/dispatch only

## VPS deploy

| Item | Value |
| --- | --- |
| Previous HEAD | `612d65b` (no exact tag) |
| Deployed HEAD | `c984973` |
| Deployed tag | `nsf-7-evidence-gate-automation-r011-r012-ci-go` |
| DB backup | `storage/app/backups/deploy/pre_nsf7_20260704-065303.sql` (587K) |
| Migrate | Nothing to migrate |
| npm build | pass (Node 18 EBADENGINE warning — non-blocking) |
| Smoke | HTTP 302, routes listed, no 500 |

## Governance (VPS)

| Check | Result |
| --- | --- |
| DQ-1 | GO |
| DQ-2 | GO |
| DQ-3 | GO |
| DQ-3.1 | GO |
| DMO | GO |
| NSF raw | WATCH (1 warning: NSF-R009 pg_stat deferred without --include-observability) |
| NSF effective | GO |
| NSF-R011 | **automated_ci_gate** — workflow + script present; rule status passed |
| NSF-R012 | **automated_ci_gate** — quality_gate job pass |
| Combined Foundation | **GO** |

## Local validation

```
php artisan test --filter='FoundationGovernance|Nsf7' — 21 passed
./vendor/bin/pint --dirty — pass
```

## Warnings / risks

- VPS Node 18 vs Tailwind oxide >=20 warning — build still succeeds; consider Node 20 upgrade on VPS.
- NSF-R009 raw WATCH on VPS until `architecture:nsf-governance-check --include-observability` run — classified `environment`, non-blocking.
- Full suite not on every PR — weekly/dispatch/push to base only.

## Final decision

**GO** — NSF-7 objectives met. NSF-R011/R012 automated in CI. NSF-M001/M002 closed. DQ chain and DMO remain GO. Combined Foundation GO.
