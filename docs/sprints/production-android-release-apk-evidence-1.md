# PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1

**STATUS: SIGNED. Signer verified against the pin, evidence committed.**

The ceremony was performed by the custodian. Nothing in this repository ever
held the vault passphrase or the keystore password.

Base: `5c50fdd74c5f5e92a3740cd1cb79e6169803efe2`
(tree `75ad7d8d2f8c9a785caeda1f24aa288882a6a556`, tag
`production-android-signing-certificate-pin-1-go`, tag object
`45afdefcfc4f6282d14274eca0777913a8094da0` — all four verified independently).

---

## 1. The finding: the release build was impossible

`android/daengtisia-clinic/app/build.gradle.kts` line 43 reads

```kotlin
proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
```

`app/proguard-rules.pro` **had never existed in this repository.** `assembleRelease`
therefore failed at `:app:minifyReleaseWithR8`.

Three sprints built the machinery around a release artifact — the artifact
verifier, the signer-fingerprint resolver, the certificate pin, the custody
model, a 42-check readiness gate that reported **GO** — and none of them ever
ran `assembleRelease`. The production APK could not have been built on any day
of that period.

**Why governance did not catch it.** `scanner.required_release_block_markers`
requires the release block to *contain the string* `proguardFiles`. Nothing
asserted that the file that call **names** exists. A marker proving a string is
present, with no check that the thing it names exists, is exactly the orphaned
predicate rule 147 is about — and this is its second instance.

## 2. The second finding: the repository taught the forbidden habit

`php artisan tinker` has been run against production twice. The prohibition was
written in rules 113, 121 and 125 and in two runbooks. Meanwhile **three tracked
scripts invoked it**, and a runbook *instructed* it:

| Site | What it was doing |
|---|---|
| `scripts/load-test-baseline.sh:30` | asking "am I on production?" **by running the forbidden command** |
| `scripts/load-test-scale-projection.sh:31` | the same |
| `scripts/sprint-release.sh:125` | reading one YAML key, on the release path |
| `docs/runbooks/satusehat-integration-readiness-runbook.md:45` | telling an operator to run it, in a section that seeds production |

The two load-test scripts are the sharpest shape: the REPL ran on production
*before* the check that would have refused to run there — and the REPL writes
ERROR records into `laravel.log`, which pins the monitoring log signal to WATCH
for 24 hours. Prose was never going to fix this.

## 3. What changed

**Release build**
- `android/daengtisia-clinic/app/proguard-rules.pro` — minimal *by verification*.
  Manifest-declared components are auto-kept by AGP; there is no
  `addJavascriptInterface`, no reflection, no JNI; and the device-proof message
  is built from **string literals**, so R8 cannot alter a byte the device signs.
  Had it derived from Kotlin symbol names, obfuscation would have broken device
  attestation in release builds only. Line numbers are kept — there is no
  crash-reporting SDK uploading a mapping file.
- `release_proguard_files_present` — new scanner check. Walks balanced
  parentheses, because a naive `\(([^)]*)\)` stops at the close paren belonging
  to `getDefaultProguardFile` and truncates the list before the local file it
  was meant to check. The SDK default is excluded on purpose.

**Release provenance — two commits, not one**
- The manifest now carries `release_source_sha` + `release_source_tree` instead
  of the ambiguous `git_commit`, validated as real git object ids with an
  end-of-string anchor (`/D` — without it PHP's `$` also matches before a
  trailing newline, the exact defect the predecessor shipped in two regexes).
- `config/android_release.release_provenance` declares the model:

      RELEASE SOURCE   the commit + tree the APK was compiled from.
                       Clean, frozen, CI-green BEFORE signing.
      FINAL CANDIDATE  the evidence-only commit recording digest, signer and CI
                       run. Merged and deployed. Passes CI again on its tree.

  Conflating them is provenance forgery, not bookkeeping: an evidence commit
  posing as the build source asserts a build that never happened from that
  tree, and the signature would appear to vouch for code the signer never saw.
  No production manifest exists yet, so this was the one moment the rename was
  free.

**The REPL guard**
- `App\Support\Deploy\ProductionShellCommandGuard` + `deploy:forbidden-command-check`,
  wired into `scripts/sprint-release.sh` **before** the deploy runner opens a
  connection. No `--force`, no `--strict` escape hatch.
- Patterns live in `config/release_safety.php` so the guard never contains the
  literal it forbids — otherwise the scanner reddens on itself and gets deleted.
- All four sites above are fixed: `artisan env` for the environment, a `sed` on
  the manifest for the tag, `satusehat:diagnose` for the runbook.
- Registered in `ci_runner.critical_gate_mandatory_suites` **and** given a
  filter token, because the registry cross-checks selection by literal
  substring and a guard nothing selects is prose again.

