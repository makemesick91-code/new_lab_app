# CICD-FIX-3 — UI Governance Fresh Checkout & Build Artifact Contract Recovery

**Branch:** `feature/cicd-fix-3-ui-governance-fresh-checkout-build-artifact-contract`
**Base:** `feature/cicd-fix-2-inventory-postgresql-selective-gate-portability` @ `b79d0ee`
**Type:** corrective (stacked on CICD-FIX-2 → CICD-FIX-1 → CICD-CTRL-3)

Scope: the single remaining Selective Module Gate failure. CICD-FIX-2's mandate is
not reopened.

---

## 1. Blocker

```
Tests\Feature\Ui\InventoryUixTest
> it passes the UI governance check with GO including UIX-6 rules

  $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);
  expect($exit)->toBe(0);
  expect(Artisan::output())->toContain('"decision": "GO"');
```

Fresh authoritative CI checkout → exit 1. Local → pass. Predates CICD-FIX-2.

---

## 2. Phase 1 — reproduction in a CI-equivalent state

`git archive b79d0ee` reproduces exactly what `actions/checkout@v4` materialises:
tracked files only. Composer dependencies resolved from the same `composer.lock`;
no `npm ci`, no `npm run build`, no `public/build`, no pre-existing governance
output, no user workspace residue.

```
$ php artisan architecture:ui-governance-check --json --strict
exit 1
stderr (0 bytes)
{
    "decision": "WATCH",
    "docs_checked": 6,
    "components_checked": 14,
    "tokens_checked": 5,
    "forbidden_frontend_deps_checked": 22,
    "errors": [],
    "warnings": [
        "Vite build manifest missing (public/build/manifest.json) — run `npm run build` to refresh the asset baseline (UIX-18)."
    ]
}
```

**Zero errors. Exactly one warning.** Under `--strict`, `WATCH` → exit 1, so a
single soft signal fails the test.

### Non-GO rule

| Rule | Expected | Actual | Reason | Evidence path | In git? | Generated? |
|---|---|---|---|---|---|---|
| UIX-18 build-manifest baseline (`ArchitectureUiGovernanceCheckCommand.php:1243`) | file present → no signal | file absent → `warnings[]` → WATCH → exit 1 | `public/build` is Vite output; no test gate builds it | `public/build/manifest.json` | **No** — `.gitignore:3 /public/build` | **Yes** — `npm run build` |

Every other input the command reads is tracked and identical in both
environments (6 UI docs, 14 `x-ui` components, `tailwind.config.js`,
`package.json`, `docs/ui_design_system.md`, all `docs/sprints/uix-*.md`).

---

## 3. Phase 2 — local vs fresh CI delta

```
$ diff fresh.json local.json
2c2
<     "decision": "WATCH"
---
>     "decision": "GO"
8,10c8
<     "warnings": [ "Vite build manifest missing …" ]
---
>     "warnings": []
```

The **entire** delta is one generated, gitignored file:

- fresh checkout: `public/build` absent
- local: `public/build/manifest.json` present, mtime `2026-08-06 23:04:09` — a
  stale artifact of a past `npm run build`

Audited and identical in both: Vite manifest inputs, governance evidence roots,
UIX reports, cached files, storage artifacts, environment variables, repository
cleanliness, branch/commit metadata. No screenshots or generated UI evidence are
consumed by this command at all.

Local is GO **only** because of leftover ambient build output. The conclusion is
not inferred from the mere presence of `public/build` — the command's own JSON
names the file, and the fresh run confirms it is the sole warning.

---

## 4. Phase 3 — classification: **E, governance producer defect**

(with **C** as the presenting symptom: 19 UIX tests inherit the ambient dependency.)

**Not A (legitimate build prerequisite).** `npm run build` is *not* a governance
prerequisite, and the repository had already ruled so twice:

- **CICD-FIX-1** (`7c5a47f`) — *"Considered and rejected: adding `npm ci && npm run
  build` to both critical gates. That couples a PHP regression gate to a frontend
  build, lengthens every run, and makes test outcomes depend on asset build state."*
- **`PerformanceAssetWeightUixTest`**, which owns UIX-18, already skips its bundle
  budget when the manifest is absent: *"Assets not built in this environment; the
  budget is enforced wherever `npm run build` has run (local + VPS deploy)."*

That second file then asserts, three lines below, that the same governance check
must be clean under `--strict` — which only holds when assets *are* built. The
UIX-18 owner contradicts itself, and that contradiction is the defect.

**Not B (tracked-evidence defect).** `public/build` is generated output. The
architecture gitignores it deliberately and CICD-FIX-1 confirmed generated
frontend output must not be depended on. Committing it is the opposite of what
the architecture requires.

**Not D (stale expectation).** The UI governance contract did not change; the
UIX-6 → UIX-21 closure rules are all still in force and still enforced.

**E.** The producer classified an environment-dependent, deliberately-gitignored
build artifact as a *governance warning* that feeds the strict decision. Its own
comment calls the signal "soft" — but "soft" was implemented as `warnings[]`,
which `--strict` promotes to a hard failure.

---

## 5. Phase 4 — fix at the producer layer

`architecture:ui-governance-check` now distinguishes three channels:

| Channel | Meaning | Decides? |
|---|---|---|
| `errors` | a governance rule is violated | yes → `FAIL` |
| `warnings` | soft signal over **tracked source** | yes → `WATCH` |
| `advisories` | observation about the **environment** (generated, gitignored state) | **no** |

The decision is therefore a statement about the tracked source tree and is
reproducible in any clean checkout.

- The missing-manifest signal moves to `advisories`. It is still reported, in
  both JSON and text output (`~ [environment] …`) — not deleted, not suppressed.
- `--require-build-manifest` promotes it back to a real warning for any context
  that genuinely builds first (VPS deploy, release evidence, the NSF-R012 quality
  gate — all of which do run `npm run build`).
- The payload gains `build_manifest_present` so the state is always visible.

Not done, deliberately: no `npm` step added to any gate, no build output
committed, no rule suppressed, no manifest faked, no CI/Biznet/GitHub-hosted
special-casing, no restored swallowed exit semantics, and `InventoryUixTest` is
neither skipped nor weakened.

One consumer assertion changed: `PerformanceAssetWeightUixTest` moves from a raw
`not->toContain('UIX-18')` substring match to the `errors`/`warnings` channels,
so it tests rule violations rather than whether assets happened to be built.

---

## 6. Phase 5 — two-direction contract

`tests/Feature/Ui/UiGovernanceFreshCheckoutContractTest.php` (5 tests) relocates
`public/build` so it asserts identically on a built developer machine and in a
clean CI checkout:

| Direction | Expected | Result |
|---|---|---|
| clean checkout, never built | `GO`, exit 0, advisory present, `warnings` empty | pass |
| advisory leaking into rule channels | never | pass |
| `--require-build-manifest --strict` | `WATCH`, exit 1, warning present | pass |
| `--require-build-manifest` without strict | `WATCH`, exit 0 | pass |
| required `x-ui` component genuinely missing | `FAIL`, exit 1 | pass |

The last one exists so the check can never degrade into reporting GO for every
state.

---

## 7. Evidence

Clean checkout of `b79d0ee`, manifest deliberately absent:

```
before                          decision WATCH, exit 1, 1 warning,  0 advisories
after                           decision GO,    exit 0, 0 warnings, 1 advisory
after --require-build-manifest  decision WATCH, exit 1, 1 warning,  0 advisories
```

Local, full repo: `vendor/bin/pint --test` → passed; `git diff --check` → clean.

---

## 8. Gate results

All test runs below: **PostgreSQL 16.14** (CI-canonical and production version, in a
throwaway container) with `public/build` **relocated**, reproducing the fresh-checkout
asset state. Phase 2 proved that file is the complete local-vs-CI delta.

### Selective Module Gate — exact workflow filters

| Step | Result | Assertions | Exit | Duration |
|---|---|---|---|---|
| `--filter='Inventory'` | **1585 passed, 1 skipped, 0 failed** | 8048 | **0** | 1081s |
| `--filter='Lab'` | 18 failed, 9 skipped, 558 passed | 2193 | 2 | 445s |
| `--filter='Ui'` | 1 failed, 584 passed | 4376 | 2 | 418s |
| `--filter='Permission\|AccessControl'` | 2 failed, 327 passed | 1477 | 2 | 255s |

The Inventory step hits the **1586 tests / 0 failures** target exactly. Warnings: **0**
(the ~1585 warning volume noted in the brief did not reproduce on this path; nothing was
suppressed to achieve that). The single skip is pre-existing and explicit — see §9.

### Residual failures are pre-existing — proven, not asserted

Identical filters and conditions at base `b79d0ee` (CICD-FIX-2 tip, without the
CICD-FIX-3 commit):

| Step | base `b79d0ee` | CICD-FIX-3 | Delta |
|---|---|---|---|
| `Lab` | 19 failed | 18 failed | −1 · `it passes the UI governance check under strict mode` |
| `Permission\|AccessControl` | 4 failed | 2 failed | −2 · `…GO including UIX-14 rules`, `…UIX-20 permission-aware…` |

Every failure remaining under CICD-FIX-3 exists at base. CICD-FIX-3 **introduces none and
removes three**. This also shows the blocker was wider than the one reported test: the
same root cause failed UIX-6, UIX-14, UIX-20 and strict-mode consumers across several
filter steps, because ~19 UIX tests call the same command.

The remaining 19 distinct failures reduce to two root causes, both outside this sprint's
mandate and both handed over in
`docs/sprints/cicd-fix-4-lab-permission-postgresql-selective-gate-portability.md`:

- **A** — `trx_lab_orders_branch_id_foreign` violation: LAB-PROD-2 fixtures hardcode
  `branch_id => 1` and never create it (18 failures + 1 `25P02` cascade). SQLite did not
  enforce the FK; PostgreSQL does.
- **B** — `PermissionManagementTest`: unordered pagination, so `manage users` lands on a
  different page under PostgreSQL than under SQLite.

The single `--filter='Ui'` failure is root cause A, swept in because `Ui` matches
substrings such as `req**ui**res` / `REQ**UI**RED`.

### Other gates

| Gate | Result |
|---|---|
| NSF-R012 Quality | **PASS** — `npm ci` 0, `npm run build` 0, `pint --test` (whole repo) passed, `git diff --check` clean |
| NSF-R011 Critical | **PASS** — 325 passed, 1270 assertions, 0 failed, exit 0, 1345s |
| NSF-9 Release Safety | **PASS** — all 11 governance checks exit 0 |
| NSF-10 Release Evidence | **PASS** — capture / check / release-safety-check all exit 0 |
| Selective Module | **FAIL** — pre-existing only, see above |

Critical's 325 passed / 1270 assertions is byte-identical to the count CICD-FIX-1
recorded, confirming that fix still holds.

### Inherited invariants

| From | Invariant | Observed |
|---|---|---|
| CICD-FIX-1 | Pint = 0 | 0 (whole repo) |
| CICD-FIX-1 | Vite = 0 | 0 |
| CICD-FIX-1 | JsonException = 0 | 0 |
| CICD-FIX-1 | Governance decision failures = 0 | 0 |
| CICD-FIX-2 | PRAGMA errors = 0 | 0 |
| CICD-FIX-2 | `GoodsReceiptSchemaTest` | PASS |
| CICD-FIX-2 | `PurchaseOrderSchemaTest` | PASS |
| CICD-FIX-2 | `InventoryDecimalQuantityTest` | PASS |

---

## 9. Known, unhidden

- **1 skip** in the Inventory step:
  `InventoryUnifiedBranchMasterHotfixTest > it every branch-scoped inventory table has a
  branch_id foreign key to mst_branches` explicitly `markTestSkipped`s on any non-SQLite
  driver. Pre-existing (`5f39a9b`), untouched here. It is a twelfth `PRAGMA` site that
  CICD-FIX-2 guarded rather than ported — so the `branch_id → mst_branches` contract is
  verified only on SQLite. Porting it would have caught CICD-FIX-4 root cause A.
- **`ci:assert-non-production-database` exits 1 locally** because the developer `APP_ENV`
  is `local`; CI sets `testing`. Expected, environmental, not a code defect.
- The run used a `postgres:16` container on port 55432. The developer host has only
  PostgreSQL 18, which CICD-CTRL-3 documented as producing 7 self-hosted-only failures,
  so the CI-canonical major version was used deliberately.

---

## 10. Status

**WATCH.** CICD-FIX-3's own mandate is complete and verified: the UI governance decision
is now reproducible in any clean checkout, the blocking test passes on authoritative
PostgreSQL with no build output, both contract directions are pinned, and Quality,
Critical, NSF-9 and NSF-10 all pass.

The Selective Module Gate is still red on two pre-existing, unrelated PostgreSQL
portability defects. Per Phase 9's own precondition ("when CICD-FIX-3 authoritative CI is
green"), the stack merge is **not** performed. Next action: **CICD-FIX-4**.
