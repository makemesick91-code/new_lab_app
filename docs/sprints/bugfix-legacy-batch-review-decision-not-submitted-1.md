# BUGFIX-LEGACY-BATCH-REVIEW-DECISION-NOT-SUBMITTED-1

**Type:** RUNTIME_FIX
**Base authority:** `feature-legacy-batch-review-publish-1-pr2-go` @ `ac9acd9f` (+ its deploy-evidence merge `0d78190d`)
**Scope:** Legacy Batch Review decision submission (RME **and** Odontogram — one shared view)

**No migration. No route. No permission. No policy. No schema change. No clinical
rule touched. Server-side validation preserved verbatim.**

---

## 1. The symptom

On production, Batch Review Legacy RME answered every press of **Tandai Ditinjau**
with:

> keputusan tinjauan wajib diisi.

The operator had made a decision. The server said no decision arrived.

## 2. The contract (read, not guessed)

`RecordLegacyBatchReviewDecisionRequest` is the authority:

```php
'import_id' => ['required', 'integer', 'min:1'],
'decision'  => ['required', 'string', Rule::in(LegacyBatchReviewDecision::all())],
```

and its `attributes()` maps `decision` → `keputusan tinjauan`, which is where the
operator's message comes from. The payload is **flat and singular** by design —
the request's own docblock says "DELIBERATELY SINGULAR. There is no `import_ids`
array and no `all` flag", which is PR1's structural guarantee that there can be
no "Review All".

| | |
|---|---|
| `EXPECTED_FIELD` | `decision` (flat, top level, beside `import_id`) |
| `ACTUAL_FIELD` | `decision` — correct name, present in the form |
| `ACTUAL_VALUE` | `''` at the instant the browser serialized the form |
| `ROOT_CAUSE` | Alpine flushes the `x-model` DOM write on the **microtask queue**; the component submitted synchronously in the same tick, so the stale empty value was serialized |
| `VALIDATION_WEAKENED` | **NO** |

### Why that is the cause, from the shipped sources

`node_modules/alpinejs/dist/module.cjs.js`:

- `function scheduler(callback) { queueJob(callback); }` → `queueMicrotask(flushJobs)`
- the model binding writes the DOM inside `effect(() => { ... el._x_forceModelUpdate(value) })`

The old inline component did:

```js
this.decision = decision;   // queues the DOM write
this.$refs.form.submit();   // runs NOW, before the queue flushes
```

**The server was never wrong.** It correctly refused a request the browser had
built wrong.

### The shape of the bug matches the report exactly

Only the REVIEWED path broke. `BLOCKED` / `NEEDS_ATTENTION` reveal the reason
panel instead of submitting, so the microtask queue flushes long before
`confirmTriage()` submits in a later tick — which is precisely why the operator
saw this on "Tandai Ditinjau" and nowhere else.

### Three competing hypotheses, each ruled out with evidence

| Hypothesis | Evidence |
|---|---|
| `x-ui.button` renders a native submit | `@props(['type' => 'button'])`, rendered `type="button"` — the button was never the problem |
| `@push('scripts')` had no stack | `layouts/app.blade.php:47` carries `@stack('scripts')` |
| Alpine v2/v3 API mismatch | Alpine 3.15.12 |

## 3. The fix

`resources/js/legacy-batch-review.js` (new) — the component, extracted to a
plain dependency-free factory and registered through `Alpine.data()` in
`app.js`, following `patient-combobox.js` / `doctor-device-webauthn.js`.

One submission path, and it writes the field **synchronously**:

```js
submitDecision(decision) {
    if (! this.mutable) return false;
    if (! this.isKnownDecision(decision)) return false;

    const form  = (this.$refs || {}).form;
    const field = (this.$refs || {}).decisionField;
    if (! form || ! field) return false;

    field.value = decision;   // <- the fix
    form.submit();
    return true;
}
```

This is **not** a `setTimeout` / `$nextTick` race workaround. There is no race
left to lose: the value is in the field in the same block as the submit.

The declarative `:value="decision"` binding stays for coherent rendering; it is
no longer what the server reads. The `x-ref="decisionField"` is therefore
load-bearing, and the Blade comment says so.

### One encoding, one path

Mouse buttons, keyboard `R`/`B`/`P`, and triage confirmation all go through
`mark()` → `submitDecision()`. There is deliberately no second place that can
encode a decision differently.

