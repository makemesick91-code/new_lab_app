# PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1

**STATUS: BLOCKED — awaiting the human signing ceremony. No GO tag. Not merged.**

Base: `5c50fdd74c5f5e92a3740cd1cb79e6169803efe2`
(tree `75ad7d8d2f8c9a785caeda1f24aa288882a6a556`, tag
`production-android-signing-certificate-pin-1-go`, tag object
`45afdefcfc4f6282d14274eca0777913a8094da0` — all four verified independently).

Objective: produce the first authoritative production-signed release APK and
its evidence, signed by the existing permanent production key. That objective
is **not** met, for one reason and one reason only: the signing ceremony is a
human-custodian action (below). Everything that does not need the private key
is done, and the ceremony's input artifact now exists for the first time.

---

## The finding: the release build was impossible

`android/daengtisia-clinic/app/build.gradle.kts` line 43 reads

```kotlin
proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
```

`app/proguard-rules.pro` **has never existed in this repository.** `assembleRelease`
therefore failed at `:app:minifyReleaseWithR8`:

```
Supplied proguard configuration does not exist: .../app/proguard-rules.pro
```

Three sprints built the machinery around a release artifact — the artifact
verifier, the signer-fingerprint resolver, the certificate pin, the custody
model, a 42-check readiness gate that reported **GO** — and none of them ever
ran `assembleRelease`. The production APK could not have been built on any day
of that period.

### Why governance did not catch it

`config/android_release.php` requires the release block to *contain the string*
`proguardFiles` (`scanner.required_release_block_markers`). Nothing asserted
that the file that call **names** exists. A marker proving a string is present,
with no check that the thing it names exists, is exactly the orphaned predicate
rule 147 is about — and this is its second instance.

---

## What changed

1. **`android/daengtisia-clinic/app/proguard-rules.pro`** — new. Minimal *by
   verification, not by neglect*; each category was checked against the source
   before concluding no keep rule was needed:
   - framework entry points are manifest-declared, so AGP generates their keep
     rules — restating them would create a second, drifting source of truth;
   - there is deliberately no `addJavascriptInterface` / `@JavascriptInterface`;
   - no reflection, JNI, or dynamic loading anywhere;
   - **the device-proof wire protocol is built from string literals**
     (`daengtisiams-device-proof|v1|…`), not from Kotlin symbol names, so R8
     cannot alter a byte the device signs. Had the message been derived from
     symbol names, obfuscation would have broken device attestation in
     production builds only.

   A blanket `-keep class com.daengtisia.**` would have gone green faster and
   disabled most of the shrinking this build type exists to apply. It was not
   used. Line numbers are kept (`-keepattributes SourceFile,LineNumberTable`)
   because there is no crash-reporting SDK uploading a mapping file.

2. **`release_proguard_files_present`** — new scanner check closing the gap.
   It walks balanced parentheses rather than using one regex, because a naive
   `\(([^)]*)\)` stops at the close paren belonging to `getDefaultProguardFile`
   and silently truncates the list before the repository-local file it was
   meant to check. `getDefaultProguardFile(...)` is excluded on purpose: it
   resolves inside the SDK, and a control that reddens on a correct build file
   is one people learn to delete.

3. **Three tests**, written failing first: the real tree passes *and names the
   file it resolved* (a parser that extracted nothing would also pass); the SDK
   default is not mistaken for a repository file; and a synthetic tree
   reproducing the shipped defect is detected, then goes green when the named
   file appears.

## Verified

| Fact | Value |
|---|---|
| `assembleRelease` | **BUILD SUCCESSFUL** (was: failed at R8) |
| Artifact | `app/build/outputs/apk/release/app-release-unsigned.apk`, 738,069 bytes |
| applicationId | `com.daengtisia.clinic` |
| versionName / versionCode | `0.3.0-phase3` / `1` |
| minSdk / targetSdk | 26 / 35 |
| Signature state | `DOES NOT VERIFY — Missing META-INF/MANIFEST.MF` (**unsigned, by design**) |
| Unsigned digest | `a9b80e2b87ceacb5d0a01c1c3a97e6ff41fb297aa1e7e8761e5f1b95542270d1` — **NOT** the release hash |
| `tests/Feature/DoctorDevice` | 374 passed, 1 risky, 0 failed |
| `android:release-readiness` | **GO — 43/43 PASS, 0 WATCH, 0 FAIL** (42 + the new check) |
| Enforcement | `DEVICE_ENFORCEMENT_ACTIVE=false` — unchanged |

