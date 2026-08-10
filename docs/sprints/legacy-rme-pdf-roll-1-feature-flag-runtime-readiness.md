# LEGACY-RME-PDF-ROLL-1 — Feature Flag Runtime Override & Controlled Enablement Readiness

**Branch:** `feature/legacy-rme-pdf-roll-1-feature-flag-runtime-readiness`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(1C merge `cee66f7515fa96fc8b6e47832db872c55fe92b58`, GO tag
`legacy-rme-pdf-1c-controlled-publish-patient-history-go`)
**GO tag:** `legacy-rme-pdf-roll-1-feature-flag-runtime-readiness-go`

> **This sprint enables nothing.** `rme.legacy_pdf_archive` stays `false` in
> production. ROLL-1 makes the *switch* trustworthy; deciding to throw it is a
> separate, explicit rollout decision.

---

## 1. Problem

LEGACY-RME-PDF-1C closed with a carry-forward finding: the archive flag defaults
OFF and production resolves OFF (correct, fail-closed), but the runtime override
was never proven to work. Setting `FEATURE_RME_LEGACY_PDF_ARCHIVE=true` could be
silently ignored, leaving an operator unable to enable a capability that the
release notes claim is switchable.

That is harmless while the answer is "off", and a hard blocker the moment anyone
wants it on.

## 2. Root cause — and it was not Legacy RME

`FeatureFlagService::hydrate()` already resolved
`env_value` (captured at config-build time) → runtime `env()` → default. The
resolution logic was sound.

The defect was in the **registry**: `env_value` capture was an ad-hoc, per-flag,
hand-written opt-in. An audit of the canonical base found:

| | Flags |
|---|---|
| Declare an `env_key` | **28 / 28** |
| Actually capture `env_value` | **3** (`lab.workflow_v2`, `satusehat.integration_readiness`, `satusehat.external_submission_enabled`) |
| Declare an `env_key` that is **inert under `config:cache`** | **25** |

Once a deployment runs `config:cache`, Laravel's `LoadEnvironmentVariables`
bootstrapper returns early and the environment file is never read. A runtime
`env()` call then returns `null`, and the declared default wins.

Production runs cached (`bootstrap/cache/config.php` is present on the VPS), so
for 25 of 28 flags the documented `env_key` was a promise the system did not
keep. This is a **shared FeatureFlagService/registry defect**, not a Legacy RME
one, and it is fixed once, canonically — not with a Legacy-RME-only parser.

## 3. What changed

### 3.1 Systematic capture (`config/feature_flags.php`)

Capture is applied once, at the bottom of the file, to every flag declaring an
`env_key`:

```php
foreach ($registry['flags'] as $key => $definition) {
    $envKey = $definition['env_key'] ?? null;

    if (is_string($envKey) && $envKey !== '' && ! array_key_exists('env_value', $definition)) {
        $registry['flags'][$key]['env_value'] = env($envKey);
    }
}
```

A new flag cannot forget it. The result is a plain scalar array, so it stays
`var_export()`-safe for `config:cache`. An explicitly written `env_value` still
wins, so a flag can opt out if it ever needs a computed override — the three
existing explicit captures are left untouched, which keeps the diff on
`lab.workflow_v2` (the only production-enabled flag) at zero.

### 3.2 Fail-closed resolution (`FeatureFlagService::resolveOverride()`)

| Environment | Effective | `env_resolution` |
|---|---|---|
| unset | declared default | `default` |
| `true` / `1` / `on` / `yes` | **on** | `env` |
| `false` / `0` / `off` / `no` | **off** | `env` |
| blank / whitespace | declared default | `default` |
| unparseable (`banana`) | declared default | `invalid_fallback_default` |

Two deliberate choices:

- **Blank is "not configured", not `false`.** For a default-true safety flag
  (`release.automated_smoke_required`), falling back to the default keeps the
  gate ON. Reading blank as `false` would silently disable a safety gate.
- **Invalid falls back to the declared default, not to `false`.** For a risky
  default-off flag that is `false` — a typo can never enable it. For a
  default-true safety flag that is `true` — a typo can never disable it. Either
  way governance FAILS loudly rather than resolving quietly.

### 3.3 Governance that prevents regression

| Check | Status | Meaning |
|---|---|---|
| `FLAG-ENV-CAPTURE` | **FAIL** | a flag declares an `env_key` with no capture → its override is inert under `config:cache` |
| `FLAG-ENV-VALUE-VALID` | **FAIL** | a configured override is not a parseable boolean |

`foundation:feature-flags` now also prints `via=` (how the value was reached)
and `captured=` per flag, so a rollout can be debugged without guessing.

Evidence exposes a **normalized** `env_value` — `true`, `false`, `null`, or the
literal `'invalid'` — never the raw environment string, so a misconfigured value
cannot be echoed into evidence JSON.

### 3.4 Not changed

`LegacyRmeFeatureGuard` was already correct: it delegates to
`FeatureFlagService`. It gains tests proving it inherits the canonical
resolution, and no logic of its own.

## 4. Production impact: provably nil

The VPS `.env` sets exactly one feature variable:

```
FEATURE_LAB_WORKFLOW_V2=true
```

That flag already had capture and already resolved `true`. Every other flag's
variable is unset, so systematic capture stores `null` and each flag keeps
resolving to its declared default. **No effective flag value changes on this
deploy.** The smoke step re-verifies both facts on the live host.

## 5. Verification

Resolved through a genuine cached-config round trip (the registry is built with
a controlled environment, `var_export`ed to a file, required back, and resolved
with the environment removed — exactly what a cached deployment does):

| Case | Before | After |
|---|---|---|
| uncached, unset | off | off |
| uncached, `true` | on | on |
| cached, unset | off | off |
| **cached, `true`** | **off — the defect** | **on** |
| cached, `false` | off | off |
| cached, blank | off | off |
| cached, invalid | off | off + governance FAIL |
| cached, `lab.workflow_v2=true` | on | on |

Tests: `tests/Feature/Foundation/FeatureFlagRuntimeOverrideTest.php` — capture
contract, governance FAIL on a removed capture, the resolution matrix, invalid
handling, redaction, rollback, guard inheritance, request-input rejection, and
a no-drift sweep over the whole registry.

## 6. Rules

Mirrored in `.cursor/rules/92-feature-flag-runtime-override.mdc`:

1. One registry, one resolver; dotted keys never via `config()` traversal.
2. A declared `env_key` must be captured at config-build time.
3. An uncaptured `env_key` FAILS governance.
4. Resolution: captured `env_value` → runtime `env()` → default.
5. Unset, blank and invalid all fall back to the declared default; invalid also
   FAILS governance.
6. Never echo the raw override value.
7. Server-side only — no request, query, header, session or browser input.
8. Risky flags default OFF; enabling one is a WATCH, never a silent GO.
9. Rollback is `false` + rebuild cache; never a schema rollback, never deletion.
10. **Code readiness ≠ production rollout.** A GO tag ships the capability
    disabled; turning it on is a separate explicit decision.
11. Legacy RME is reached only through `LegacyRmeFeatureGuard`; it stays OFF and
    answers 404 server-side while off.

## 7. Carry-forward (out of scope, deliberately)

- **INFRA-POPPLER** — Poppler still needs declarative server provisioning.
- **CICD-FIX-1** — pre-existing `file_get_contents` / Vite-manifest test noise.
- **Deploy queue restart contract** — canonical graceful queue recycle.
- **Controlled production enablement of `rme.legacy_pdf_archive`** — a separate
  rollout sprint. ROLL-1 makes it *possible* and *reversible*; it does not do it.