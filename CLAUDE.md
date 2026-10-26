# DaengtisiaMS — Claude Code project instructions

DaengtisiaMS (Daengtisia Management System) is a multi-branch dental clinic and dental lab platform: a Laravel
modular monolith (`app/Modules/*`), PostgreSQL 16 in CI and production, Blade + Tailwind + Alpine. Production is a
single VPS pilot that serves real patient data.

This file holds only what every session needs. Until 2026-10-26 it also carried 240 sprint records
(1.56 MB, ~659K tokens loaded into every session). Those records now live, verbatim, in the archive below.

## Foundation-First Sprint Lock

Status: **FOUNDATION-FIRST LOCK ACTIVE**. All non-foundation work is POST-FOUNDATION BACKLOG and blocked until
FOUNDATION GO. Read `docs/architecture/foundation-first-sprint-lock-governance.md` before planning or implementing
sprint work.

## Where knowledge lives (authority order)

1. **Code, migrations, routes, tests, config**: the source of truth. Verify a claim there before acting on it.
2. **`.cursor/rules/NNN-*.mdc`**: canonical durable rules, one per sprint or foundation. A later rule that says it
   supersedes an earlier one wins; `SUPERSEDED` markers are authoritative.
3. **`.claude/rules/*.md`**: domain rules. Each loads automatically when you open a file it covers. It summarises
   the domain's invariants and indexes its canonical `.cursor/rules` files. If it disagrees with the code or a
   canonical rule, follow the code, then the canonical rule, and report the drift.
4. **`docs/`**: the Obsidian knowledge base. Start at `docs/00-INDEX.md` through the `knowledge-base` skill and
   read on demand (never `@`-import it). Check `docs/knowledge-base/doc-status-registry.md`: `SNAPSHOT` and `STALE`
   are hints, not facts. Report doc drift as a proposal (format in `docs/knowledge-base/conventions.md`); never
   rename or move an existing doc, because code, config and tests reference doc paths exactly.
5. **`docs/sprints/claude-md-archive/`**: the former `CLAUDE.md` sprint records, verbatim and `HISTORICAL`.
   Grep by sprint id, e.g. `grep -n 'ENT-10' docs/sprints/claude-md-archive/*.md`; never read a whole file.
   `manifest.json` hashes every section.

Domain rule files in `.claude/rules/`: `rme-clinical`, `rme-billing`, `branch-context`, `patient-identity`,
`legacy-rme`, `legacy-import`, `doctor-device-android`, `doctor-access`, `lab`, `inventory`, `ui`, `satusehat`,
`foundation-governance`, `cicd-testing`, `deploy-infra`, `monitoring`. A rule file loads only after you open a file
it covers. When you plan work in a domain before opening any of its files, read its rule file first.

## Critical global rules

### Repository and git

- **CG-01 Base branch.** All work targets `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`,
  never `main`. Resolve it from `origin` after `git fetch`: local refs are routinely stale
  (`php artisan devflow:base-ref-check`).
- **CG-02 Worktree isolation.** One task gets one new worktree and branch, cut from the remote base. Never edit the
  primary checkout (it is often parked on an old branch) or another session's worktree. Never remove a worktree,
  branch or stash you did not create. The Obsidian vault worktree `/home/fikri/Projects/daengtisiams-kb-vault` is
  locked and detached; never edit it directly. In a new worktree, copy `vendor/` or run `composer install` (never
  symlink it), and unset an upstream that points at the base branch so a bare `git push` cannot hit it.
- **CG-03 Git safety.** Stage explicit paths; never `git add -A`, because agents leave scratch files behind. Never
  force-push a shared branch, never use `--no-verify`, never bypass a required check. PRs squash-merge into the base
  branch.

### Architecture

