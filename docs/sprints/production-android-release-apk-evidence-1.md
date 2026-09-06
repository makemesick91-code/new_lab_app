# PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1

**STATUS: RELEASE SOURCE FROZEN — awaiting the human signing ceremony.**
Not signed, not merged, not deployed, no GO tag.

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

## 6. Why this sprint stops here

The ceremony is not blocked by tooling. It is a human control, and the
repository says so: `release_signing_context =
designated_signing_workstation_manual_approval`, `pull_request_ci_may_sign = false`.

1. **The passphrases are the custodian's and nobody else's.** They were never
   held by the automated session and must never be pasted into a chat, an argv
   or an environment variable. No terminal fixes that.
2. No interactive input exists in the automated environment (`TERM=dumb`).
3. `sudo -n` fails, so `swapoff /swap.img` — a precondition for exposing the key
   — cannot be performed.

No workaround was attempted. `cryptsetup --key-file`, a piped passphrase,
`-storepass` or a sudoers entry would each have converted a custody control into
a convenience.

## 7. The ceremony

See §"Ceremony" in `docs/runbooks/android-direct-apk-installation.md` and the
operator commands returned with this sprint's handoff. In order:

1. **CI green on RELEASE_SOURCE_SHA first** — the manifest binds `ci_run_id`.
2. `sudo swapoff /swap.img`, verify `swapon --show` is empty.
3. Open the vault interactively. No keyfile, no piped passphrase.
4. `zipalign`, then `apksigner sign` — passwords **prompted**, never flags.
5. `apksigner verify --verbose --print-certs`, then `sha256sum` the **signed**
   file. Order matters: a digest taken before signing identifies a file nobody
   will install.
6. Signer certificate must equal
   `79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9`.
   **If it does not, stop.** Do not change the pin to match the artifact.
7. `sync`, unmount, `cryptsetup close`, then restore swap.

`apksigner` is not on `PATH` by default — export
`$ANDROID_HOME/build-tools/35.0.0` first, or `android:verify-release` reports
the tooling missing and fails closed.

## 8. Not done, and deliberately not faked

APK signing · signer verification · release manifest · merge · deploy · GO tag.

Untouched throughout: tablet, adb, pilot enforcement, global enforcement,
certificate pin, custody topology, Backup 1, Backup 2, the private key.
