# PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1

**Status:** CLOSED — GO
**Supersedes:** `docs/sprints/production-android-signing-key-provisioning-1-blocked.md`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Inherits:** `revision-production-signing-custodian1-encrypted-vault-1-go`
**Next task:** `PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1`

---

## What happened

The production Android application signing identity was **generated exactly
once**, on Custodian 1, inside the LUKS2 vault that
`REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1` built for it. Two
encrypted backups were written to their destinations, and **both were restored
from the destination copy** and proven to yield a usable private key.

This sprint is the engineering half of that work: it moves the governance
record from *ready* to *provisioned, backed up and recovery-verified*, and it
closes the gate defects that the move exposed.

The physical ceremony itself is not scriptable and was not scripted. The vault
passphrase, the keystore password and both backup passphrases were typed at a
real TTY by the operator. **No agent knows any of them**, and none of them
appear in argv, an environment variable, a file, a log, this repository or this
document.

## The identity

A certificate fingerprint is a **public identifier** — it is derived from the
certificate, which ships inside every signed APK. Recording it discloses
nothing.

| Field | Value |
|---|---|
| Alias | `daengtisia-clinic-release` |
| Key format | PKCS12, `PrivateKeyEntry` |
| Algorithm | RSA 4096 |
| Certificate | self-signed |
| Distinguished name | `CN=DaengtisiaMS Android Production, OU=IT, O=DaengtisiaMS, C=ID` |
| Validity | 2026-09-06 → 2054-01-22 |
| SHA-256 | `79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9` |

**This identity is permanent.** `key_loss_recoverable` is false and
`app_signing_key_rotation` is `constrained_treat_as_permanent`. There is no
second attempt, and a second signer is never the answer to a failing gate — it
is a permanently divergent app identity that can only be installed by
uninstalling the first, erasing every enrolled device's app data.

## Custody outcome

| Destination | Holder | Artifact | Integrity | Recovery |
|---|---|---|---|---|
| Primary | Custodian 1 — IT workstation, Cabang Pusat | PKCS12 in the LUKS2 vault | vault closed, mapper closed after the ceremony | source of truth |
| Backup 1 | Custodian 2 — PC Admin Klinik, Windows | `daengtisia-clinic-signing-backup1-v2.gpg` | destination SHA-256 matched the source | **restored from the destination copy**, `PrivateKeyEntry`, fingerprint equal to primary, private key proven usable |
| Backup 2 | Custodian 3 — USB, Kantor Management Klinik | `daengtisia-clinic-signing-backup2-v2.gpg` | destination SHA-256 matched the source | **restored from the destination copy**, same result |

Encrypted-artifact SHA-256 (the `.gpg` files, not the key):

- Backup 1 v2 — `9368f15988ed6d3f9c2a8e7bb3266cf33a1e852ea289c9d89593557a7db83e35`
- Backup 2 v2 — `a42e3a41aff984edbdbf3e4688c2d0092244d2b38fe06bf4baed7e442279c64d`

Three-way public identity match established: primary == backup 1 restored ==
backup 2 restored.

**The negative recovery test matters as much as the positive ones.** A
disposable copy of the Backup 2 artifact was truncated by 32 bytes and GPG
rejected it. Without that, two passing restores would only have shown that the
procedure returns *something*, not that it would notice damage. The durable
backups were not touched; only a scratch copy in RAM was corrupted, and the
workspace was removed afterwards.

**Backups were verified from the destination, never from the source.** Hashing
the file you just sent proves the file you just sent. Only reading it back from
where it now lives proves the destination. This is the single most repeatable
lesson from this sprint.

### Password exposure, and why it did not become a key exposure

During the ceremony the original PKCS12 password was exposed in interactive
terminal output that was pasted into an AI conversation. It was rotated with
`keytool` **before the v2 backups were created**, without regenerating the RSA
private key or the certificate — same alias, same `PrivateKeyEntry`, same
identity, same fingerprint. Both retained backups are the post-rotation
artifacts; the pre-rotation copies were retired from both destinations.