- **CG-04 Layering.** `Controller (thin) → FormRequest → Service (business logic) → RepositoryInterface → Repository
  → Model`. Never Controller → Model, never View → Service/Repository/Model, never ad hoc DB access across modules.
  Modules live in `app/Modules/<Module>`; bind repositories and policies in `RepositoryServiceProvider`. Multi-write
  operations run inside `DB::transaction`, with row locks where state is contended. Extend established patterns;
  do not introduce frameworks. Blade + Tailwind + Alpine only: no React or Vue, and no business logic in Blade
  (`.cursor/rules/00-adlms-core.mdc`).
- **CG-05 Branch isolation.** The branch always comes from a server authority: `App\Modules\Branch\Services\BranchContext`,
  `RmeWorkingBranchScope`, or the branch derived from a patient's medical-record number for legacy archives. Never
  from a request `branch_id`, which may only narrow. Queries over branch-owned data are branch-scoped. `MAIN` is not
  an RME branch. Cross-branch reads are read-only and permission-gated. Where existence must not leak, an
  out-of-scope record answers 404, not 403.
- **CG-06 Authorization is three layers.** Route middleware, a policy, and a re-assertion in the service. A hidden
  menu or a disabled button is never the boundary. `Gate::before` grants Super Admin every ability, so a rule that
  must also bind Super Admin (separation of duties, workflow state) lives in the service. Audit with effective
  permissions (`$user->can()`), never with direct-only permission queries.
- **CG-07 Separation of duties.** Maker ≠ checker, creator ≠ approver, uploader ≠ publisher wherever a rule requires
  it, enforced inside the transaction rather than by role convention. Account separation is verifiable; human
  separation is not, so never claim it.
- **CG-08 Feature flags.** Read them only through `App\Services\Foundation\FeatureFlagService`: dotted keys break
  `config()` traversal. Risky flags default OFF, and an environment override must be captured at config build
  (`.cursor/rules/92-feature-flag-runtime-override.mdc`).

### Data, privacy and clinical correctness

- **CG-09 Migrations are additive.** Production runs `php artisan migrate --force` only, after a backup. Never
  `migrate:fresh`, `db:wipe`, `schema:drop`, `migrate:reset` or destructive SQL on the VPS.
- **CG-10 Inventory is ledger-only.** Stock is derived from `trx_inventory_movements`; never add or write a mutable
  stock column.
- **CG-11 Patient privacy.** KTP/NIK is masked on every list, search result, report, export, log, audit payload,
  evidence file, doc and test. The full number appears only on the permission-gated patient master-data form and
  the RME consent document. Clinical evidence lives only on private disks through
  `App\Support\Storage\ClinicalEvidenceStorage` and is served by policy-checked controllers, never by a public URL.
  Never put patient data, credentials, tokens or environment-file contents into code, docs, rules, commits or chat.
- **CG-12 Clinical time.** Clinical "today" and every clinical date come from `App\Support\Clinical\ClinicalClock`
  (`Asia/Makassar`); `config/app.php` stays UTC for technical instants. Never derive a clinical date from
  `Carbon::today()` or `now()`. Report clinic-local times as WITA (UTC+8).
- **CG-13 Never fabricate records.** No fake patient, visit, consent, invoice, payment or archive to produce evidence;
  synthetic data belongs in tests. Lifecycle changes go through the canonical services and commands (dry run first,
  then `--apply`), never through raw SQL or a REPL.

### Production and deployment

