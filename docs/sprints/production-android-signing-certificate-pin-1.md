# PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1

**Status:** implemented, tested, mutation-verified
**Base:** `production-android-signing-key-provisioning-1-go` @ `195fb195`
**Durable rule:** `.cursor/rules/148-android-production-certificate-pinned.mdc`
**Next task:** `PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1`

---

## 1. What this sprint decided

The production Android signing key has existed since
PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1. Its certificate fingerprint was
**RECORDED** as evidence that a key exists, and deliberately **not PINNED**,
because pinning is a different question and belonged to its own task.

This is that task. `signing.production_certificate_sha256` moves from `null` to

```
79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9
```

which is the same certificate as `signing.production_certificate_sha256_recorded`
— a pairing the custody state machine already refuses to let disagree.

`android:verify-release` can now authenticate an artifact instead of failing
closed. With Google Play out of the distribution chain, that comparison is the
**only** control between a substituted APK and a clinic tablet.

**No key was generated, no vault opened, no PKCS12 read, no password typed.** A
certificate fingerprint is a public identifier: it ships inside every signed APK,
so committing it discloses nothing. Every control added here evaluates from
public data alone, and a test asserts that no keystore is tracked in the
repository.

---

## 2. Four predicates answering one question

Before this sprint, "is this a fingerprint, and is it the same one?" had four
answers in three files:

| Where | Predicate |
|---|---|
| `AndroidReleaseGovernanceScanner::isCertificateFingerprint()` (reporting) | `/^[0-9a-f]{64}$/i` |
| `AndroidReleaseArtifactVerifier::signerChecks()` (enforcement) | its own inline copy of that regex |
| custody state machine, `$pinned` | `is_string($v) && $v !== ''` |
| custody state machine, `$recorded` | `/^[0-9a-f]{64}$/` — **no `i` flag** |

The security review of the predecessor had already found what that costs: with
the pin set to `'TBD'` the command printed `PRODUCTION_CERTIFICATE_PINNED=true`
while the verifier rejected the same value and failed closed. The fix then was to
align two of the four by hand, which holds until the next edit to either.

They are now one class — `App\Support\Android\AndroidCertificateFingerprint` —
and **reporting and enforcement both call it**, so they cannot drift apart
without that file changing.

The fourth divergence was a live inconsistency nobody had hit yet: an upper-case
recorded fingerprint (the form `keytool -list` prints) reported
`PRODUCTION_CERTIFICATE_RECORDED=true` and, in the same scan,
`key claimed provisioned while no valid certificate fingerprint is recorded`.
One field, two verdicts, both printed. Now case-insensitive like its sibling.

### `isPresent()` is not a weaker `isValid()`

The class keeps **two** predicates and merging them would be a fail-open
regression that reads as a cleanup:

- **`isPresent()`** — does the field carry a *claim*? The lifecycle needs this.
  A pin of `'TBD'` written while no key is provisioned must still trip
  *"a certificate is pinned while no key is claimed provisioned"*. Under a
  validity-only predicate that junk becomes invisible to the rule that catches
  it.
- **`isValid()`** — is the claim a *fingerprint*? Reporting and install-time
  enforcement need this, because a non-fingerprint cannot authenticate anything.

This distinction was documented in a code comment **before any test enforced
it**, and the mutation campaign duly reported it as a survivor. See §5.

---

## 3. A latent validation defect the new tests found

Both replaced regexes were missing PCRE's `D` modifier. Without it, PHP's `$`
also matches immediately before a trailing newline, so:

```php
preg_match('/^[0-9a-f]{64}$/i', "79db…a2d9\n") === 1   // true, pre-existing
```

A trailing newline is exactly what a paste from a file or from `keytool` output
produces. The consequence was not a whitespace nit:

1. `signer_trust_anchor` reports the anchor **ARMED**.
2. The comparison against a clean signer fingerprint then fails on length.
3. The operator is told *"this APK was signed by a different key — do not install
   it"*.

A newline in config would have been reported as a **substituted signing key** —
the single message in this subsystem most likely to be acted on hardest and the
hardest to walk back. The pattern is now `/^[0-9a-f]{64}$/iD`, so the value fails
validation at the point where it is actually wrong.