Recorded state: **no current credential is exposed**, a historical exposure
occurred and was remediated by rotation, and there is **no evidence the private
key was ever exposed**. Rotating the password rather than the key was the
correct call: the key is irreplaceable, the password is not.

### Residual risks, recorded rather than smoothed over

Both are carried forward from the custody sprint and remain accurate:

1. **Three media are not three people.** IT is responsible for custodians 1 and
   3 and has access to custodian 2, so one party can reach every copy. The
   scanner *derives* this from the recorded access lists rather than accepting
   a declaration, and five compensating controls are recorded. A second
   independent human custodian remains an open item before the `operational`
   state.
2. **Custodian 1's host is not full-disk encrypted.** A dedicated LUKS2 vault
   protects the keystore at rest; swap is a file on the unencrypted root, so it
   is taken down for any operation that opens the vault.

## What this sprint did NOT do

Asserted, not merely intended — each has a test:

- **The certificate is not pinned.** `production_certificate_sha256` is still
  `null`, so `android:verify-release` authenticates nothing and fails closed.
- No production APK was built or signed. No artifact was distributed.
- No tablet was touched, no ADB, no Device Owner, no kiosk.
- Phase 4A pilot enforcement is not activated; global enforcement is not
  activated; `enforcement.current_stage` is `off`.
- No private key, keystore or password reached this repository, the production
  VPS or CI, and CI does not require any of them.
- No Google Play, Managed Google Play or Play App Signing dependency exists.

## The gate defects this exposed

Advancing the lifecycle is the first thing that had ever actually moved these
checks, and it broke two of them. Both were real defects, not friction.

### 1. A state machine whose own gate failed on lawful progress

`signing_custody_ready_for_provisioning` required
`status === 'ready_for_provisioning'` **exactly**. The moment custody advanced —
the entire purpose of the state machine it guards — the check went red.

A gate that fails on correct behaviour teaches the next operator that a FAIL
there is normal, which is how a gate stops being read at all. It now asserts
what it actually means: every destination is still prepared, and custody has
reached **at least** readiness. States below readiness still fail, and the
destination flags are still required in every state — so a destination cannot be
quietly retired after a key has been written to it.

### 2. One field answering two questions

`custody_state_machine_consistent` required a key claimed as provisioned to have
a certificate **pinned**. While both facts were false at once that was adequate.
The moment a key existed it became a trap: the only way to state "the key
exists" was to also arm `android:verify-release`.

That would have pushed an operator to pin a certificate before the pinning task
had reviewed anything, purely to get a gate green — coupling a custody record to
an install-time enforcement switch.

So the claim is split, exactly as
`REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1` split
`disk_encryption` into a host fact and a vault control:

| Field | Meaning |
|---|---|
| `production_certificate_sha256_recorded` | **fact** — this is the certificate the production key belongs to |
| `production_certificate_sha256` | **decision** — the trust anchor that arms the verifier; still `null` |

The evidence field is not a loophole in the check it serves. Four rules now bind
the pair in both directions: a key without a recorded certificate fails; a
recorded certificate without a key fails; a pin without a key fails; and a pin
that disagrees with the recorded certificate fails. The recorded value must also
*be* a fingerprint — `'yes'` and `'TBD'` are rejected — and a test proves the
verifier does not fall back to it, so recording evidence can never silently arm
the install path.

### 3. A new gate: the status may not run ahead of the artifacts

Between "no downstream fact without its upstream one" and "readiness claims
nothing" sat an unguarded gap: **every state past readiness could be declared
with nothing behind it.** `status => 'operational'` with all six flags false
contradicted no rule, and `signing_custody_status` — the summary line a human
actually reads — would have announced it.

