# Runbook — Android signing key backup and recovery

**FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5.**
Authority: [signing governance](../governance/android-production-signing-governance.md) ·
[ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md).

Scope: the **upload key** the clinic holds. The app signing key is held by
Google KMS under Play App Signing and is not backed up here — which is the point
of that decision.

> **Status as of Phase 3.5:** the production upload key does not exist yet. The
> procedure below has been **rehearsed end to end with a disposable throwaway
> key** (§5) so it is known to work before it matters. That rehearsal is not a
> production key event and must never be reported as one.

---

## 1. Generate the upload key (once)

Run as the **signing custodian**, on a trusted machine, not in a repository
directory.

```bash
keytool -genkeypair -v \
  -keystore daengtisia-clinic-upload.jks \
  -alias daengtisia-clinic-upload \
  -keyalg RSA -keysize 4096 \
  -validity 10000 \
  -storetype pkcs12
```

- `-validity 10000` (~27 years) because an upload certificate that expires
  mid-life is an avoidable outage.
- Choose a long random passphrase. Store it in the organisation password
  manager, **in a separate entry from the keystore file**.
- Never pass the passphrase on the command line — shell history is a file.

Export the upload certificate for Play Console registration:

```bash
keytool -export -rfc \
  -keystore daengtisia-clinic-upload.jks \
  -alias daengtisia-clinic-upload \
  -file upload_certificate.pem
```

The certificate is **public**. The keystore is not.

Record the fingerprint — it is a public identifier and it is how you later prove
a restored keystore is the same identity:

```bash
keytool -list -v -keystore daengtisia-clinic-upload.jks | grep -i 'SHA-256'
```

---

## 2. Back it up

Two copies, both encrypted, one offsite.

```bash
# Copy 1 — primary custodian, encrypted offline medium
gpg --symmetric --cipher-algo AES256 \
    --output daengtisia-clinic-upload.jks.gpg \
    daengtisia-clinic-upload.jks

# Copy 2 — recovery custodian, offsite. Same ciphertext, different holder.
```

Checklist:

- [ ] both copies encrypted at rest
- [ ] passphrase stored separately from both copies
- [ ] copy 2 physically offsite
- [ ] restore drill scheduled (180 days)
- [ ] custodian roles recorded in the operations register

**Never** back up to: a git repository, an unencrypted shared drive, chat, email,
or an unencrypted cloud sync folder. Each of those creates copies nobody can
count and nobody can revoke.

---

## 3. Restore drill — every 180 days, and after any custodian change

A backup that has never been restored is a belief. Fifteen minutes.

```bash
WORK="$(mktemp -d)"          # outside any repository
cd "$WORK"

# 1. restore from the encrypted backup only — do not touch the primary
gpg --decrypt --output restored.jks /path/to/backup/daengtisia-clinic-upload.jks.gpg

# 2. the restored keystore must be the SAME identity
keytool -list -v -keystore restored.jks | grep -i 'SHA-256'
#    -> must equal the fingerprint recorded in §1

# 3. it must actually sign
keytool -exportcert -keystore restored.jks -alias daengtisia-clinic-upload >/dev/null

# 4. leave nothing behind
shred -u restored.jks 2>/dev/null || rm -f restored.jks
rm -rf "$WORK"
```

Record: date, who, fingerprint matched (yes/no), issues. Do **not** record the
passphrase or the keystore bytes.

---

## 4. Incidents

### 4.1 Upload key lost

Recoverable. This is the whole reason for choosing Play App Signing.

1. Restore from backup (§3). If that succeeds, you are done.
2. If both copies are gone: Play Console → **Request upload key reset**.
   Generate a new upload key (§1), submit its certificate, wait for Google to
   register it.
3. Publishing is blocked until the reset completes. **Devices in the field are
   unaffected** — Google still holds the app signing key. This is a release
   outage, not a clinical one.
4. Update backups and record the new fingerprint.

### 4.2 Upload key compromised

Treat as lost, urgently.

1. Reset the upload key (§4.1) **before the next release**.
2. Audit Play Console release history for uploads the clinic did not make.
3. If an unrecognised release exists: halt its rollout, forward-fix, and treat
   it as a security incident.
4. Rotate the keystore passphrase and re-issue backups.

A compromised upload key lets an attacker *submit* a build to your Play account.
It does not let them sign an artifact that installs outside Play — a
meaningfully smaller blast radius than a compromised app signing key, which is
the trade this architecture bought.

### 4.3 App signing key

Held by Google KMS. Not ours to lose. If Play App Signing were ever abandoned in
favour of self-managed signing, loss becomes **unrecoverable** and every device
enrolment is destroyed on the forced reinstall — that change requires a new ADR,
not a decision made in a hurry.

### 4.4 Custodian departs

1. Recover their copy or confirm its destruction.
2. Rotate the keystore passphrase.
3. Re-issue both encrypted backups.
4. Update the operations register.
5. Run the restore drill (§3) to prove the new arrangement works.
6. Confirm the Play Console admin **role account** is still accessible — if
   administration ran through a personal account, fix that now.

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
