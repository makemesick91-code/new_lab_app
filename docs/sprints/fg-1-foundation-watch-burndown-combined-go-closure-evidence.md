# FG-1 Foundation WATCH Burn-down & Combined Governance GO Closure — Evidence

Status: **GO — DEPLOYED**

## Sprint metadata

| Field | Value |
| --- | --- |
| Sprint | FG-1 |
| Feature branch | `feature/fg-1-foundation-watch-burndown-combined-go-closure` |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| PR | [#167](https://github.com/makemesick91-code/new_lab_app/pull/167) |
| Merge commit | `69cc953` |
| GO tag | `fg-1-foundation-watch-burndown-combined-go-closure-go` |

## VPS deploy

| Field | Value |
| --- | --- |
| VPS path | `/var/www/asia-dental-lab-v2` |
| Previous HEAD | `765dffe` |
| Deployed HEAD | `69cc953` |
| Deployed tag | `fg-1-foundation-watch-burndown-combined-go-closure-go` |
| DB backup | `storage/app/backups/deploy/pre_fg1_20260704-061320.sql` (585K) |
| Migrate | Nothing to migrate |
| npm build | OK (Node EBADENGINE warning non-blocking) |
| Smoke | `php artisan about` OK; `curl -I http://127.0.0.1` → 302 (expected) |

## Local validation

```
php artisan test --filter=FoundationGovernance  → 11 passed
php artisan test --filter=Fg1                  → 2 passed
php artisan test --filter=Dq31                 → passed (subset)
./vendor/bin/pint --dirty                      → passed
```

## DQ chain (VPS)

| Audit | Result |
| --- | --- |
| DQ-1 | GO |
| DQ-2 | GO |
| DQ-3 | GO |
| DQ-3.1 | GO (0 ambiguous rows) |

## Foundation governance (VPS)

| Section | Raw | Effective | Blocking |
| --- | --- | --- | --- |
| NSF | WATCH | GO | 0 |
| DMO | WATCH | GO | 0 |
| DQ | GO | GO | 0 |
| **Combined** | **GO** | — | 0 |

### NSF non-blocking causes (VPS)

- NSF-R011 — evidence_only: full suite gate
- NSF-R012 — evidence_only: build/pint gate
- NSF-M001 — deferred_backlog (Engineering, NSF-7)
- NSF-M002 — deferred_backlog (Platform, NSF-7)

Note: NSF-R009 passed on VPS with observability enabled (pg_stat available).

### DMO deferred backlog (VPS)

- DMO-M001 — net_revenue blocked (DMO-3)
- DMO-M003 — receivable_aging_bucket (DMO-3)
- DMO-M006 — treatment/tariff multi-branch (DMO-3)
- DMO-M007 — pod_count blocked (DMO-3)

## Warnings / risks

- Raw NSF/DMO remain WATCH for transparency; Combined GO is valid because all warnings are classified non-blocking.
- Deferred DMO metrics require DMO-3/NDA sprint before canonical expansion.
- NSF evidence gates (R011/R012) must be re-run and documented per sprint before future GO tags.

## Final decision

**GO** — DQ chain GO, Combined Foundation GO, VPS deploy and smoke pass. Deferred backlog documented with owner/risk/target sprint.
