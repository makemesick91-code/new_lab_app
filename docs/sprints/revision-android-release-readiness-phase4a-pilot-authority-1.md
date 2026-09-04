# REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1

**Date:** 2026-09-04 (WITA, UTC+08:00)
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `e99bc212`
**Type:** RUNTIME_FIX + governance record. No migration, no permission, no route,
no schema, no clinical data touched.

Two unrelated things shipped together because the same operator question raised
both: *can we run the readiness gate, and are we allowed to run the pilot?*

---

## 1. The false FAIL

### What was observed

On production, the same command gave two different answers depending on who ran
it:

| Identity | Decision |
|---|---|
| `root` | `GO` — 22/22 PASS, 0 WATCH, 0 FAIL |
| `daengtisiams` (the runtime identity) | `FAIL` — 20/22 PASS, 0 WATCH, **2 FAIL** |

The two failures:

```
no_committed_key_material   FAIL  Could not read the git index to verify no key material is tracked.
gradle_wrapper_executable   FAIL  android/daengtisia-clinic/gradlew is not tracked by git.
```

The second message was the more expensive one. The wrapper **is** tracked, and
has been since Phase 3. Git had simply declined to open the repository, and the
check reported that refusal as a statement about the file.

### Root cause

```
$ sudo -u daengtisiams git -C /var/www/asia-dental-lab-v2 ls-files
fatal: detected dubious ownership in repository at '/var/www/asia-dental-lab-v2'
```

INFRA-SEC-RUNTIME-1 pairs a **root-owned deployed tree** (`root:root 755`) with
an **unprivileged application runtime** (`daengtisiams`, uid 999). Git refuses
to read a repository owned by another user. Both scanner git call sites
therefore failed, and both fell into their fail-closed branch.

The gate was behaving correctly — it could not verify the index, so it refused
to claim the index was clean. What was wrong was that a correct refusal looked
like a repository defect, and that the only way to get a green reading was to
run the audit as root.

### What was deliberately not done

- **Not** `safe.directory=*`. That trusts every repository on the host,
  including one an attacker can drop into a world-writable directory.
- **Not** a persistent `--global` / `--system` entry. It outlives the process,
  the deploy and the operator's memory, and nothing tests that it is still
  narrow.
- **Not** chowning the production repository. Root ownership is the
  INFRA-SEC-RUNTIME-1 boundary working, not a misconfiguration.
- **Not** "run everything as root". That makes the privileged account the only
  one that can check whether the tree is safe.
- **Not** treating a git error as "no key material found". That is the
  false-green the whole scanner exists to prevent.

### The fix

Every git invocation in `AndroidReleaseGovernanceScanner` now goes through one
private helper that prepends an **invocation-scoped** trust for exactly the
repository the scanner was constructed to audit:

```php
Process::path($this->basePath)->run(array_merge(
    ['git', '-c', 'safe.directory='.$this->basePath],
    $arguments,
));
```

`safe.directory` is only honoured from *protected* configuration. The command
scope is protected, which is why this works and a repository-local entry would
not. Verified empirically rather than assumed, on both git versions in play:

| Case | git 2.53.0 (dev) | git 2.43.0 (production) |
|---|---|---|
| Foreign-owned repo, no trust | refused (128) | refused — `detected dubious ownership` |
| `-c safe.directory=<that repo>` | 0 | 0, index read |
| `-c safe.directory=<a different path>` | refused (128) | — |
| Parent entry, **nested** repo | refused (128) | — |

The last row matters: the trust does not extend to child repositories, so a
repository planted under the app directory gains nothing from it.

### Error classification

Checks now carry a `reason`, so the two incidents stop looking identical:

| reason | meaning |
|---|---|
| `index_access_error` | git could not read the index; **no** conclusion about contents |
| `forbidden_key_material_found` | index read, forbidden artifact tracked |
| `not_tracked` | index read, file genuinely absent from it |
| `clean` / `tracked` | index read, expected state holds |

`gradle_wrapper_executable` no longer merges "git refused" with "file is
untracked" — they were one branch and one message, and they send an operator to
two completely different places.

**Fail-closed is unchanged.** An unreadable index is still FAIL. The only thing
that changed is that one specific, expected, architecturally-intended refusal is
answered instead of hit.

---

## 2. Phase 4A pilot authority

The owner has authorized a **PILOT** of doctor device enforcement:

| Field | Value |
|---|---|
| Authorized scope | `pilot_branch_or_device` |
| Pilot doctor | drg Karmila |
| Pilot branch | Cabang Sunu |
| Rollback owner | Custodian 1 / Raushan Fikri Ridha / IT |
| Global enforcement | **still prohibited until Phase 5** |

Recorded in `config/android_release.php` under `enforcement.owner_signoff`.

