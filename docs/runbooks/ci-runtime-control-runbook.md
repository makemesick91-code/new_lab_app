# CI Runtime Control Runbook (CICD-CTRL-1)

**Purpose:** operate and audit the safe CI runtime control that decides which
Foundation Evidence Gates run per change set, without weakening any required
gate.

**Owner:** whoever ships to `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`.
**Review cadence:** whenever the CI workflow, the classifier, or the gate
contract changes.

## When to use

- A PR looks like it ran too few / too many gates and you want to know why.
- You changed the classifier or workflow and must confirm the safety contract.
- You need to force the full suite before a GO tag / release.

## Safe commands

Read-only, non-destructive:

```bash
# Governance posture (must be GO before merge / deploy).
php artisan foundation:ci-runtime-control-check --strict
php artisan foundation:ci-runtime-control-check --json

# Preview the gate decision locally for the current branch vs base.
scripts/ci/resolve-gates.sh --base origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report --head HEAD

# Preview from an explicit file list (one path per line).
scripts/ci/resolve-gates.sh --changed-files-file /tmp/files.txt --json

# Enterprise gate must also stay GO (runtime control depends on it).
php artisan foundation:cicd-enterprise-gate-check --strict
```

## How the CI job reads it

The `classify` job runs first and exposes outputs
(`gate_profile`, `run_critical_tests`, module flags, `run_full_suite`).
Downstream jobs consume `needs.classify.outputs.*`:

- `quality_gate`, `release_safety_gate`, `nsf10_release_evidence_gate` — always run.
- `critical_test_gate` — the migration + governance audit steps always run;
  the expensive Pest filter step is skipped only when
  `run_critical_tests == 'false'` (docs-only).
- `selective_module_gate` — additive module suites for the changed module.
- `full_suite_gate` — schedule / manual dispatch / push-to-base fallback.

## Forcing the full suite

- Manual: run the **Foundation Evidence Gates** workflow via *Run workflow*
  with `run_full_suite = true`.
- Automatic: it runs weekly (Sunday 02:00 UTC) and on push-to-base.
- Always run it before creating a GO tag.

## Forbidden / unsafe changes (blocked by governance)

- Do NOT add `paths-ignore` or blanket path filters to the workflow — gate
  selection must be the audited classifier, not opaque path globs.
- Do NOT widen `skip_critical_profiles` beyond `docs_only` without a reviewed
  sprint and new tests; `foundation:ci-runtime-control-check` fails otherwise.
- Do NOT gate the always-on security / governance / release-safety / evidence
  jobs on the classifier.
- Do NOT make CI green by skipping failures or hiding them.
- Never run destructive database rebuild/wipe/reset operations in CI or deploy;
  migrations stay additive and use `migrate --force` only.

## Troubleshooting

- **Classifier returned `unknown_high_risk` unexpectedly:** the base ref was
  unreachable or the diff was empty. That is the safe fallback (everything
  runs); check the `classify` job log `classification_reason`.
- **A code PR skipped critical tests:** it should never happen. Any non-Markdown
  file forces `run_critical_tests=true`. Inspect the `classify` step summary and
  file `foundation:ci-runtime-control-check` output; treat as a defect.
- **Governance command not GO:** read the non-passing checks it prints; the most
  common cause is a workflow edit that dropped a required marker or an always-on
  job, or an ENT-10 enterprise-gate regression.

## Evidence / rollback

- Evidence: `classify` job summary, `selective_module_gate` artifact, and the
  existing NSF gate artifacts.
- Rollback: revert the CICD-CTRL-1 changes (classifier script, workflow edits,
  config/command/scanner/service). This only affects CI gate selection; no app
  runtime, route, schema, or data behaviour is involved.
