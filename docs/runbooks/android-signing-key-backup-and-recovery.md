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

## 0. Stage gates — where we actually are

*PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1, superseding
PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1 forward.*

This runbook covers seven stages. **Stages A to D are closed.** Each stage is a
separate authorised task; none of them may be folded into another because
"we were already in there".

| Stage | What it is | State |
|---|---|---|
| **A — Custody readiness** | custodians designated, media and controls recorded, destinations prepared | **CLOSED — GO** |
| **B — Key provisioning** | generate the production key on Custodian 1 (§1) | **CLOSED — GO** |
| **C — Backup creation** | write encrypted copies to Custodians 2 and 3 (§2) | **CLOSED — GO** |
| **D — Recovery verification** | restore drill proves a copy actually restores (§3) | **CLOSED — GO** |
| **E — Certificate pinning** | pin `production_certificate_sha256` from the real key | open |
| **F — APK signing** | build and sign a production artifact | open |
| **G — Device installation** | install on the pilot tablet, enrol, activate the pilot | open |

**A THE KEY NOW EXISTS.** It was generated exactly once, on Custodian 1, inside
the LUKS2 vault. It is **permanent production authority**: `key_loss_recoverable`
is false and `app_signing_key_rotation` is `constrained_treat_as_permanent`.

> **There is no second attempt.** Do not generate another production signing
> key, do not "rotate to a clean one", and do not create a second signer to get
> a gate green. If something appears wrong with the identity, STOP and report
> it — a replacement key does not fix a problem, it creates a permanently
> divergent app identity that can only be installed by uninstalling the first,
> which erases every enrolled device's app data.

Section 1 below is therefore **history, not an instruction**. It records how
the one key was created. Running it again is the failure this box exists to
prevent.

**Public identity of the production signer** (a certificate fingerprint is a
public identifier — it ships inside every signed APK):

| Field | Value |
|---|---|
| Alias | `daengtisia-clinic-release` |
| Key format | PKCS12, `PrivateKeyEntry` |
| Algorithm | RSA 4096 |
| Certificate | self-signed, `CN=DaengtisiaMS Android Production, OU=IT, O=DaengtisiaMS, C=ID` |
| Validity | 2026-09-06 → 2054-01-22 |
| SHA-256 | `79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9` |

**Stage E is NOT closed, and that distinction is load-bearing.** The fingerprint
above is RECORDED in `signing.production_certificate_sha256_recorded` as
evidence that a key exists. It is NOT PINNED:
`signing.production_certificate_sha256` is still `null`, so
`android:verify-release` authenticates nothing and fails closed. Pinning is the
separate task **PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1**. Until it runs,
no production APK may be installed on any device.

**What closing C and D actually required.** Both were verified by restoring
**from the destination copy** — the file as it sits on Custodian 2 and on the
Custodian 3 USB — never by re-hashing the artifact that was sent. A hash of the
source proves the source; only a restore from the destination proves the
destination. Each restore produced a `PrivateKeyEntry` under the production
alias, with a certificate fingerprint equal to the primary's, and the recovered
private key was proven usable by generating a certificate signing request. A
deliberately corrupted disposable copy was additionally rejected by the
decryption step, so the positive results are known not to be vacuous.

### The three destinations

| # | Role | Medium | Location | Responsible |
|---|---|---|---|---|
| 1 | primary signing authority | primary IT workstation, Ubuntu | Cabang Pusat | Raushan Fikri Ridha / IT |
| 2 | encrypted backup destination | Admin Klinik workstation, Windows | Klinik Daengtisia | IT + Admin Klinik |
| 3 | encrypted backup, sealed-cold, offsite | USB | Kantor Management Klinik | IT |

**Stage B runs on Custodian 1 and nowhere else.** Not the VPS, not CI, not
Custodian 2, not the USB, not a tablet, not a container.

**USB rules (Custodian 3).** Unrelated data already on the medium stays — no
wipe is required. Signing material goes into an encrypted container or it does
not go on at all. The passphrase never travels on the same medium. The USB is
offline except during an approved operation and does not return to general
daily use afterwards.

Verify the recorded posture at any time with:

```bash
php artisan android:release-readiness --strict
```

---

## 0. Open the Custodian 1 signing vault

**Custodian 1 is NOT full-disk encrypted.** `/` and `/home` mount from raw ext4
partitions. The encrypted-at-rest requirement is met by a dedicated **LUKS2
vault**, established by
REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1, and the keystore must
live inside it. A keystore written anywhere else on this host is written in the
clear.

