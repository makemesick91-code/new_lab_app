# DMS-BK2-MANUAL-ONLY-1 — BK-2 is manual-only and not a deploy gate

| Field | Value |
|---|---|
| Date | 2026-11-14 (WITA) |
| Status | **OPEN**: pull request prepared; not merged, nothing deployed |
| Category | Foundation-First allowed category 5, *backup / restore / monitoring / CI-CD gates* |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` at `6c7d2c0e` |
| Branch / PR | `docs/dms-bk2-manual-only-1` / see the pull request |
| Durable rule | `.cursor/rules/232-bk2-manual-only-not-a-deploy-gate.mdc` (supersedes rule 230 §9), index line `AIR-DEP-38` |
| Production | untouched: no deploy, no host change, no BK-2 run; the BK-2 and db-backup timers are not touched |
| GO tag | none: nothing is deployed, so there is no `go_tag` |
| BK-2 | `SKIPPED — DMS-BK2-MANUAL-ONLY-1 (Owner Directive)` |

## 1. Owner directive

On 2026-11-14 the Project Owner issued the directive `DMS-BK2-MANUAL-ONLY-1` (status ACTIVE, every later sprint, until
the owner changes or revokes it) and then ordered, in writing: "hapus syarat backup BK-2 saat deploy" (remove the BK-2
backup requirement at deploy). The directive says:

- BK-2 is skipped by default. No agent creates, schedules, triggers or runs a BK-2 backup without an explicit owner
  order about BK-2; "lanjutkan sprint", "deploy", "merge" and "GO" are not such an order.
- BK-2 is not a sprint requirement and not a GO/NO-GO requirement, and the owner is not asked to confirm it again and
  again.
- Still mandatory: the production database backup before a deploy, the automated backups already active, backup
  verification and restore readiness. Existing BK-2 backups are never deleted or modified.
- If BK-2 would be the only backup meeting an operation's safety requirement, the operation is BLOCKED rather than
  forced.
- A sprint report carries the line `BK-2: SKIPPED — DMS-BK2-MANUAL-ONLY-1 (Owner Directive)`; it is not a failure.

## 2. Where BK-2 was a deploy gate (read-only audit)

| # | Finding | Evidence |
|---|---|---|
| A1 | The deploy runbook made BK-2 a precondition: the host BK-2 status had to report the on-server and the off-server copy healthy, the workstation verdict was read at the start of deploy work, and a fail state was a HOLD. | `docs/runbooks/production-host-identity.md` §6b step 3; §6d inherited it ("All of §6b's (BK-2, …)") |
| A2 | Rule 230 and the BK-2 runbook restated that gate as "unchanged". | rule 230 §9; `docs/runbooks/bk2-offserver-workstation-recovery.md` §7 |
| A3 | The gate was procedural only. No deploy script consults BK-2. | `scripts/deploy-vps.sh` and `scripts/deploy-vps-runner.sh` contain no BK-2 reference |
| A4 | The deploy's own pre-deploy backup is enforced in code: the runner writes a private `pre_auto_deploy_<stamp>.sql` dump before the checkout and stops on a failed dump. | `scripts/deploy-vps.sh` runs under `set -euo pipefail`; dump via `dms_write_private_dump` |
| A5 | BK-2's role is disaster recovery (the only encrypted, off-server, full-recovery copy); the on-server restore point a deploy relies on is the db-backup timer and the deploy dump. | `deploy/systemd/daengtisiams-db-backup.timer` header |

A4 and A5 mean removing the BK-2 gate does not leave a deploy without its required backup, so the directive's BLOCK
clause does not apply to deploys.

## 3. What changed

- `docs/runbooks/production-host-identity.md` §6b step 3: the deploy's verified dump is named as the required
  pre-deploy backup; BK-2 is not a deploy gate; a BK-2 check enters a deploy only on an explicit owner order. §6d's
  inherited precondition list no longer names BK-2.
- `docs/runbooks/bk2-offserver-workstation-recovery.md` §7: deploy work no longer reads `bk2-status.sh` or asks for the
  HDD; the runbook applies when the owner orders a BK-2 check, recovery or drill.
- `.cursor/rules/230-bk2-offserver-workstation-recovery.mdc`: status line and §9 marked SUPERSEDED by rule 232.
- `.cursor/rules/232-bk2-manual-only-not-a-deploy-gate.mdc` (new, `alwaysApply: true` because the directive covers
  every sprint, not only deploy files).
- `.claude/rules/deploy-infra.md`: `AIR-DEP-38` and a canonical-rules row.
- `docs/governance/full-development-production-testing-policy.md` risk R5: the deploy-time monitor is the runner's
  verified dump, not BK-2 health.
- `docs/00-INDEX.md`: link to this record.
- `tests/Feature/Deploy/DeployHardenBk2OffServerRecoveryTest.php`: the old assertion that the deploy runbook keeps the
  BK-2 HOLD is replaced by a rule 232 contract test (already in `critical_gate_mandatory_suites`, selected by the
  `DeployHarden` token).

Deliberately unchanged: the BK-2 timers on the host and the workstation (rule 221 keeps them ON), the rest of rule 230,
the deploy scripts, and every existing backup.

## 4. Decisions

| # | Decision | Basis |
|---|---|---|
| D1 | BK-2 is removed from the deploy gate; the deploy's own verified dump is the mandatory pre-deploy backup. | owner order, 2026-11-14; A3–A5 |
| D2 | The BK-2 timers stay ON and are not modified. | directive §3; rule 221 |
| D3 | Owner holds that merely involve the backup HDD (such as a key-custody hold) are separate owner decisions and are not lifted by this rule. | CG-22 |

## 5. Tests

Local run, 2026-11-14 (WSL2, PHP 8.3, SQLite in memory, a disposable environment file copied from the example and
removed afterwards):

| Suites | Result |
|---|---|
| `DeployHardenBk2OffServerRecoveryTest`, `DevflowAiInstructionSystemTest`, `ProductionShellCommandGuardTest` | 49 passed (2583 assertions) |
| the three above plus `ProductionHostIdentityGuardTest`, `DeployHardenReleaseGuardTrustTest`, `DeployHardenRunnerEvidenceContractTest`, `DeployHardenApprovedShaGuardTest` | 338 passed (4186 assertions) |
| Negative check: the base's `production-host-identity.md` restored in place | the rule 232 test fails (1 failed, 6 passed); file restored |

Full Suite: DEFERRED (global temporary full-suite policy, CG-18), never reported as passed. CI results are on the
pull request.

## 6. Merge timing

Rule 229 §4.8 (widened by rule 231) admits only release approval records, their `.sig` files and `docs/**/*.md` after
an approved commit. This change also touches `.cursor/rules/`, `.claude/rules/` and `tests/`, so merging it while a
signed release approval is waiting for its deploy makes that approval refuse (`DEPLOYMENT=DENIED`). Merge it after
that deploy, or have the owner sign a new approval under a new release id. The merge is the owner's decision.