### `may_be_enabled_in_phase` — the decision

The pre-existing key held a single number, `5`, and after this sign-off it could
be read two ways: "nothing before Phase 5" (now wrong) or "Phase 4 is fine"
(dangerously broad — the global rung is one line further down the same ladder).

One number could not carry two answers, so the bound was split:

- `pilot_may_be_enabled_in_phase` → `'4A'`
- `global_may_be_enabled_in_phase` → `5`
- `may_be_enabled_in_phase` → **retained at 5**, because rule 141, ADR 0009/0011
  and both Phase-4 closure records cite it by name. It now also carries
  `may_be_enabled_in_phase_scope = global_doctor_enforcement`, and the scanner
  FAILs if it ever drifts from the global bound.

The existing five-rung ladder was reused. No parallel OFF/PILOT/GLOBAL
abstraction was built — rule 141 §17 forbids exactly that, and the ladder plus
`global_prerequisites` already expressed the distinction.

`global_enforcement_stage` names the global rung explicitly rather than deriving
it from the last array element, so appending a future rung cannot silently move
what "global" means.

### Authorization is not activation

Nothing was switched on. Verified in production after deployment:

| Signal | Value |
|---|---|
| `DEVICE_ENFORCEMENT_ACTIVE` | `false` |
| `DOCTOR_BROWSER_LOGIN_DENIED` | `false` |
| `enforcement.current_stage` | `off` |
| `doctor.trusted_device_enforcement` | default `false` |
| `owner_signoff.pilot_activated` | `false` |
| Pilot doctor targeted | no |
| `DoctorDeviceAuthorization` rows created | none |

drg Karmila logs in exactly as before. So does every other doctor.

The pilot doctor and branch are recorded **as written text**, not as production
row ids. Resolving them to real records belongs to the phase that runs the
pilot; an id copied between environments is how the wrong doctor gets enrolled.

---

## 3. Signing custody — unchanged, still partial

| Control | Status |
|---|---|
| Custodian 1 — Raushan Fikri Ridha / IT | established |
| Custodian 2 | **DEFERRED** by owner decision |
| Offsite backup | **DEFERRED** by owner decision |
| Custodian 3 | **DEFERRED** by owner decision |
| Sealed-cold backup | **DEFERRED** by owner decision |
| `PRODUCTION_SIGNING_KEY_PROVISIONED` | `false` |
| `PRODUCTION_CERTIFICATE_PINNED` | `false` (`production_certificate_sha256` is `null`) |

`SIGNING_CUSTODY_STATUS = PARTIAL`. No key was created, no certificate pinned,
no backup made.

### Why the gate says GO with no production key

Worth stating plainly, because the numbers invite the wrong inference. The 24
checks audit **repository release governance**: is the release build type
debug-signed, is key material tracked, is the wrapper committed executable, are
the five Phase-3 decisions recorded, is enforcement off. None of them assert
that a signed artifact exists.

Signed-artifact readiness is a **different** gate: `android:verify-release`, and
the `--manifest` path on this command. Both fail closed while
`production_certificate_sha256` is `null`, because there is no authority to
verify against. That separation is preserved exactly; this revision did not
touch it, and did not turn a missing signing prerequisite into a PASS.

Every run prints `PRODUCTION_SIGNING_KEY_PROVISIONED=false` and
`ANDROID_REAL_DEVICE_VALIDATION=false` for precisely this reason.

---

## 4. History

Phase 4 and the first Phase 4A attempt remain **BLOCKED / NO-GO**. Those records
are unchanged apart from a forward reference to this revision. This is a
prerequisite cleanup and a governance record, not a replacement attempt.

Neither `feature-doctor-trusted-android-device-lock-1-phase-4a-app-only-real-device-pilot-go`
nor `feature-doctor-trusted-android-device-lock-1-go` was created.

---

## 5. Next Phase 4A resume gates

Not executed here. In order:

1. Owner reopens the deferred custody controls (Custodian 2, offsite, Custodian 3,
   sealed cold) — or accepts running with Custodian 1 only, in writing.
2. Provision the production signing key on the IT signing workstation.
3. Back it up and prove restore.
4. Pin `production_certificate_sha256`.
5. Build and verify the production APK (`android:verify-release`).
6. Authorize ADB on the pilot tablet.
7. Real AndroidKeyStore `KeyInfo` validation.
8. Real cryptographic enrolment → automatic PENDING request.
9. Approval through the inbox.
10. **Then** activate pilot enforcement (`pilot_branch_or_device`), the step this
    revision authorizes but does not take.
11. Browser-login denial for the pilot doctor only.
12. Branch/room UAT, session revoke, same-signer update.

Global enforcement remains Phase 5 and is not reachable from any of the above.