`custody_status_matches_recorded_facts` closes it. Each state's requirements are
cumulative, so a later claim cannot be satisfied by skipping an earlier one, and
`operational` additionally requires the certificate to be armed. The rule is
one-directional on purpose: a status may lag the facts, never lead them.

## Reporting

`PRODUCTION_SIGNING_KEY_PROVISIONED` now prints `true` for the first time, and
the most likely misreading of it is "so we can install a signed build". Two
lines were added immediately beneath it, following this command's own convention
of printing a fact next to the one it will be confused with:

```
PRODUCTION_SIGNING_KEY_PROVISIONED=true
PRODUCTION_CERTIFICATE_RECORDED=true
PRODUCTION_CERTIFICATE_PINNED=false
ANDROID_REAL_DEVICE_VALIDATION=false
ANDROID_REAL_DEVICE_HARDWARE_PREFLIGHT=PASS
DEVICE_ENFORCEMENT_ACTIVE=false
```

One further message was corrected rather than left standing. `preflight_unlocks_nothing`
printed "no production key", but its condition never read the custody key flag —
it reads the pin. The claim was always wider than its evidence and became simply
untrue once a key existed.

## Tests

Sibling suites were **carried forward, not deleted**. Several asserted the old
truth by pinning `false`; where the underlying invariant still holds it is now
asserted directly. Three cases are worth naming:

- Four custody mutation tests had become **no-ops**: they mutated a flag to
  `true` when `true` was now the real value, so they asserted nothing while
  still reading as guards. They mutate in the opposite direction now.
- The vault suite's V7–V9 proved "storage readiness implies nothing downstream"
  by pinning downstream facts to `false`. That was a stale-fact test. They now
  assert **independence** — the vault verdict is unchanged with each downstream
  flag flipped both ways — which is stronger and survives every future state.
- A blunt substring sweep for `passphrase` over the encoded config was written
  and then withdrawn: the compensating control
  `passphrase_held_separately_from_every_medium` is policy vocabulary, and a
  substring test would flag it while a real field named `p12_pw` sailed past.
  The check matches leaf **keys**, as the scanner does, for the same reason.

## Mutation results

22 mutants against the changed predicates. Seven (`M16`–`M22`) were added after
the security review, one per fix, because a fix without a mutant is a fix nobody
has shown to be load-bearing.

| Campaign | Killed | Survived | Not applied |
|---|---|---|---|
| 1 | 8 | 7 | 0 |
| 2 | 13 | 2 (`M04`, `M15`) | 0 |
| 3 | 14 | 1 (`M15`) | 0 |
| 4 — post-review | 17 | 2 (`M15`, `M20`) | 3 |
| 5 — final | **21** | **1** (`M15`, equivalent) | **0** |

Every survivor in campaign 1 was a real gap, and they all failed the same way:
the mutation was caught by a **different** rule firing on the same fixture, so
the rule under test was already dead while its test read as coverage. Six were
closed with isolating tests (P13–P18).

`M04` — "a certificate is pinned while no key is provisioned" — **survived
twice**. The first isolating attempt set the pin and cleared the key but left
the recorded fingerprint in place, so the FAIL came from the
*certificate-without-key* rule instead. Isolating it required clearing the
recorded fingerprint as well, leaving a pin over no key as the only
contradiction standing. That is worth recording: writing a test *aimed* at a
predicate does not mean the test *reaches* it, and only the mutation run said so.

`M20` — removing the denylist/shape walk over `signing.*` — **survived campaign
4**, and it exposed a blind spot in my own tests rather than in the code. Every
signing test written for the review fixes was being caught by the field
*allowlist*, which stops undeclared field NAMES and says nothing about what a
DECLARED field may hold. The closing tests therefore use allowlisted fields, and
one nested inside `signing.backup`, where the walk is the only thing between the
value and a committed file.