The vault is **closed at rest**. Its full lifecycle for any signing operation:

```
OPEN → MOUNT → approved operation → SYNC → UNMOUNT → CLOSE
```

```bash
# Swap is a 4 GB file on the UNENCRYPTED root filesystem. Key material held in
# memory can page out in the clear, so take swap down for the operation.
sudo swapoff -a

sudo cryptsetup open \
  "$HOME/.local/share/daengtisiams-signing/production-signing-vault.luks" \
  daengtisiams-signing-vault              # prompts for the passphrase

sudo mount /dev/mapper/daengtisiams-signing-vault "$HOME/DaengtisiaMS-Signing-Vault"
cd "$HOME/DaengtisiaMS-Signing-Vault"
```

The passphrase is typed at the prompt. Never in argv, an environment variable,
a file, a script literal or a chat message. It is a **different** secret from
the keystore password and from both backup container passphrases.

Confirm the vault is genuinely open and you are inside it before continuing:

```bash
findmnt -no SOURCE,TARGET "$HOME/DaengtisiaMS-Signing-Vault"
# must print /dev/mapper/daengtisiams-signing-vault
```

---

## 1. Generate the production signing key (once)

**Stage B.** Run as **Custodian 1** — the primary IT workstation (Ubuntu,
Cabang Pusat, screen lock and login password active, **host not full-disk
encrypted**, signing vault open per §0) — **with the working directory inside
the mounted vault**, never in a repository directory. No other host may
generate this key: not the production VPS, not a CI runner, not Custodian 2,
not the USB, not a clinic tablet, not a container.

```bash
cd "$HOME/DaengtisiaMS-Signing-Vault"     # inside the vault, always

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

# Copy 2 — CUSTODIAN 2. Admin Klinik workstation (Windows), Klinik Daengtisia.
#          Encrypted at rest. Never in Downloads, Desktop or a temp directory,
#          and never into a consumer cloud sync folder.
#
# Copy 3 — CUSTODIAN 3. USB, Kantor Management Klinik. Sealed cold AND offsite.
#          Encrypted container only — never a plaintext keystore on the medium.
#          Unrelated data already on the USB stays; no wipe is required.
#          Opened only on a declared key-loss incident or a scheduled drill.
```

Checklist (**stage C**):

- [ ] all three copies encrypted at rest
- [ ] passphrase stored separately from every copy — never on the same medium
- [ ] copy 2 on Custodian 2, not in Downloads/Desktop/temp, no cloud sync
- [ ] copy 3 in an encrypted container on the Custodian 3 USB
- [ ] copy 3 offsite at Kantor Management Klinik
- [ ] copy 3 sealed, holder recorded, not routinely accessed
- [ ] USB taken **offline** and not returned to general daily use
- [ ] restore drill scheduled (**90 days**) — that is stage D, still open
- [ ] custodian roles recorded in the operations register

Only when every box above is genuinely ticked may
`signing.custody.backup_1_key_copy_created` / `backup_2_key_copy_created` /
`sealed_cold_backup_created` / `offsite_backup_created` be set true. Setting one
early makes `custody_state_machine_consistent` fail, which is the point:
`android:release-readiness` refuses to carry a claim the artifacts do not
support.

**Never** back up to: a git repository, an unencrypted shared drive, chat, email,
or an unencrypted cloud sync folder. Each of those creates copies nobody can
count and nobody can revoke.

---

## 2b. Close the vault — every time, without exception

The operation is not finished until the vault is closed. An open vault on an
otherwise unencrypted workstation is an ordinary directory holding an
unrecoverable signing key.

```bash
cd ~                                        # leave the mountpoint first
sync
sudo umount "$HOME/DaengtisiaMS-Signing-Vault"
sudo cryptsetup close daengtisiams-signing-vault
sudo swapon -a                              # restore swap taken down in §0
```

Verify the resting state, and do not walk away until it prints clean:

```bash
ls /dev/mapper/ | grep -c '^daengtisiams-signing-vault$'   # must be 0
findmnt -no TARGET "$HOME/DaengtisiaMS-Signing-Vault"      # must be empty
ls -A "$HOME/DaengtisiaMS-Signing-Vault"                   # must be empty
```

No plaintext keystore, certificate or scratch copy may remain outside the
vault. The vault does **not** auto-unlock: there is no `/etc/crypttab` entry,
no systemd unit and no keyfile, and none may be added — the interactive
passphrase is the only unlock secret by design.

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
