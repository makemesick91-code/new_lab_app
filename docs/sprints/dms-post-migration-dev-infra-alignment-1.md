# DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 — Biznet is production, Hostinger is write-frozen

**Date:** 2026-11-04 (WITA). **Type:** SECURITY_FIX. **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `da01282e` (one docs commit ahead of the production runtime `89711151`).
**Owner authority:** mission `DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1` — "DEVELOPMENT FREEZE LIFTED — RESUME NORMAL
DEVELOPMENT". Development only; production deploy and clinical reopening remain separately controlled.
**Rule:** `.cursor/rules/220-production-host-identity-biznet.mdc`. **Guide:** `docs/runbooks/production-host-identity.md`.

## 1. Why

Production moved from Hostinger to Biznet Gio on 2026-11-03 and DNS for `daengtisia.online` now resolves to
`139.190.106.38`. The repository still taught the old host:

- CLAUDE.md CG-14 named `srv1730088` and "reached with `ssh daengtisiams-vps`". That alias was re-pointed at the
  frozen Hostinger box, as root, before the DNS flip, so following CG-14 literally reaches the wrong machine.
- Eight live runbooks, one operations runbook and the deploy runner's header gave the same instruction, and they
  assumed a root login that Biznet does not offer.
- `.github/workflows/deploy-vps.yml` keyscanned whatever host answered, then piped the deploy program over SSH to
  the host named by the `VPS_HOST` secret. That secret names the domain, so one manual dispatch would now have
  targeted live production from CI, which CG-15 forbids. A gap let it through: `forbidden_ci_production_commands`
  is checked only against `foundation-evidence-gates.yml`.
- The scanner agent's test fixtures and example configuration trusted `http://145.79.13.224` as the DaengtisiaMS
  origin. The Hostinger lease has ended, and that address may be reassigned.

## 2. What changed (PR A — this record)

| Area | Change |
|---|---|
| Authority | New `deploy/production-host.conf`: production and frozen-legacy identity, public facts only |
| Workstation check | New `scripts/production-target-check.sh`. It verifies through `ssh -G` (no network) that the alias resolves to the pinned address, logs in as the admin account, checks host keys strictly, has no DNS-learned or `UpdateHostKeys` trust, and that every known-hosts entry equals the pinned key. With `--remote` it reads `hostname` / `hostname -I` only. It refuses any frozen hostname, address or alias, and never accepts or refreshes a key |
| CLAUDE.md | CG-14 rewritten for Biznet, with Hostinger marked WRITE-FROZEN. CG-15: root through `sudo -n`; the first deploy on a new host needs owner approval plus the readiness checklist |
| Runbooks | `production-host-identity.md` (new, canonical). Banners and commands corrected in `vps-deploy-rollback`, `vps-deploy-evidence-timeout-recovery`, `backup-dr-restore-rehearsal`, `dedicated-runtime-identity`, `doctor-global-activation-window`, `enterprise-operations`, `release-evidence-smoke`, `docs/operations/doctor-real-device-readiness-campaign.md`, `docs/ai-knowledge/23_*`. The completed DEPLOY-HARDEN-1 Hostinger transition block was replaced with a pointer to its sprint record |
| Deploy runner | Header comments only: Biznet alias, `sudo -n`, prove the target first |
| CI | `deploy-vps.yml` retired: `workflow_dispatch`, no checkout, no secrets, `exit 1`. New `ci_runner.forbidden_workflow_remote_access_markers`. `SelfHostedRunnerScanner` judges executable workflow lines (comments ignored) for remote-access markers and deploy/rollback programs in both workflows |
| Rules | AIR-DEP-31 added. AIR-DEP-18 and AIR-DEP-28 co-tenant claims corrected. Posture snapshot updated to Biznet. Rule 220 |
| Scanner agent | Test fixtures use `https://daengtisia.online`. README states that the production origin is the domain only |
| Tests | `tests/Feature/Cicd/ProductionHostIdentityGuardTest.php` (25 cases), declared in `critical_gate_mandatory_suites`; the CG-14 pin is updated |

No migration, no permission, no route, no application request-path change, and no production change.

## 3. Deployment compatibility audit (read-only, 2026-11-04)

The Biznet host was inspected read-only over the pinned alias. Nothing was written, restarted or deployed, and no
artisan command was run there. Findings that differ from Hostinger:

| Area | Hostinger (scripts written for) | Biznet (measured) | Consequence |
|---|---|---|---|
| Login | root over SSH | admin account; root has no SSH login | the runner must run as root through `sudo -n` |
| Toolchain | Node + npm present | **Node and npm absent**; Composer 2.7.1, PHP 8.3.6 only | `npm ci` fails **after** checkout + `composer install` — first-deploy blocker D1 |
| PHP | FPM 8.3 + co-tenant CLI 8.5 | 8.3 only; default `www` pool retired | the queue worker's `/usr/bin/php` is 8.3, so the old version mismatch is gone |
| Co-tenant | aish-pos on the same uid | none | isolation verifier still applies; co-tenant prose was Hostinger-only |
| Runner status | real deploys | `latest.status` = Hostinger's `exit=0` (2026-11-02 06:13), copied in migration | `status` reports OK for a deploy that never ran here — blocker D2 |
| Background units | worker running | worker installed, **disabled** (C5); db-backup + BK-2 timers enabled | `provision-runtime-identity.sh --apply` would START the worker (`systemctl restart`) — D4 |
| Backup units | as tracked | installed db-backup units differ from `deploy/systemd/` (18:30 UTC vs 19:00 UTC) | reinstall from the repo would move the schedule — D5 (owner) |
| Loopback | co-tenant answered | nginx unknown-host default server answers (404) | evidence base URL `http://127.0.0.1` stays a known warning — D6 |
| Git | — | checkout clean (one untracked `storage/runtime-home/`, ignored by the dirty check), GitHub reachable read-only, deploy scripts byte-identical to `89711151` | fetch path works |
| Lock root | `/run` writable as root | same; `/run/daengtisiams-deploy` absent (no deploy since boot) | fine under root |

The deploy scripts do not assert root, production identity or the toolchain before their first mutation. A wrong
invocation would fail part-way, not refuse cleanly; as non-root, the lock root falls back to world-writable
`/var/lock`. These are blockers D3 and D4, and they belong to the separate deploy-chain PR.

## 3a. Deploy-chain change (PR B — stacked, owner authorisation required)

| Area | Change |
|---|---|
| `scripts/lib/production-host-preflight.sh` (new) | `dms_production_host_preflight`: positive identity against the authority, refusal of the frozen host (hostname or address), root required, canonical toolchain required (`git composer node npm php pg_dump runuser nginx systemctl`). Every observed value is a parameter; there is no environment override |
| `scripts/deploy-vps.sh`, `scripts/rollback-vps.sh` | call the preflight from the snapshot immediately after the snapshot assertion — before identity resolution, the secret file, the backup and the checkout; a refusal exits `2` |
| `scripts/deploy-vps-runner.sh` | each run writes a sidecar `.host` file; `status` refuses an unbound or foreign status with exit `4`. The `exit=N` format is unchanged |
| `scripts/provision-runtime-identity.sh` | `systemctl try-restart` for the queue worker (both the apply and the rollback path) |
| `config/deployment_entrypoint.php` | preflight library and authority declared as execution-closure members |
| Tests | `tests/Feature/Deploy/DeployHardenProductionHostPreflightTest.php`, declared mandatory |

Behaviour change, stated plainly: the next deploy or rollback on Biznet **refuses** until Node and npm are
installed (D1). On a host that is not `DaengtisiaMS` it refuses always. No application runtime changes.

## 4. Verification

- `ProductionHostIdentityGuardTest` 25/25 + `DevflowAiInstructionSystemTest` + `DedicatedSelfHostedRunnerTest`:
  85 passed, 0 failed, 0 skipped (JUnit). The SSH-backed cases run for real against synthetic configuration in
  RFC 5737 documentation address ranges.
- Mutation campaign: **11 killed / 0 survived / 0 not applied**. Mutants: CG-14 reverts; a runbook instructs the
  legacy alias; the workflow regains SSH; the scanner judges comments; the check forgets frozen addresses, accepts
  `accept-new`, ignores foreign keys, accepts the frozen alias, or accepts root; the deploy starts the queue worker;
  config names the frozen address. Revert by copy, checksum-verified.
- `--list-tests` under the exact critical-gate filter selects all 25 cases. Both runner variants are identical.
- Scanner agent `node --test`: 15/15.
- The workstation check against the real configuration: `daengtisiams-biznet-prod` VERIFIED, both static and
  `--remote`. `daengtisiams-vps` NOT_VERIFIED on 10 counts.
- PR B: `Deploy` + `Cicd` + ENT-11 + POST-ENT + AI-instruction suites — 617 passed / 0 failed / 0 skipped.
  Mutation **13 killed / 0 survived**: root, frozen hostname, frozen address, any-hostname, tool check, the verdict
  ignored, the call dropped (deploy and rollback), binding skipped, binding never written, the worker restarted,
  `$HOSTNAME` trusted, the toolchain emptied.
- Full Suite: **not run** (GLOBAL TEMPORARY FULL-SUITE POLICY ACTIVE — deferred, never reported as passed).

## 5. Residuals and owner actions

1. `tools/daengtisia-scanner-agent/.env.example` still lists `http://145.79.13.224`. Environment-style files are
   outside this sprint's permission scope, so it is pinned by the guard suite as the single recorded residual.
   Owner action: replace it with `https://daengtisia.online`, and check the configured origins of every agent
   already installed on clinic PCs.
2. Delete the now-unused GitHub secrets `VPS_HOST`, `VPS_USER` and `VPS_SSH_KEY` (repository settings).
3. Rename or remove the workstation alias `daengtisiams-vps` (local SSH configuration). It still points at Hostinger
   as root, with `StrictHostKeyChecking ask`.
4. First Biznet deploy: close D1–D4 (§3), then take a separate owner approval. D5 (backup unit drift) is an owner
   decision; backup services are untouched here.
5. GO tag `dms-post-migration-dev-infra-alignment-1-go` is not created. CG-16 requires merge, deploy and production
   verification, and no deploy is authorised.