Campaign 4 also reported **three mutants `NOT-APPLIED`**, not killed: the review
fixes had moved their target source, so they silently stopped applying. The
harness reports that state distinctly rather than counting a no-op as a kill,
which is the only reason it was noticed — three phantom kills would otherwise
have inflated the score. All three were re-targeted against the current source
and verified to apply before the final run.

`M15` drops `$keyProvisioned` from the `backups_created` requirement. The
cumulative fold already folds the `key_provisioned` term in beforehand, so no
verdict can differ. Classified **equivalent by exhaustive enumeration**, and
**re-proven against the post-review predicate** rather than assumed to carry
over — the guard now also consults `$undefinedPastReadiness`, so the original
proof no longer described the code. All 4,096 (state × flag × position)
combinations produce identical verdicts.

The harness reverts **by copy**, never `git checkout --`, and the scanner was
verified byte-identical to the pre-mutation original after every campaign.

**`MUTATIONS_REAL_SURVIVORS=0`.**

### One false failure, and why it was not a defect

A regression run started while a mutation campaign was still executing produced
a single failure — `still requires every destination to be ready after the key
exists` — that did not reproduce. Both were operating on the same worktree, and
the mutation being applied at that moment, `M11`, removes precisely the
predicate that test asserts. The suite was reading a deliberately broken scanner
mid-flight.

Two processes must not run tests in one worktree at the same time when either of
them rewrites application source. The symptom is indistinguishable from a flaky
codebase, and the tempting response — re-running until green, or loosening the
assertion — would have removed a working test to accommodate a harness mistake.
The failure was reproduced-against rather than explained away: the same command
on a quiet worktree is green.

## Security review

An independent adversarial review of the diff found **no CRITICAL**. It
confirmed the pin stays null and the verifier fails closed, that the recorded
fingerprint has no path into the verifier, that nothing enables enforcement or a
Play dependency, and that the single-signing-authority checks were not loosened.

It also found six real defects, **one of which this sprint introduced**. Each was
reproduced against the real scanner before being fixed, and each now has a test
that records the exploit rather than only the corrected behaviour.

### HIGH — the new gate failed open, and my own relaxation removed its backstop

`custody_status_matches_recorded_facts` decided whether a status claimed
artifacts by looking it up in a hardcoded map — so a state **absent** from that
map was read as "claims nothing" and PASSed. Nothing constrains the membership of
`custody.states`, and check 2 no longer pinned the status to an exact value.

Those two facts compose. Appending one state, setting the status to it, and
leaving all six artifact flags false produced:

```
ALL SIXTEEN custody checks: PASS        Decision: GO
signing_custody_...preconditions_met:  true
```

— for a record claiming zero key, zero backups and zero recovery, with the
PASS message affirmatively stating the status "makes no artifact claim".

**This was created here.** Before this sprint, check 2 would have gone red on
`$status !== 'ready_for_provisioning'` and caught it; relaxing check 2 is what
removed the backstop that had been making the gap harmless. Position, not map
membership, now decides: a state at or past readiness with no requirement
defined is an unanswerable claim, and the only safe answer to an unanswerable
claim about an unrecoverable key is to refuse it.

### MEDIUM — `PRODUCTION_CERTIFICATE_PINNED=true` for a pin that arms nothing

The summary predicate was `is_string(...) && !== ''` while its sibling one line
above applied the fingerprint regex. With the pin set to `'TBD'` the command
printed `PRODUCTION_CERTIFICATE_PINNED=true` while the verifier rejected the same
value on its own regex and failed closed — inverting the exact reading the
printed pair was added to give the operator. Both now share one
`isCertificateFingerprint()` helper, so they cannot drift again.

### MEDIUM — the `signing.*` namespace was unpoliced, and this sprint put a field in it

`custodySecretLeaks()` walked `signing.custody.*` and nothing above it. A
hardware serial, the vault filesystem UUID or a passphrase hint could be
committed at `signing.*` and no control in the tree would notice — while the
check printed "no leaf key or value matches a passphrase, serial, identifier…".
The three things it named were the three that got through.

