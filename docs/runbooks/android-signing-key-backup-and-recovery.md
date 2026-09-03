# Runbook — Android signing key backup and recovery

**REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.**
Authority: [signing governance](../governance/android-production-signing-governance.md) ·
[ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md).

Scope: the **DaengtisiaMS production app signing key**.

> **This is the unrecoverable one.** Play App Signing is **NOT used**, so there
> is no Google-held key behind this and no upload-key reset to fall back on.
> Under the superseded ADR 0009 the clinic held only a resettable upload key;
> it now holds the app signing key itself.
>
> Losing it means the app can never be updated. Recovery requires uninstalling
> and reinstalling on every device — and uninstall erases app data, which holds
> the Android Keystore device identity, so **the entire fleet re-enrols by
> hand**. Three copies and a 90-day drill exist because of exactly this.

> **Status:** the production key does not exist yet. The procedure below has
> been **rehearsed end to end with a disposable throwaway key** (§5) so it is
> known to work before it matters. That rehearsal is not a production key event
> and must never be reported as one.

---

## 1. Generate the production signing key (once)

Run as the **signing custodian**, on a trusted machine, not in a repository
directory.

```bash
keytool -genkeypair -v \
  -keystore daengtisia-clinic-release.jks \
  -alias daengtisia-clinic-release \
  -keyalg RSA -keysize 4096 \
  -validity 10000 \
  -storetype pkcs12
```

- `-validity 10000` (~27 years) because a signing certificate that expires
  mid-life is an avoidable outage on a key that cannot be replaced.
- Choose a long random passphrase. Store it in the organisation password
  manager, **in a separate entry from the keystore file**.
- Never pass the passphrase on the command line — shell history is a file.

Export the public certificate. It is the canonical signer identity that every
installer verifies against, and it is **public** — publish its fingerprint, never
the keystore:

```bash
keytool -export -rfc \
  -keystore daengtisia-clinic-release.jks \
  -alias daengtisia-clinic-release \
  -file release_certificate.pem
```

The certificate is **public**. The keystore is not.

Record the fingerprint — it is a public identifier and it is how you later prove
a restored keystore is the same identity:

```bash
keytool -list -v -keystore daengtisia-clinic-release.jks | grep -i 'SHA-256'
```

---

## 2. Back it up

Three copies, all encrypted, one offsite, one sealed cold.

```bash
# Copy 1 — primary custodian, encrypted offline medium
gpg --symmetric --cipher-algo AES256 \
    --output daengtisia-clinic-release.jks.gpg \
    daengtisia-clinic-release.jks

# Copy 2 — recovery custodian, OFFSITE. Same ciphertext, different holder.
# Copy 3 — sealed cold copy, opened only on a declared key-loss incident.
```

Checklist:

- [ ] both copies encrypted at rest
- [ ] passphrase stored separately from both copies
- [ ] copy 2 physically offsite
- [ ] copy 3 sealed, holder recorded, not routinely accessed
- [ ] restore drill scheduled (**90 days**)
- [ ] custodian roles recorded in the operations register

**Never** back up to: a git repository, an unencrypted shared drive, chat, email,
or an unencrypted cloud sync folder. Each of those creates copies nobody can
count and nobody can revoke.

---

## 3. Restore drill — every 90 days, and after any custodian change

A backup that has never been restored is a belief. Fifteen minutes.

```bash
WORK="$(mktemp -d)"          # outside any repository
cd "$WORK"

# 1. restore from the encrypted backup only — do not touch the primary
gpg --decrypt --output restored.jks /path/to/backup/daengtisia-clinic-release.jks.gpg

# 2. the restored keystore must be the SAME identity
keytool -list -v -keystore restored.jks | grep -i 'SHA-256'
#    -> must equal the fingerprint recorded in §1

# 3. it must actually sign
keytool -exportcert -keystore restored.jks -alias daengtisia-clinic-release >/dev/null

# 4. leave nothing behind
shred -u restored.jks 2>/dev/null || rm -f restored.jks
rm -rf "$WORK"
```

Record: date, who, fingerprint matched (yes/no), issues. Do **not** record the
passphrase or the keystore bytes.

---

## 4. Incidents

### 4.1 Signing key lost — the incident that matters

**There is no reset.** Play App Signing is not used, so no third party can
reissue this identity.

1. **Restore from backup (§3).** If any of the three copies survives, you are
   done. This is why there are three.