## 4. What this fix may NOT do, and does not

- **No default REVIEWED.** An empty or unknown decision is refused client-side
  and the server's `required` rule remains the authority. 9 bad values are
  asserted to be refused and to never become `REVIEWED`.
- **No bulk vocabulary.** There is no `ALL` / `markAll` / `reviewAll` /
  `selectAll` anywhere client-side, and a test asserts their absence.
- **Per-item identity preserved.** One attestation = one request naming one
  server-rendered `import_id`. An untouched document has no decision row.
- **Unchanged:** review authorization, separation of duties, patient binding,
  branch scope, single-active-document guard, date rules, triage behaviour, and
  every publish rule.

## 5. The brief's multi-item framing, corrected

The brief anticipated a payload like `decisions[<item>][decision]`. The real
contract is flat and singular, and converting it to an array would have
**weakened** PR1's no-Review-All guarantee. Per-item identity is already
structural, so nothing needed to change there.

## 6. CI — a pre-existing gap found and closed

The critical gate selects by `--filter` token. Measured before any change, with
the workflow's own filter string:

| File | Tests | Selected before |
|---|---|---|
| `LegacyBatchReviewRmeTest` | 22 | **1** |
| `LegacyBatchReviewHttpTest` | 15 | **2** |
| `LegacyBatchReviewOdontogramTest` | 6 | 6 |
| `LegacyBatchReviewDatabaseInvariantTest` | 4 | **0** |
| `LegacyBatchReviewSubmissionContractTest` (new) | 9 | **3** |
| `LegacyBatchPublishRmeTest` | 22 | **1** |
| `LegacyBatchPublishHttpTest` | 17 | **2** |
| `LegacyBatchPublishOdontogramTest` | 8 | 8 |
| `LegacyBatchPublishGuardTest` | 8 | **0** |
| `LegacyBatchPublishConcurrencyTest` | 8 | **1** |
| **Total** | **119** | **24** |

PR1 and PR2 both reached GO with ~80% of their own suites never running in the
required gate. Not zero — which is worse, because partial selection reads like
coverage. The 24 matches were accidental: the unrelated `Odontogram` token swept
both odontogram files wholesale and caught individual tests elsewhere whose Pest
*description* contained the word. `LegacyBatchPublishGuardTest` — the publish
selection contract — and `LegacyBatchReviewDatabaseInvariantTest` ran **nowhere**.

`LegacyRme` does not match `LegacyBatchReviewRmeTest`: `--filter` matches the test
identity and the contiguous substring is `LegacyBatchReviewRme`.

Closed by adding `LegacyBatchReview|LegacyBatchPublish` to **both** filter
variants (asserted byte-identical) and declaring all nine files in
`config/ci_runner.php` `critical_gate_mandatory_suites`, so a dropped token fails
loudly instead of silently. Selection after: **4800 → 4895 (+95)**, every batch
file complete.

## 7. Evidence

| Check | Result |
|---|---|
| `npm run test:js` | **65 passed** |
| `LegacyBatchReviewSubmissionContractTest` | **9 passed / 66 assertions** |
| `LegacyBatchReview` + `LegacyBatchPublish` (SQLite) | **107 passed / 12 skipped** |
| Same, **PostgreSQL 16.15** (production major.minor) | **119 passed / 504 assertions** |
| DB driver asserted, not assumed | `driver=pgsql`, `PostgreSQL 16.15` |
| Legacy regression (`LegacyRme` + `LegacyOdontogram` + `LegacyImportHub`) | **1294 passed / 15 skipped / 0 failed** |
| `CriticalGateSuiteCoverage` | **14 passed** |
| `npm run build` | passed |
| `pint --dirty --test`, `git diff --check` | clean |
| `view:cache` | compiles |
| `architecture:ui-governance-check --strict` | GO |
| `foundation:security-compliance-check` | GO 9/9 |

### The decisive assertion

`tests/js/legacy-batch-review.test.mjs` drives the **real** factory against a form
double whose `submit()` records the field value *at the serialization instant*:

```js
const form = { submit() { submitted.push(field.value); } };
```

A component that writes the value after submitting — or that relies on Alpine's
microtask flush, which is what shipped — fails here. No PHP feature test can see
this: they POST a payload that already carries a decision.

### Mutation testing — 7 applied, 7 killed