## 4. Verified

| | |
|---|---|
| `assembleRelease` | **BUILD SUCCESSFUL** (was: failed at R8) |
| Artifact | `app/build/outputs/apk/release/app-release-unsigned.apk` |
| applicationId | `com.daengtisia.clinic` |
| versionName / versionCode | `0.3.0-phase3` / `1` |
| Signature state | `DOES NOT VERIFY — Missing META-INF/MANIFEST.MF` (**unsigned, by design**) |
| `tests/Feature/DoctorDevice` | 382 passed, 0 failed |
| `deploy:forbidden-command-check` | refuses the REPL (exit 1), permits `migrate --force` (exit 0) |
| `android:release-readiness` | **GO — 43/43 PASS, 0 WATCH, 0 FAIL** |
| Enforcement | `DEVICE_ENFORCEMENT_ACTIVE=false` — unchanged |

Key material topology, verified rather than assumed: the only key material this
application ever sees is **public** (device attestation). No PHP loads a PKCS12;
neither CI nor `scripts/deploy-vps.sh` references signing key material.

## 5. Full Suite — the ambiguity the predecessor left open

The predecessor reported that the classifier requested `run_full_suite=required`
while the job was skipped, and did not resolve whether that was legal. It is:

- `.github/ci-policy/full-suite-policy.json` → **`status: ACTIVE`**, authorised
  by explicit project-owner decision, effective 2026-08-19.
- The classifier's `run_full_suite` is a **risk signal**, not an authorisation.
  The suite is gated by event *plus* policy, and `pull_request` never enabled it
  (`FULL_SUITE_NOT_ENABLED_FOR_EVENT`).
- Retirement is reserved for the consolidated closure and is explicitly
  forbidden as a side effect of another sprint.

So for this sprint the Full Suite is **DEFERRED** — reported as deferred, never
as passed. The semantics are already pinned mechanically by
`tests/Feature/Cicd/TemporaryFullSuiteScheduleGateTest.php`, so no duplicate
control was added.

## 6. The signed artifact

The ceremony ran on Custodian 1 with swap off, the vault opened interactively,
and the password prompted rather than passed. The vault and mapper were closed
before swap was restored. No key, keystore or password entered git, CI or the
VPS, and none was regenerated.

| | |
|---|---|
| Artifact | `DaengtisiaMS-Clinic-v0.3.0-phase3-production.apk` |
| Size / sha256 | 784,404 bytes &middot; `ab3e30df111ca3cfb6aa5efeb37dde1b3624e822c88134065c2c314a2fd10a03` |
| Built from | `1fecab7b` / tree `0f6544f2`, CI run `34018374405` |
| `apksigner verify` | **exit 0 — Verifies** |
| Schemes | v2 **true**, v3 **true**; v1, v3.1, v4 false &middot; zipalign PASS |
| Signers | 1 &middot; `CN=DaengtisiaMS Android Production, OU=IT, O=DaengtisiaMS, C=ID` |
| Signer certificate | `79db269b…a2d9` &middot; RSA 4096 |
| Pin | `79db269b…a2d9` &mdash; **match** |

The digest was taken **after** signing. The signer was extracted **from the
finished APK** with `apksigner verify --print-certs` and compared against the
pin held in source control — not config against config. That closes the
residual the certificate-pin sprint could not close, because no signed artifact
existed then.

`php artisan android:verify-release <apk> <manifest>` returns **PASS on 14/14
checks, exit 0**, including `signer_fingerprint`, `release_source_provenance`
and `manifest_matches_trust_anchor`.

## 7. What the ceremony left behind

Two findings, both fixed rather than tidied away:

- **`.idsig` was not ignored.** `apksigner` writes a v4 signature beside every
  APK it signs. The ignore list covered `.apk`, `.aab` and every keystore
  extension and missed this one, so the first real ceremony produced signing
  output that git offered to commit. Now a governed
  `required_gitignore_patterns` entry, not a one-off `.gitignore` edit.
- **An unsigned intermediate sat beside the signed artifact**, sharing its
  prefix and differing only by suffix — a substitution hazard. Verified
  byte-identical to the build output (so nothing was lost) and removed.

## 8. Not done, and deliberately not faked

Distribution, installation, tablet, ADB, device enrolment, pilot enforcement
and global enforcement. `DEVICE_ENFORCEMENT_ACTIVE=false`, and the release
manifest asserts every rollout flag false under test.

A signed artifact unlocks none of that. `approval_status: approved` gates
whether a sanctioned release MAY be installed; it is not a record that anything
was. Untouched throughout: certificate pin, custody topology, Backup 1,
Backup 2, and the signing key itself.
