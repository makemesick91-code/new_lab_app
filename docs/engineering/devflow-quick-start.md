# DEVFLOW-1 — Quick Start

Safe sprint acceleration: shorter prompts, faster audits, focused tests,
consistent releases. Nothing here weakens security, branch isolation, ledger
correctness, required tests, or release safety.

## Minimum prompt

A future sprint prompt only needs:

```
Implement FIX-XYZ:
- type: HOTFIX
- module: Lab
- objective: fix notification destination
- acceptance criteria:
  - Admin Lab opens the notification
  - no permission expansion

Use the canonical Sprint Runtime Template and manifest.
```

Everything recurring (base branch, architecture, branch isolation, ledger,
RBAC, tests, backup, deploy, smoke, rollback, GO/WATCH/NO-GO, evidence) is
inherited from `docs/engineering/sprint-runtime-template.md` + the sprint type.

## First question of every sprint

**Is the `GLOBAL TEMPORARY FULL-SUITE POLICY` ACTIVE?**
(`docs/governance/global-temporary-full-suite-policy.md`, rule `.cursor/rules/107-global-temporary-full-suite-policy.mdc`.)

- **YES** → **do not run the Full Suite.** Targeted + dependency-aware
  regression → required non-Full-Suite CI → merge → deploy → smoke → rules →
  cleanup → close **WATCH — PENDING CONSOLIDATED FULL SUITE**, GO tag deferred.
- **NO** → normal cadence.

Either way the final report states `FULL SUITE STATUS`,
`FULL SUITE EXECUTION COUNT`, and `GLOBAL TEMPORARY POLICY ACTIVE = YES/NO`.

## New sprint

```
php artisan sprint:new FIX-XYZ --type=HOTFIX --module=Lab --runtime --deploy
php artisan sprint:prepare              # read-only preflight; mutates nothing
php artisan sprint:manifest-check       # schema + type + diff consistency
php artisan sprint:audit-plan           # audit level + inspection checklist
php artisan sprint:scope-audit --strict # one sprint = one outcome
```

## Test

```
php artisan sprint:test-plan            # focused filters + CI escalation
php artisan sprint:test --focused       # run the focused plan
php artisan sprint:test --all-required  # pint + diff-check + focused + escalation
```

> **DANGER — the most likely accidental trigger.** `php artisan sprint:test
> --all-required` (and `--regression`) appends a `pest-full-required` step that
> runs **bare `php artisan test` — the entire repository** — whenever
> `escalate_full_suite` is true. Escalation is **fail-closed**: a changed file
> that matches an escalation category *or no category at all* sets it, and a
> sprint manifest matches nothing. **While this policy is ACTIVE, do not run
> `sprint:test --all-required` or `--regression`.** Use instead:
>
> ```bash
> php artisan sprint:test-plan            # read-only; shows the plan + escalation
> php artisan sprint:test --focused       # focused filters only, never escalates
> vendor/bin/pint --dirty --test          # covered by --all-required, run it directly
> git diff --check                        # same
> php artisan test --filter='<targeted regression>'
> ```


## Release

```
php artisan sprint:release-check --ci-passed=true   # GO/WATCH/NO-GO, creates nothing
scripts/sprint-release.sh --dry-run                 # verify, mutate nothing
scripts/sprint-release.sh --apply --tag             # deploy + smoke, then GO tag
php artisan sprint:evidence --write --decision=GO   # real-value evidence pack
```

## Governance

```
php artisan foundation:devflow-check --strict           # foundation intact + safe
php artisan foundation:shared-service-audit --strict    # canonical foundations reused
```

## Troubleshooting

- **`sprint:prepare` NO-GO on branch** — you are on `main`/`master`; create a
  feature branch.
- **`sprint:manifest-check` NO-GO on a flag** — a changed file contradicts an
  impact flag (e.g. a migration with `schema_change=false`); fix the flag.
- **`sprint:test-plan` shows unexpected full-suite escalation** — a changed file
  matched an escalation category or no category at all (fail-closed); expected
  for shared-foundation/CI/schema/security changes.
- **graphify CLI absent** — impact audit falls back to `rg` + `route:list`;
  `sprint:prepare` reports this honestly as WATCH, not GO.
- **A Full Suite job appears on a merge you just pushed** — expected: the
  push-to-base trigger. Do not panic and do not edit CI. Read §6.1 of the policy
  document for the only permitted handling and the proof it requires.
