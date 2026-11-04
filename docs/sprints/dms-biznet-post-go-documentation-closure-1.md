# DMS-BIZNET-POST-GO-DOCUMENTATION-CLOSURE-1

**Date:** 2026-11-04 (WITA, UTC+8) · **Type:** INFRA_RELEASE (documentation, rules and governance tests only)
**Runtime change:** none · **Deploy:** none (not authorized, not needed) · **GO tag:** none for this change

Aligns the documentation with the first Biznet production deployment and makes the one GO-tag naming deviation an
explicit, narrow, registered exception.

## 1. The release this records

| Item | Value |
|---|---|
| Production environment | Biznet Gio host `DaengtisiaMS` (`deploy/production-host.conf`); Hostinger stays write-frozen |
| Production commit | `6f22beb77c1648811bf10c2208afc0b75502252c` (tree `bdcb996b`); previous release `89711151` |
| Deploy | `20261104-151042`, 2026-11-04, finished 15:12:28 UTC (23:12:28 WITA); `exit=0`, `DEPLOY OK` |
| Deploy proof | `verify-transition 20261104-151042 6f22beb7…` → `TRANSITION DEPLOY VERIFIED` (the one-time first-deploy path; production-host-identity.md §6a) |
| CI | push run `41831518128` on the exact commit; every required gate succeeded (critical gate 7,795 passed); Full Suite **deferred** by the ACTIVE policy, not passed |
| Production verification | canonical URL `/login` and the three health checks returned 200, `/storage/…` 403; 0 migrations ran; row counts identical across all tables; critical tables content-identical; host configuration unchanged; no new application errors |
| Backups | on-server and off-server copies healthy before the deploy; the deploy took and verified its own dump; no backup, historical archive or rollback database was removed |
| GO tag | `dms-biznet-first-production-deploy-infra-1-go`, tag object `a58aedfe` → `6f22beb7` (owner-named; see §3) |
| Cleanup | staging copies and merged worktrees from that mission removed; protected material untouched |

## 2. What deployment GO does — and does not — mean

| Claim | Status |
|---|---|
| **Deployment GO** — the approved commit is deployed, proven by identifier, healthy, with data unchanged | **GO** (verified 2026-11-04) |
| **Production stability under clinical load** | **Not verified.** The clinics were closed during verification; about 20 minutes of monitoring observed an idle system only |
| **Scanner Agent clinic-PC rollout** | **Not done.** Version 0.2.0 is in the release and its tests pass; no clinic PC has been upgraded or verified |
| **Clinic reopening** | **Not authorized.** Requires a separate owner decision; reopening conditions are unchanged |

Nothing in this table is upgraded by this documentation change.

## 3. CG-16 finding

CG-16 named a GO tag by the manifest `go_tag` and had **no exception clause**. The release tag was named by the owner
in the deploy authorization (§15), so it deviated from CG-16 as written. Code enforcement is narrower than the rule:
`SprintManifestValidator` checks only the `-go` pattern and `SprintReleaseChecker` checks only that the manifest's tag
is free, so no automated gate was violated.

The deploy released two sprints, and neither sprint's GO tag exists:

| Sprint | PRs | Manifest `go_tag` |
|---|---|---|
| DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 | #586, #587, #588 | `dms-post-migration-dev-infra-alignment-1-go` |
| DMS-SCANNER-AGENT-TRUSTED-ORIGIN-HARDENING-1 | #589 | `dms-scanner-agent-trusted-origin-hardening-1-go` |

Clarification adopted: CG-16 now permits **one** exception. When a single production deploy releases more than one
sprint, the owner may name the release tag in writing. The tag must still match the `-go` pattern and be registered in
`docs/governance/go-tag-exceptions.md`, with the superseded `go_tag` values listed; those tags are never created
afterwards. Nothing else is an exception. The existing tag is preserved unchanged. Its message names only one of the
two superseded tags, and the registry records that gap rather than modifying the tag.

## 4. Documentation corrected

- `CLAUDE.md` CG-15: removed "Biznet has not yet run one". It now records the first deploy and sends later deploys
  through §6b under the owner's current release governance. Every run must be proven with `verify <deploy-id>`. The
  base tip is pinned at launch. Rollback is code-only. CG-15 deliberately adds **no** new per-deploy authorization
  rule: who approves a later deploy is the owner's release governance to state, not this documentation's.
- `CLAUDE.md` CG-16: the narrow exception and the registry pointer.
- `docs/runbooks/production-host-identity.md`: §6 marked closed with blocker status. §6a marked executed and kept as
  history, with its step-2 count corrected (entries outside the `deploy-*`/`latest.*` contract are excluded: 510 − 2
  = 508) and an execution record added. New §6b gives the current procedure for every later deploy.
- `docs/runbooks/vps-deploy-evidence-timeout-recovery-runbook.md`: the first-deploy section is marked done.
- `.claude/rules/deploy-infra.md` (AIR-DEP-33 tail, new AIR-DEP-34, current posture) and
  `.cursor/rules/220-production-host-identity-biznet.mdc` (§4 items 12 and 13d, new 13f).
- New `docs/governance/go-tag-exceptions.md`.

Historical Hostinger records are not rewritten (rule 220 §14).

## 5. Tests

- `DevflowAiInstructionSystemTest`: CG-15 and CG-16 now pin the new required content, and a new test keeps the GO-tag
  exception narrow and registered. Each registered and superseded tag must match the validator's own pattern, carry
  exact SHAs, a written authorization and at least two superseded sprint tags. The current manifest may never
  re-declare a superseded tag.
- `ProductionHostIdentityGuardTest`: a new test pins the first-deploy record and the §6b safety order. It rejects the
  stale "never deployed" claims.
- No existing assertion was removed or relaxed.