The pre-existing gap became load-bearing the moment this sprint added a field
there. Extending the leaf-key denylist alone does **not** close it: that denylist
matches EXACT keys, so `primary_workstation_serial` and `keystore_passphrase_hint`
walk straight past `serial` and `hint` — which is precisely why custodian entries
were given a field allowlist in the first place. So `signing.*` gets the same
mechanism: a 16-entry allowlist, plus the denylist and shape detection.

The two certificate fingerprints are the only shape exemption, named
individually so no future field inherits it by resemblance — and they are exempt
from *shape detection*, never from *being fingerprints*: a fingerprint field
holding a serial is still reported.

### LOW — a correct upper-case pin was reported as a substituted key

The pin/recorded comparison was case-sensitive while the verifier normalises.
`keytool -list` prints SHA-256 in upper case, so an upper-case paste is the most
likely form the pin will arrive in during the next task — and it produced
"the wrong key or a substituted one", a false accusation of substitution on the
one message an operator would act on hardest. Both sides are lower-cased now.

### LOW — a summary field named for a state that had passed

`signing_custody_ready_for_provisioning` became permanently `true` once check 2
stopped requiring an exact status. Under an invariant that exactly one production
key may ever exist, a machine-readable field named "ready for provisioning"
reading true forever is the wrong signal to leave in output an agent may act on.
Renamed to `signing_custody_provisioning_preconditions_met`, which is what the
checks behind it actually assert.

### Accepted, not fixed

The review noted that `docs/` records the two backup filenames and their
ciphertext SHA-256s, which is a confirmation oracle for anyone already holding a
custody medium. The digests are the integrity evidence for stages C and D and
were explicitly authorised for this record, so they stay — but the residual is
real and is written down here rather than implied away.

## Files

| File | Change |
|---|---|
| `config/android_release.php` | recorded-fingerprint field; custody lifecycle advanced; preamble corrected |
| `config/ci_runner.php` | new suite declared in the critical gate |
| `app/Support/Android/AndroidReleaseGovernanceScanner.php` | check 2 accepts lawful progress; check 11 split pin/recorded + 3 new rules; new `custody_status_matches_recorded_facts` (fails closed on an undefined post-readiness state); `signing.*` field allowlist + leak walk; shared `isCertificateFingerprint()`; case-insensitive pin comparison; renamed summary field; corrected preflight message |
| `app/Console/Commands/AndroidReleaseReadinessCommand.php` | prints the recorded/pinned split |
| `tests/Feature/DoctorDevice/DoctorDeviceAndroidSigningKeyProvisioningTest.php` | new — 29 tests |
| `tests/Feature/DoctorDevice/DoctorDeviceAndroidSigningCustodyReadinessTest.php` | carried forward to the new truth; no-op mutations inverted |
| `tests/Feature/DoctorDevice/DoctorDeviceCustodian1EncryptedVaultTest.php` | V7–V9 rewritten as independence tests |
| `tests/Feature/DoctorDevice/DoctorDeviceAndroidReleaseGovernanceTest.php` | asserts the reporting contract, not a frozen value |
| `tests/Feature/DoctorDevice/DoctorDeviceAndroidRealDevicePreflightTest.php` | asserts the pin, not the key |
| `tests/Feature/DoctorDevice/DoctorDeviceAndroidRuntimeIdentityReadinessTest.php` | asserts the pin, not the key |
| `docs/runbooks/android-signing-key-backup-and-recovery.md` | stages A–D closed; §1 marked history; do-not-regenerate warning |
| `.cursor/rules/147-android-production-signing-key-provisioned.mdc` | new durable rule |

## Deploy

No migration, no seed, no permission change. The gate must be the one production
actually runs, so this deploys.

```bash
# ON the VPS
cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh start
sudo -u daengtisiams php artisan android:release-readiness
```