Colon-separated input is **rejected, never normalized**: a normalizer that
strips separators also quietly accepts values a reviewer would reject. It fails
closed and the operator reformats.

---

## 4. The containment check that had to be replaced, not relaxed

`preflight_unlocks_nothing` asserts that the Phase-4A hardware preflight pass
moved nothing else. One of its clauses was:

```php
config('android_release.signing.production_certificate_sha256') === null
```

That was correct while the pin could only be null. It is the **same shape of
mistake the predecessor already had to correct once**: the same check used to
claim "no production key", read no key flag, and went silently untrue the moment
a key was generated under a separate authority. The predecessor fixed the
message and left the condition reading the pin — so this sprint hit the same wall
one field later, and would have failed with *"a real-device preflight pass has
been read as unlocking signing"* for a lawful, separately authorised decision.

**It was not deleted.** Rule 147's own durable lesson is that relaxing a
predicate orphans the gap a sibling was quietly covering. The clause is now
`noUnauthorisedTrustAnchor()`:

> the pin must be **absent**, or be **exactly the recorded production
> certificate**.

That is strictly narrower than `null` was broad. A well-formed anchor that is not
ours — precisely what "the preflight introduced a trust anchor" would look like —
still goes red, and so does junk. Three tests exist for this alone: the
legitimate pin passes, a rogue valid fingerprint fails, `'TBD'` fails, and a
preflight record that *claims* to unlock `certificate_pinning` fails.

Deciding whether the pin is *authentic* remains the job of
`custody_state_machine_consistent`, which requires the pin to equal the recorded
certificate **and** the key to be provisioned — a stronger owner of that question
than a containment check could be.

---

## 5. Tests and mutation

New suite `tests/Feature/DoctorDevice/DoctorDeviceAndroidCertificatePinTest.php`
(**22 tests / 185 assertions**), registered in
`ci_runner.critical_gate_mandatory_suites` — a filter-token match is an accident
of naming and nothing would say the day it stopped selecting.

It covers 27 rejected input vectors (null, empty, whitespace, `TBD`, placeholders,
wrong types, 63/65 hex, non-hex, embedded/leading/trailing whitespace, leading and
trailing newline, prefix and suffix junk, SHA-1 length, partial, colon form,
`0x`-prefixed), the canonical pin, upper and mixed case, one-nibble substitution,
prefix/suffix/substring non-matching, report-equals-enforcement across every
vector, private-key independence, the `signing.*` allowlist, the custody
regressions the predecessor paid for, the containment invariants, and the pilot
boundary.

**Ten stale assertions across five sibling suites were rewritten, not deleted.**
Each had pinned "not pinned" as a frozen value; each now asserts its own suite's
invariant — usually *"this sprint introduced no anchor of its own"*, expressed as
`pin == recorded`. Two were strengthened rather than merely updated:

- the no-fallback proof (*the recorded fingerprint must not arm the verifier*)
  only worked while the pin happened to be null; it now **sets the pin to null
  itself**, so the invariant stays testable for the life of the repository;
- `operational` custody status previously asserted a frozen `FAIL`; it now
  asserts the **rule** by mutating in both directions. The custody record itself
  stays at `recovery_verified` — a status may lag its facts, and moving it is a
  custody decision this task has no authority to take.

### Mutation campaign

**27 mutants, three campaigns, final 27 killed / 0 equivalent / 0 not-applied.
`MUTATIONS_REAL_SURVIVORS=0`.** M27 was added after security review — see §7.

The first campaign killed 25 and left **one real survivor: M18, tightening the
lifecycle's `isPresent()` to `isValid()`** — the exact fail-open regression the
code comment claimed to prevent. The existing ordering test could not see it
because it used a *well-formed* pin, for which both predicates agree. Isolating
it also required clearing the recorded fingerprint, or the sibling rule
("recorded while no key is provisioned") fails the check for a different reason
and masks the hole entirely.

A claim in a comment that no test defends is exactly the drift this programme
exists to catch. The killing test is now in the suite and M18 dies.

