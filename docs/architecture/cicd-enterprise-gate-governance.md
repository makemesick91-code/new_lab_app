# CI/CD Enterprise Gate Governance (ENT-10)

Durable governance lock for the enterprise CI/CD gate. ENT-10 extends the
shipped NSF-9 / NSF-10 flag / smoke / evidence gates into a full enterprise
CI/CD gate: critical tests, migration safety, route/config sanity, and smoke
commands, encoded so a failed gate blocks merge and no gate can be skipped
silently.

Verify with `php artisan foundation:cicd-enterprise-gate-check --strict`.

## Scope

Read-only, config-driven governance over the existing release pipeline — **no
migration, no runtime driver change, no new route, no permission change, no
business workflow change, no queue worker enabled, no external CI/CD platform,
no secret added**. It validates the deploy script, the CI workflow + CI script,
the release-evidence profiles, and the release-safety pre-deploy gate against a
single contract, and re-verifies that the ENT-5..9 foundations remain GO.

## Surfaces

- `config/cicd_enterprise_gate.php` — the contract: gate files, required
  foundation commands, required deploy-evidence commands, migration-safety
  expectations, destructive-command patterns (kept here so no app/CI/deploy
  source carries them inline), the ENT-8 cache-order markers, CI workflow
  expectations, evidence-artifact requirements, and pre-deploy gate commands.
- `App\Support\Cicd\CicdEnterpriseGateScanner` — read-only posture scanner for
  the deploy script, CI workflow/script, release-evidence profiles, and
  release-safety pre-deploy gate.
- `App\Services\Foundation\CicdEnterpriseGateGovernanceService` — publishes
  ENT10-CICD001..ENT10-CICD012, aggregates the ENT-5/6/7/8/9 decisions, and
  emits the informational `cicd_enterprise_gate_governance` summary section
  (not wired into the blocking `combinedDecision`).
- `php artisan foundation:cicd-enterprise-gate-check` (`--json`, `--strict`,
  `--fail-on-warning`).
- Evidence artifact `cicd-enterprise-gate-check.json` in the `ci` and `vps`
  release-evidence profiles, the `ReleaseEvidenceService` job map, the
  `foundation_governance` CI-gate registry, the CI workflow +
  `scripts/ci/foundation-evidence-gates.sh`, and `scripts/deploy-vps.sh`
  (captured after the preserved ENT-8 route/config cache-clear ordering).
- Pre-deploy gate `foundation:cicd-enterprise-gate-check` in
  `config/release_safety.php`.

## Rules

- **ENT10-CICD001** — Enterprise CI gate runs on every pull request to the
  approved base branch; a failed gate blocks merge. CI never targets `main`.
- **ENT10-CICD002** — CI/deploy validate the ENT-5..9 foundation stack
  (queue-retry, idempotency/outbox, developer-console, health-check,
  security-compliance) before release; those strict checks must stay GO.
- **ENT10-CICD003** — The `ci` and `vps` release-evidence profiles require the
  ENT-10 `cicd-enterprise-gate-check.json` artifact plus the ENT-5..9 siblings;
  capture/check produce and validate them.
- **ENT10-CICD004** — Deploy verifies a database backup (`pg_dump`) before
  pull/migrate; backup failure stops the deploy.
- **ENT10-CICD005** — Migration safety: `php artisan migrate --force` only —
  never `migrate:fresh`, `migrate:reset`, `db:wipe`, `schema:drop`,
  `DROP DATABASE`/`DROP SCHEMA`, or `TRUNCATE`.
- **ENT10-CICD006** — The ENT-8 cache-order hardening is preserved: route/config
  cache is cleared before the route-dependent governance gates.
- **ENT10-CICD007** — Route/config sanity: `config:cache`, `route:cache`, and
  `view:cache` are rebuilt after the gate phase.
- **ENT10-CICD008** — Gate failure exits non-zero (`set -euo pipefail`; commands
  return non-zero on FAIL and on WATCH under `--strict`); no gate is skipped
  silently.
- **ENT10-CICD009** — Evidence artifacts stay non-sensitive: every artifact
  passes the release-evidence forbidden-pattern/regex scan; no secret,
  credential, or KTP/NIK-shaped value is written or printed.
- **ENT10-CICD010** — The release-safety pre-deploy gate lists the ENT-5..9
  foundation checks and `foundation:cicd-enterprise-gate-check`, each registered.
- **ENT10-CICD011** — Destructive-command literals live in
  `config/cicd_enterprise_gate.php`, never inline in app/CI/deploy source.
- **ENT10-CICD012** — Any future CI/deploy gate, evidence artifact, or
  release-safety gate must extend this contract with coverage + tests and pass
  `foundation:cicd-enterprise-gate-check` before shipping.

## Preserved foundations

ENT-5 queue/retry, ENT-6 idempotency/outbox, ENT-7 developer console, ENT-8
health-check, and ENT-9 security/PII compliance governance all remain mandatory
and are surfaced by this gate; their strict checks must stay GO for ENT-10 to be
GO. The gate never enables a queue worker, never sends external traffic, and
never weakens an existing permission, policy, consent, room, or completion gate.
