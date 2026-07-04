# CACHE-1 Evidence — Cache Strategy, Redis Readiness & Invalidation Governance

**Status:** GO — PR merged, GO tag pushed, CI passed, VPS deployed, evidence captured, and smoke passed.

| Field | Value |
| --- | --- |
| PR | [#174](https://github.com/makemesick91-code/new_lab_app/pull/174) — merged into `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Merge commit | `5449b76620f4c571b9e5c68c484aff761efff159` (`5449b76`) |
| GO tag | `cache-1-cache-strategy-redis-readiness-invalidation-governance-go` |
| CI run | [28722092872](https://github.com/makemesick91-code/new_lab_app/actions/runs/28722092872) — `Foundation Evidence Gates` completed `success` |
| VPS previous HEAD | `8fdc92e` (`nsf-10-observability-backup-release-safety-hardening-go`) |
| VPS deployed HEAD | `5449b76`, exact tag `cache-1-cache-strategy-redis-readiness-invalidation-governance-go` |
| Backup path | `storage/app/backups/deploy/pre_cache1_20260704-225254.sql` |
| Backup size | `593K` (`606803` bytes) |
| Node/npm | Node `v20.20.2`, npm `10.8.2` |
| Cache governance | GO — 15 checks passed, 0 warnings, 0 errors; allowed categories 7, denied categories 11 |
| Redis readiness | Ready by governance only; production runtime remains disabled (`Cache store: file`, `Redis runtime enabled: no`) |
| Release evidence | GO — VPS profile 12/12 checks passed, required artifacts present |
| Release safety | GO — VPS profile 11/11 checks passed |
| Automated smoke | GO — command readiness and HTTP `/login` probe healthy (`200`) |
| DQ/DMO/NSF/ROADMAP/FEATURE_FLAGS/Combined | GO — roadmap next recommended sprint `QUEUE-1`, feature flags 16 registered/0 risky-enabled, cache governance GO |
| Next sprint | QUEUE-1 |

## Local validation

Executed on local branch `feature/cache-1-cache-strategy-redis-readiness-invalidation-governance` before PR merge:

- `php artisan release:evidence-capture --profile=ci` — GO
- `php artisan release:evidence-check --profile=ci` and `--json` — GO, 6/6 passed
- `php artisan test --filter=Cache1GovernanceIntegrationTest` — PASS, 9 tests / 16 assertions
- `php artisan test --filter=CacheGovernance` — PASS with 1 expected skip, 13 tests / 77 assertions
- `php artisan test --filter=CacheInvalidation` — PASS, 4 tests / 62 assertions
- `php artisan test --filter=ReleaseEvidenceFoundationTest` — PASS, 8 tests / 47 assertions
- `php artisan test --filter=ReleaseSafetyEvidenceClosureTest` — PASS, 7 tests / 15 assertions
- `php artisan test` — PASS, 3859 passed, 8 skipped, 17113 assertions
- `./vendor/bin/pint --dirty` — PASS
- `npm run build` — PASS
- `php artisan route:list` — PASS, 379 routes
- `git diff --check` — PASS

## CI / VPS

CI evidence:

- PR #174 merged at `2026-07-04T22:52:04Z`.
- CI run `28722092872` completed successfully.
- PR checks: `NSF-R012 Quality Gate` PASS, `NSF-R011 Critical Test Gate` PASS, `NSF-9 Release Safety & Automated Smoke Gate` PASS, `NSF-10 Release Evidence Gate` PASS.
- `NSF-R011 Full Suite Gate` was skipped on PR by workflow design.

VPS deploy evidence:

- Deploy target: `/var/www/asia-dental-lab-v2`.
- `git checkout cache-1-cache-strategy-redis-readiness-invalidation-governance-go` deployed `5449b76`.
- `composer install --no-dev --prefer-dist --optimize-autoloader` completed.
- `npm ci` completed.
- `npm run build` completed.
- `php artisan migrate --force` completed; `php artisan migrate:status` shows latest migrations ran.
- `php artisan foundation:backup-verify --path=storage/app/backups/deploy/pre_cache1_20260704-225254.sql` — GO, 9/9 passed.
- `php artisan foundation:cache-governance-check` — GO, 15/15 passed.
- `php artisan release:evidence-check --profile=vps` — GO, 12/12 passed.
- `php artisan foundation:release-safety-check --profile=vps` — GO, 11/11 passed.
- `php artisan architecture:foundation-governance-summary` — Combined GO.
- `php artisan release:automated-smoke --base-url=http://127.0.0.1` — GO, HTTP `/login` probe returned healthy `200`.
- `curl -I http://127.0.0.1` — `302 Found` to `/login`.
- `php artisan about` — environment `pilot`, debug OFF, cache driver `file`, database `pgsql`, config/routes/views/events cached.
- `php8.3-fpm` active; `nginx` active.
- No deploy-time Laravel log entries were found for `2026-07-04 22:5x`.

## Warnings / risks

- CACHE-1 intentionally does not enable Redis runtime caching on VPS; Redis remains a readiness/governance target for a later operational sprint.
- `architecture:foundation-governance-summary` still reports local-profile `RELEASE_SAFETY` and `RELEASE_EVIDENCE` as WATCH when run without VPS profile; this is expected and non-blocking because VPS profile release evidence is GO.
- Tail of `storage/logs/laravel.log` still contains an older `2026-06-30` file-cache permission error. No matching deploy-time entries were found after CACHE-1 deployment, and storage/bootstrap cache ownership was reset during deploy.