The unsigned digest is recorded only to identify the ceremony's input. The
authoritative `artifact_sha256` must be taken from the **signed** APK, after
signing, per `release_metadata_sha256_fields`.

---

## Why this sprint stopped

The ceremony is not blocked by tooling. It is blocked because it is a human
control, and the repository says so: `release_signing_context =
designated_signing_workstation_manual_approval`, `pull_request_ci_may_sign =
false`.

1. **The passphrases are the custodian's and nobody else's.** The LUKS vault
   passphrase and the PKCS12 password were never held by this session and must
   never be pasted into a chat, an argv, or an environment variable. No
   terminal fixes that.
2. **No interactive input exists here** — `tty` reports not a tty, `TERM=dumb`.
3. **`sudo -n` fails**, so `swapoff /swap.img` — a precondition for exposing
   the private key — cannot be performed.

No workaround was attempted. `cryptsetup --key-file`, a piped passphrase,
`-storepass`, or a sudoers entry would each have converted a custody control
into a convenience.

## The ceremony, for the custodian

Run on Custodian 1 only, at the frozen candidate, **after** authoritative CI
(the manifest binds `ci_run_id` and `git_commit`).

```sh
# 0. apksigner must be on PATH or android:verify-release reports it missing:
export PATH="$ANDROID_HOME/build-tools/35.0.0:$PATH"

# 1. swap off — verify empty before the key is exposed
sudo swapoff /swap.img && swapon --show

# 2. open the vault interactively (no keyfile, no piped passphrase)
sudo cryptsetup luksOpen ~/.local/share/daengtisiams-signing/production-signing-vault.luks \
     daengtisiams-signing-vault
sudo mount /dev/mapper/daengtisiams-signing-vault ~/DaengtisiaMS-Signing-Vault

# 3. sign — apksigner PROMPTS for the passwords; never pass them as flags
zipalign -p -f 4 app-release-unsigned.apk DaengtisiaMS-Clinic-v0.3.0-phase3.apk
apksigner sign \
  --ks ~/DaengtisiaMS-Signing-Vault/daengtisia-clinic-production.p12 \
  --ks-type PKCS12 --ks-key-alias daengtisia-clinic-release \
  DaengtisiaMS-Clinic-v0.3.0-phase3.apk

# 4. verify, then hash the SIGNED file (order matters)
apksigner verify --verbose --print-certs DaengtisiaMS-Clinic-v0.3.0-phase3.apk
sha256sum DaengtisiaMS-Clinic-v0.3.0-phase3.apk

# 5. close before restoring swap
sync && sudo umount ~/DaengtisiaMS-Signing-Vault
sudo cryptsetup close daengtisiams-signing-vault
sudo swapon /swap.img
```

The signer certificate SHA-256 must equal the pin
`79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9`.
**If it does not, stop. Do not change the pin to match the APK, and do not
generate another key.**

Then write `DaengtisiaMS-Clinic-v0.3.0-phase3.release.json` with the eleven
`release_metadata_required` fields and run
`php artisan android:verify-release`, which re-reads the certificate **out of
the APK** via `apksigner verify --print-certs`. That is the independent
measurement the certificate-pin sprint could not make: signer-from-artifact
against pin-from-source, not config against config.

## Not done, and deliberately not faked

APK signing · signer verification · release manifest · merge · deploy · GO tag ·
authoritative CI (the candidate cannot be frozen while the manifest is still
missing, so dispatching CI now would validate a tree that must change).

`docs`-only items carried forward: the §17 predeploy Tinker/PsySH guard was
not implemented — it cannot land through a sprint that cannot merge, and it
deserves its own candidate rather than a ride on this one.

Untouched throughout: tablet, adb, pilot enforcement, global enforcement,
certificate pin, custody topology, Backup 1, Backup 2, the private key.