| Mutation | Result |
|---|---|
| M1 drop the synchronous field write | **killed** (5 fail) |
| M2 keyboard gets its own encoding | **killed** (1) |
| M3 default to REVIEWED | **killed** (1) |
| M4 drop `x-ref="decisionField"` from the Blade | **killed** (2) |
| M5 REVIEWED button becomes a native submit | **killed** (2) |
| M6 `app.js` stops registering the component | **killed** (1) |
| M7 drop `@keydown.window="onKey($event)"` from the Blade | **killed** (2) |

All files restored byte-identical (revert by copy — `git checkout --` cannot
restore untracked files).

## 8. Named residual — no browser-engine test

`BROWSER_TEST = NOT RUN.`

The local dev database is unseeded (**0 branches / 0 users / 0 patients**),
is behind on migrations (168 applied), and the legacy capability resolves
`migrationEnabled() = false` locally. Standing up a Dusk path would mean
migrating and fully seeding the dev estate plus a served process with flag
overrides — environment build-out beyond a submission-ordering bugfix.

What covers the gap instead, and why it fits this defect: the defect is a
submit-time **ordering** property, and the JS suite captures the field at the
exact serialization instant that broke; the PHP contract suite proves the
rendered view wires that factory (`x-ref="decisionField"`, `x-ref="form"`,
`name="decision"`, `name="import_id"`, `type="button"` controls) **separately for
each archive**; and M4/M5/M6/M7 kill the four ways that wiring could rot.

Stated plainly: **nothing in CI executes this page in a browser engine**, so an
Alpine *initialization* failure on this page specifically would not be caught by
these suites. A future sprint that stands up a seeded local estate should add the
click-and-keyboard Dusk test for both archives.

## 9. Odontogram verified, not assumed

Both archives render this one view, which is exactly why neither is taken on
trust. The rendered-markup assertions and the server-refusal assertions are
written **once per archive**, against each archive's own route, session, adapter
and reviewer:

- `settings.rme.legacy-review-imports.*` with `lbrRmeAdapter()` / `lbrReviewer()`
- `settings.rme.legacy-review-odontograms.*` with `lbrOdontogramAdapter()` /
  `lbrOdontogramReviewer()`

## 10. Gotchas recorded

- **`toContain()` is variadic.** `expect($x)->toContain('needle', 'message')`
  reads the message as a **second needle** and always fails. Keep the
  explanation in a comment.
- **Anchor on behaviour, not copy.** A first draft located the decision controls
  by the label `Tahan`, which also appears on the *clear-triage* button —
  legitimately `type="submit"` for its own form. Anchoring on
  `x-on:click="mark('<DECISION>')"` is precise and survives rewording.
- **`lbrReviewer()` mints a new user per call.** Opening the session as one
  returned user and acting as another 404s, because `.show` is owner-scoped.
  Bind `$reviewer` once and reuse it. (PR1 documented the same trap with
  `superAdmin()`.)
- **A `php -r` probe needs `require "vendor/autoload.php"`** before
  `bootstrap/app.php`, or it dies on `Illuminate\Foundation\Application not found`.
- `LegacyRmeFeatureGuard` lives in `App\Modules\LegacyRme\Support`, not
  `\Services`.

---

## 11. Shipped and deployed (2026-10-04)

**PR #450** squash-merged as `dd053c22641206c8e3f40ca79049a50133dd1882`.
**GO tag** `bugfix-legacy-batch-review-decision-not-submitted-1-go` (annotated,
object `4fed0780`) @ `dd053c22` — local peel == remote peel == VPS HEAD, and
`git describe --tags --exact-match HEAD` on the VPS resolves to the tag.

### Tree integrity

| | |
|---|---|
| CI candidate tree | `35cd291e9f19d4389f619b62bbe6f696415cd4cc` |
| Merge commit tree | `35cd291e9f19d4389f619b62bbe6f696415cd4cc` |
| Deployed tree | `35cd291e9f19d4389f619b62bbe6f696415cd4cc` |

One tree from CI through production — the squash changed no content.

### CI (run `37195685930`, exact SHA `417c0370`)

Every required gate `success`: CICD-CTRL Gate Classifier, NSF-R012 Quality,
NSF-R011 Critical Test Gate, CICD-CTRL Selective Module Gate, NSF-9 Release
Safety & Automated Smoke, NSF-10 Release Evidence, Phase 3 Android Clinic App.