- **CG-14 Production identity.** VPS `srv1730088`, app at `/var/www/asia-dental-lab-v2`, reached with
  `ssh daengtisiams-vps`. Canonical URL `https://daengtisia.online`; the bare IP and localhost do not reach the app
  (a co-tenant and nginx's unknown-host default server answer them), so never verify there. PHP-FPM runs as `daengtisiams` on PHP 8.3; the host's default CLI PHP belongs to
  the co-tenant. Never run `php artisan tinker` or psysh on production: it writes ERROR lines that hold monitoring at
  WATCH for 24 hours. Production checks are read-only unless the task explicitly authorises a mutation.
- **CG-15 Deploy.** Deploy only with `bash scripts/deploy-vps-runner.sh start`, executed **on the VPS** in the app
  directory, never on a workstation or a CI runner. A deploy is complete only when its status file reports `exit=0`
  and its log reports `DEPLOY OK`; the SSH pipe may drop while the detached runner carries on. The runner takes the
  backup, pins the SHA and runs from an immutable snapshot, so never pre-pull or hand-edit the production tree. After
  a permission change run `PermissionSeeder`, then `RoleSeeder`, then `permission:cache-reset`. Rebuild config
  caches as the runtime user. If you edit the environment file, re-assert `root:daengtisiams 0640`.
- **CG-16 GO tags.** Annotated, named by the lowercase `go_tag` declared in `.sprint/current.yml` (ending `-go`),
  created only after merge, deploy and production verification. Never move, recreate or delete an existing tag.
  The deploy fetches with `--no-tags`, so fetch tags explicitly before checking a GO tag against the VPS HEAD. A
  docs-only evidence commit after the GO commit is not deployed.

### CI and testing

- **CG-17 CI truthfulness.** Required gates must pass on the exact candidate SHA. A green job does not mean its tests
  ran, so read the step. Skipped is not passed. Never weaken the classifier, a gate or a test to get green, and never
  add `paths-ignore`.
- **CG-18 Full Suite policy.** The GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE
  (`.github/ci-policy/full-suite-policy.json`, `.cursor/rules/107-global-temporary-full-suite-policy.mdc`): a fix
  sprint does not run the Full Suite and reports it as deferred, never as passed. `sprint:test --all-required` and
  `sprint:test --regression` can escalate to the whole suite, so use `sprint:test-plan`, `sprint:test --focused` and
  targeted `php artisan test --filter=…` instead.
- **CG-19 New suites must be selected.** The NSF-R011 critical gate is a name allowlist. A new test file that matches
  no `--filter` token in **both** runner variants never runs in CI. Add the token, and declare the suite in
  `config/ci_runner.php` `critical_gate_mandatory_suites` when it protects a critical invariant.
- **CG-20 Test determinism.** Run one `php artisan test` process per worktree: parallel runs share `Storage::fake`
  disks. Pin clock-dependent fixtures (`Carbon::setTestNow`, `clinicalToday()`). SQLite green is not PostgreSQL
  green, so verify database-sensitive code on PostgreSQL 16. Every temporary file has an owner that removes it. A
  change gets focused Pest tests for the happy path, validation, authorization, branch isolation and, where it
  applies, ledger correctness.

### Working method

- **CG-21 Evidence over claims.** "Pre-existing" requires reproducing the failure on the untouched base. Report
  measured numbers, never estimates dressed as facts. A WATCH is reported as WATCH.
- **CG-22 Owner decisions are never inferred.** Activation, pilot scope, approvals and production data changes need
  an explicit owner decision, recorded in the sprint doc.
- **CG-23 Keep context small.** Follow the `limit-saver` skill: targeted `grep` or graphify before reading files, and
  never read all of `docs/`, `docs/sprints/`, `.cursor/rules/` or the archive. When `graphify-out/graph.json` exists,
  use `graphify query "<question>"`, `graphify path "<A>" "<B>"` or `graphify explain "<concept>"` before raw reads;
  read `graphify-out/GRAPH_REPORT.md` only for broad architecture review; run `graphify update .` after code changes.
- **CG-24 Do not grow this file.** A sprint closure record goes to `docs/sprints/<sprint-id>.md` (the
  `sprint-doc-writer` skill). A durable rule goes to `.cursor/rules/NNN-*.mdc`, plus one line in the matching
  `.claude/rules/<domain>.md`. The GO tag itself is the release record. Edit this file only when a
  global rule changes. `tests/Feature/Foundation/DevflowAiInstructionSystemTest.php` enforces the size budget and the
  presence of every rule above. Two scanners fail CI on doc text: never write the literal environment-file name in
  rule or governance text (write "the environment file"), and a runbook line that names a forbidden command must
  carry this repository's `-- never` marker (`tests/Feature/Deploy/ProductionShellCommandGuardTest.php`).
