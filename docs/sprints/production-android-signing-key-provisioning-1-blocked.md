# PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 — BLOCKED BEFORE KEY GENERATION

> **SUPERSEDED — historical record of the FIRST attempt.**
>
> The task was resumed and completed. The production signing key now exists, both
> encrypted backups exist, and recovery was verified from both destination
> copies. See **`docs/sprints/production-android-signing-key-provisioning-1.md`**
> for the closed record, and rule 147 for the durable invariants.
>
> **The statement "no production Android signing key exists" below was true when
> written and is no longer true.** It is kept unedited because the reasoning that
> produced the stop is the reason the key was eventually created safely — with
> all three destinations genuinely available, rather than in one place.
>
> Superseded forward, never rewritten.

**Status:** BLOCKED. Stopped at the provisioning precheck, before `keytool` ran.
**No production Android signing key exists.** No GO tag was created.

This is a historical record. It is not a failed implementation and must not be
rewritten as one — the task did exactly what its own governance required.

## What it was asked to do

Generate the one long-lived DaengtisiaMS production Android app signing
identity on Custodian 1, create encrypted Backup 1 on Custodian 2, create
encrypted Backup 2 on the Custodian 3 USB, verify recovery from both, and seal
the USB offsite.

## Why it stopped

The provisioning precheck requires every custody destination to be genuinely
available **before** the key is generated, because the signing identity is
effectively irreversible: Android requires every future update to be signed by
the same certificate, and a key with no backups cannot be recovered by anyone.
Generating first and discovering afterwards that no backup could be written is
the specific failure the precheck exists to prevent.

Three preconditions failed when measured:

**Custodian 3 USB — absent.** The only physical block device on Custodian 1 was
the internal disk (`sda`, `removable=0`); every other entry was a snap `loop`.
No removable medium was attached and no removable filesystem was mounted.

**Custodian 2 — unreachable.** No route existed to the Admin Klinik Windows
workstation: no SSH alias, no SMB client, no credential. An operator-assisted
transfer is permitted, but it needs a person at that machine to receive the
container and prove the hash matches.

**Custodian 1 primary secret storage — not encrypted.** The custody record said
`disk_encryption => true` and the release gate had been printing PASS on it.
The machine disagreed: `/` and `/home` mount straight from raw ext4 partitions
(`/dev/sda1`, `/dev/sda3`), with no `/dev/mapper` device, no LUKS signature, no
eCryptfs and no fscrypt. `cryptsetup` was not installed. There was nowhere on
that host that satisfied the encrypted-at-rest requirement for the keystore.

A fourth constraint is structural rather than a defect: the keystore and vault
passphrases must be typed interactively and may never reach argv, an
environment variable, a file or a chat message. An agent has no terminal to
type them into, so the mechanical act of key generation belongs to the operator
by design.

## What it changed

Nothing. No key, no backup, no certificate pin, no APK, no branch, no commit,
no deploy, no tag. The tablet was not touched and ADB was not started.

## What happened next

The third finding — the false encryption claim — was a live inaccuracy in
production governance and was closed on its own by
**REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1**, which records the
host honestly as unencrypted and puts a verified LUKS2 vault on Custodian 1 for
the future keystore.

The other two findings are physical and remain open.

## To resume

Key provisioning may restart only when all of these hold:

- `custodian_1.primary_secret_storage.verified` is true and the vault is
  present — **satisfied** by the revision above;
- an operator is available at the Custodian 2 Admin Klinik workstation to
  receive and hash-verify the encrypted backup;
- the Custodian 3 USB is physically attached to Custodian 1.

Then: open the vault interactively, generate exactly one signing identity
inside it, create Backup 1 and Backup 2, verify recovery from both
independently, seal the USB offsite, close the vault, and update governance.
The procedure is in `docs/runbooks/android-signing-key-backup-and-recovery.md`.