Read from the **log**, not the check mark:

```
Tests:    4895 passed (25696 assertions)
```

`Failed asserting` = 0, `⨯` glyph = 0, no `N failed` segment. **4895 is exactly
the post-token selection count measured locally**, which is the independent
confirmation that the token fix took effect in CI. `LegacyBatchPublishGuardTest`
— which previously ran nowhere — appears in the log.

The sibling `NSF-R011 Critical Test Gate: skipped` is the inactive CICD-CTRL-3
runner variant (exactly one of the two runs, by design); verified mid-run that
one was genuinely `in_progress` so there was no false-green risk.

**`NSF-R011 Full Suite Gate: skipped`** per the standing GLOBAL TEMPORARY
FULL-SUITE POLICY and the owner's explicit instruction. The classifier marked it
`required` as a *risk signal*, which is not an authorization. **SKIPPED IS NOT
PASS** — no full-suite evidence is claimed and no CI was weakened to avoid the
runtime.

### Deploy

Run **on** the VPS via `bash scripts/deploy-vps-runner.sh start`:
`exit=0`, `DEPLOY OK: 20261004-122354`,
`DEPLOY_HEAD_TARGET_MATCH=YES (dd053c22)`, `immutable-exec rc=0`,
`DEPLOY_SNAPSHOT_CLEANED=YES`. `PENDING_MIGRATIONS=0` (this fix adds none).

The single smoke warning is the **known pre-existing** co-tenant nginx shadow:
`SMOKE-HTTP-HEALTH` probes `http://127.0.0.1/login` and another application on
this shared VPS answers the bare loopback with 404. Not a regression — the
canonical domain returns 200.

### Production verification — nothing published

**The decisive frontend check.** This is a browser fix, so the question is
whether the fix reached the bundle the browser downloads:

| | before deploy | after deploy |
|---|---|---|
| `app.js` bundle | `assets/app-CsockFtp.js` | `assets/app-eXeBP3E3.js` |
| `decisionField` occurrences | **0** | **1** |

`legacyBatchReview` and `NEEDS_ATTENTION` are also present. The deployed hash
matches the local build byte-for-byte, which is a free determinism check.

The deployed Blade carries the fix too: `x-ref="decisionField"` = 1,
`@keydown.window="onKey($event)"` = 1, old `x-model="decision"` = **0**, inline
`function legacyBatchReview` = **0**.

HTTP over `https://daengtisia.online` (never the bare IP): `/login`,
`/health/live`, `/health/ready`, `/health/lb` all **200**. Both archives' review
surfaces return **302** for a guest — `settings/rme/legacy-review-imports` and
`settings/rme/legacy-review-odontograms` — no 500.

**Zero clinical side effects.** Nothing was reviewed, attested or published:

| table | count |
|---|---|
| `trx_rme_legacy_records` | 9 (unchanged) |
| `trx_odontogram_legacy_records` | 1 (unchanged) |
| `stg_legacy_batch_review_decisions` | **0** |
| `stg_legacy_review_triage` | **0** |
| `stg_legacy_batch_publish_runs` / `_items` | **0** / **0** |

The application log is **byte-identical** to the pre-deploy baseline —
**1,428,095 bytes / 160 `.ERROR` lines** before and after. Zero new log bytes,
zero new errors. `queue:failed` empty. Production tree clean.

Verification was read-only throughout and **no `php artisan tinker` was run on
production** (it writes `ERROR` records and pins the monitor to WATCH for 24h).

### What the operator can now do

19 staged legacy RME imports and 1 odontogram import were sitting at
`READY_FOR_REVIEW` with 1 open review session. **Tandai Ditinjau** now records a
decision instead of answering "keputusan tinjauan wajib diisi." Publishing is
unchanged and still requires every canonical gate.

### Cleanup

Only task-created resources were removed: the `lbrdec-pg16` PostgreSQL 16
container and the `legacy-batch-review-decision-fix` worktree (44 → 43). The
other four `*-pg16` containers, the 43 unrelated worktrees, and **both stashes**
— including `stash@{0}` (CICD-CTRL-3 runner decommission docs) — are untouched.
No task-created process remains (verified from a `ps` snapshot with the
self-matching line excluded, because a bare `pgrep` matches its own command
line).
