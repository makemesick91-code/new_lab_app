# DaengtisiaMS — Release Definition of Done

The canonical, inherited release gate for every runtime sprint. Enforced by
`sprint:release-check`, `scripts/sprint-release.sh`, NSF-9/NSF-10, and
CICD-CTRL-1.

## Order (never reordered)

1. **Green required CI** — quality gate (NSF-R012), release-safety (NSF-9),
   release-evidence (NSF-10), and the critical/selective test gates.
   `sprint:release-check --ci-passed=true` confirms the evidence input.
2. **PR merged** into the base branch (squash). Record the merge commit.
3. **Release lock** acquired (`scripts/sprint-release.sh` file lock). No two
   releases run concurrently.
4. **Backup** the VPS PostgreSQL DB before any pull/migrate. Stop on backup
   failure. Record path + size.
5. **Deploy** via `scripts/sprint-release.sh --apply` (which drives
   `scripts/deploy-vps-runner.sh`: backup → deploy → `migrate --force` (if any)
   → cache rebuild → restart → smoke). **Never** `migrate:fresh` / `db:wipe`.
6. **Smoke** — automated smoke 7/7 + health endpoints 200 + guest routes 302
   (no 500). Deploy runner reports success only on `exit=0`.
7. **GO tag** — annotated, created **only after** deploy + smoke succeed, then
   pushed. `sprint:release-check` verifies no tag collision beforehand.
8. **Exact-match** — the GO tag points at the same commit locally, on the
   remote, and on the VPS HEAD.
9. **Evidence** — `sprint:evidence --write` renders real values; missing values
   are `NOT AVAILABLE`, never invented.
10. **Worktree clean**; rules + `CLAUDE.md` updated; `graphify update .` run.

## Hard blockers (NO-GO)

- Required CI not green, or CI evidence absent.
- Backup unavailable.
- Dirty worktree, or GO-tag collision.
- `deploy_required` but no deploy target, or missing rollback target.
- Manifest contradiction (impact flag vs actual diff).
- Any destructive DB reset in the release path.

## WATCH (proceed with a recorded caveat)

- Optional browser/tool integration unavailable.
- Non-canonical base branch (still not `main`).
- Acceleration target not fully met — foundation still safe.

## While the GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE

See `docs/governance/global-temporary-full-suite-policy.md` (rule mirror `.cursor/rules/107-global-temporary-full-suite-policy.mdc`).

- Step 1 "Green required CI" means the **required non-Full-Suite** gates:
  NSF-R012 Quality, CICD-CTRL Classifier, NSF-R011 Critical, CICD-CTRL Selective
  Module, NSF-9 Release Safety & Smoke, NSF-10 Release Evidence. **Steps 2–6 and
  9–10 are unchanged** — backup, deploy, smoke and evidence are not relaxed.
- Step 7 **GO tag is DEFERRED**: no final engineering GO tag for a fix whose
  governance requires a Full Suite before GO. The sprint closes at
  **`WATCH — PENDING CONSOLIDATED FULL SUITE`**, which is a valid closure, not a
  failure.
- Add to **Hard blockers (NO-GO)**: claiming a Full Suite pass that did not run;
  tagging GO on a pretended pass; editing CI to hide a failure; cancelling a
  Full Suite that had already begun executing in order to report a zero count.
- The GO tag is created later, by the single consolidated closure, on the frozen
  final integrated SHA — never retroactively backdated onto the fix.