Harness reverts **by copy**, never `git checkout --`: the worktree carries
untracked files, and a checkout would neither restore them nor leave uncommitted
work alone.

---

## 6. What this did not do

Arming the anchor moved exactly one thing. All of these remain false and are
asserted false:

- production APK built, signed or distributed; tablet touched; ADB used;
- device enrolled; pilot enforcement activated; global enforcement activated;
- `enforcement.active` false, `current_stage` `off`, `pilot_activated` false.

`android:release-readiness` → **GO, 42/42 PASS, 0 WATCH, 0 FAIL**, with
`PRODUCTION_CERTIFICATE_PINNED=true` printed directly beneath
`PRODUCTION_CERTIFICATE_RECORDED=true` — this command's own convention of
printing a fact next to the one it will be confused with.

**Changing the pin in future is a signer rotation, not a config tweak.** The key
is unrecoverable and `app_signing_key_rotation` is
`constrained_treat_as_permanent`; a different anchor means every enrolled tablet
must be uninstalled to take an update, which erases app data and destroys each
device's Keystore identity.

---

## 7. Security review

**No CRITICAL, no HIGH.** The reviewer executed the predicate against 28 hostile
inputs — trailing `\n` and `\r\n`, leading newline, null bytes (lead/mid/trail),
embedded newline, 63/65 chars, colon form, `0x` prefix, fullwidth `０`,
Arabic-Indic `٠`, a 1 MB string, `int`/`float`/`bool`/`null`/`array` and a
`__toString()` object — and every one returned false. Locale was probed under
`tr_TR` (the classic `strtolower` trap) with no divergence, and it is unreachable
regardless since `I`/`i` fall outside `[0-9a-fA-F]`. Prefix, suffix and substring
matches are structurally impossible: `{64}` with `^…$D` fixes the length before
`hash_equals` ever runs.

Confirmed clean: reporting and enforcement genuinely converged on one object;
`isPresent()` is used at exactly one site (the custody lifecycle) and never by
the summary or the verifier; **the verifier has no path to the recorded
fingerprint at all** — its only signing config read is the pin; the `signing.*`
allowlist is name-based *and* shape-verified, so a passphrase parked in
`production_certificate_sha256` is flagged rather than exempted; the entire
`config/` change, comments stripped, is two lines; and no enforcement, pilot,
APK or device state moved.

### The one narrowing, and the detector that covers it — LOW, accepted

Replacing `=== null` with `noUnauthorisedTrustAnchor()` changes the verdict for
exactly one state: an attacker who edits **both** adjacent config lines — pin and
recorded — to the same foreign fingerprint. The blanket `=== null` would have
gone red; the new clause passes, because the pin does equal the recorded
certificate. `custody_state_machine_consistent` does not help here: it is the
*same* config-to-config comparison, so within the scanner there is one detector,
not two.

This was verified by mutation rather than argued. **M27 substitutes both
fingerprints coherently.** The scanner alone still reports `GO 42/42` — exactly
as the review predicted — and **the test suite fails with 5 failures**, because
`DoctorDeviceAndroidCertificatePinTest` hard-codes the canonical fingerprint as a
constant and asserts it against *both* config fields. That constant, rule 148 §1
and this document are the out-of-band anchor; the suite is registered in
`ci_runner.critical_gate_mandatory_suites`, so the coordinated edit cannot reach
the base branch.

Accepted rather than closed further, for two reasons. The old clause would have
failed on the **legitimate** pin too — it was a "no pin, ever" assertion that had
to be replaced for this task to exist. And any in-scanner detector would compare
config against config; a genuinely independent one would have to read the
certificate out of a signed artifact, which is
`PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1`, not this task.

## 8. Accepted residual

Carried forward from the predecessor and **not** re-litigated here: the earlier
sprint doc records the two encrypted-backup filenames and their ciphertext
SHA-256 values, which are a confirmation oracle for someone already holding a
medium. Those are authorised stage C/D integrity evidence; the residual is
written down rather than implied away, and removing legitimate evidence to
silence a scanner would be the worse trade.