2. **If every copy is gone**, the app can never be updated again under this
   identity. There are only two ways forward, and both are expensive:
   - publish under a **new package name** with a new key — every device installs
     a different app and re-enrols; or
   - uninstall and reinstall on each device — uninstall erases app data, which
     destroys the Keystore identity, so **every device re-enrols by hand**.
3. Declare it an incident, not a task. Notify the clinic owner. Plan
   re-enrolment capacity before touching any tablet.
4. Devices in the field keep working meanwhile — the installed app is unaffected
   by the key being lost. What is lost is the ability to ship anything new.

This is the risk ADR 0010 accepted on the owner's decision. The compensating
control is entirely procedural: three copies, one offsite, one sealed, and a
90-day drill that proves at least one of them restores.

### 4.2 Signing key compromised

More serious than loss, because an attacker who holds this key can sign an APK
that installs as an **update** over the legitimate app, inheriting its package
identity and its data.

1. **Stop all distribution immediately** — remove artifacts from the release
   source.
2. Notify all three custodians and the clinic owner.
3. Audit the release source for artifacts DaengtisiaMS did not publish.
4. Verify the installed signer fingerprint on **every** deployed device:
   `adb shell dumpsys package com.daengtisia.clinic | grep -i signature`, or
   re-verify the APK you believe is installed.
5. Recovery realistically means a **new signing identity and a new package
   name**, because the compromised certificate can no longer be trusted as the
   update identity. Plan the incident on that basis.
6. Rotate the keystore passphrase and re-issue all backups for the new key.

Note the asymmetry with the Play era: a compromised *upload* key only let an
attacker submit a build to a Play account that Google would still re-sign. A
compromised *app signing* key lets them produce an artifact that installs
directly onto clinic tablets. There is no intermediary to catch it, which is why
the installer's verification step (SHA-256 + signer fingerprint against the
manifest) is not optional.

### 4.3 The warning this replaces

The superseded version of this runbook said:

> "If Play App Signing were ever abandoned in favour of self-managed signing,
> loss becomes **unrecoverable** and every device enrolment is destroyed on the
> forced reinstall — that change requires a new ADR, not a decision made in a
> hurry."

That is exactly what happened, and the condition was met:
[ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md) records
the decision, the accepted risk and the compensating custody rules. The warning
is kept here because it remains the accurate description of the risk now being
carried.

### 4.4 Custodian departs

1. Recover their copy or confirm its destruction.
2. Rotate the keystore passphrase.
3. Re-issue **all three** encrypted backups.
4. Update the operations register.
5. Run the restore drill (§3) to prove the new arrangement works.
6. Confirm a replacement custodian is appointed — operating on two copies is a
   temporary state, not a new normal.

---

## 5. Phase 3.5 rehearsal evidence

The procedure above was executed end to end during Phase 3.5 with a **disposable
throwaway key**, generated outside the repository and destroyed afterwards:

| Step | Result |
|---|---|
| Key generated (RSA 4096, PKCS12) | PASS |
| Artifact **v1** signed, `jarsigner -verify` accepted it | PASS |
| Encrypted backup created (AES-256) | PASS |
| Backup is opaque — not readable as a keystore | PASS |
| Primary destroyed (`shred`, loss simulated) | PASS |
| Restored **from the encrypted backup only** | PASS |
| Certificate fingerprint identical after restore | PASS |
| Artifact **v2** signed with the restored key | PASS |
| **Signer certificate of v1 == signer certificate of v2** | PASS |
| Disposable material destroyed | PASS |

The last row is the one that matters, and it is easy to get wrong. Comparing the
PKCS#7 signature blocks is *not* the test — those are signatures over each jar's
own content, so they differ even under an identical certificate, and a naive
comparison reports a false failure. What an Android update actually requires is
that the **signer certificate** match, so the drill reads that out of each
signed artifact with `keytool -printcert -jarfile` and compares the SHA-256.

That is the property being proven: after a total loss and a restore, the
identity is unchanged, so version 2 still installs as an *update* over version
1 rather than being refused.

```
SIGNING_RECOVERY_RUNBOOK_TESTED_WITH_DISPOSABLE_KEY = true
PRODUCTION_KEY_RECOVERY_VERIFIED                    = false
```

The second line matters. A disposable key proves the **procedure**; it says
nothing about a production key, which does not exist. Never collapse the two.
