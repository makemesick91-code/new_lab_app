# REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1

Corrects the custodian 1 encryption record and gives the future production
Android signing keystore somewhere genuinely encrypted to live.

**No production signing key was generated.** No backup was created, no
certificate was pinned, no APK was built, no tablet was touched, and neither
pilot nor global enforcement moved.

## The finding

`PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1` recorded, for custodian 1:

```php
'disk_encryption' => true,
```

`android:release-readiness` read that boolean, printed
`custody_endpoint_controls_recorded → PASS`, and reported the whole gate GO
40/40. The check name was accurate — the control was *recorded* — but nothing
had ever *measured* it.

`PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1` measured it, because it was
about to write an unrecoverable key to that disk. The claim did not survive:

| Probe | Result |
|---|---|
| `/proc/mounts` | `/dev/sda1 / ext4`, `/dev/sda3 /home ext4` — raw partitions |
| `/dev/mapper/` | only `control`; no LUKS mapping |
| LUKS signature | none visible |
| eCryptfs / fscrypt | absent |
| `lsattr -d /home/fikri` | lowercase `e` (extent format), not `E` (encrypted) |
| `cryptsetup` | not installed |

A LUKS-encrypted root would mount from `/dev/mapper/*`. It did not. One
ambiguous boolean had been standing in for a control nobody had checked, on the
one machine authorised to hold a key that cannot be replaced.

## The correction — a split, not a flip

Flipping the boolean to `false` would have been truthful and useless: it would
have said the only authorised signing host was unfit to hold the key. Leaving
it `true` was the original defect. So the claim is split in two:

```php
'host_full_disk_encryption' => false,          // the true fact about the host

'primary_secret_storage' => [                  // the control that protects the key
    'type'               => 'dedicated_encrypted_vault',
    'encryption'         => 'luks2',
    'verified'           => true,
    'default_state'      => 'closed',
    'auto_unlock'        => false,
    'plaintext_keyfile'  => false,
    'outside_repository' => true,
],
```

Neither half is readable as the other. The host is recorded as the unencrypted
thing it is; the vault is recorded as the control that actually covers the key.

## The vault

A 256 MiB LUKS2 container on custodian 1, created **non-destructively** beside
the existing filesystem. No reinstall, no wipe, no repartition; `mkfs.ext4` ran
only on the new `/dev/mapper` device, never on a physical partition. The
passphrase was typed interactively by the operator and is not known to any
agent, not in argv, not in an environment variable, not in a file, not in this
repository.

`cryptsetup 2:2.8.4-1ubuntu4` was installed from the official Ubuntu archive
(`archive.ubuntu.com/ubuntu resolute/main`).

It was verified as a **lifecycle**, not a boolean — opened, mounted, written
to, unmounted, closed, proven to hide its contents while closed, reopened,
proven to return them, then closed again. Independent verification did not
trust the provisioning script's own output: the LUKS2 magic was read straight
from the container header.

| Evidence | Value |
|---|---|
| Header magic + version | `4c554b53babe0002` = `LUKS` `0xBABE` version 2 |
| Container | `0600`, owner-only, 268435456 bytes, not a symlink |
| Parent / mountpoint | `0700` owner-only; `namei` shows no symlink in the path |
| Outside git | no repository, no worktree, not under `Projects/` |
| Auto-unlock | none — `/etc/crypttab` has zero entries, no systemd unit |
| Keyfile | none; the vault directory holds only the container |
| Resting state | mapper closed, unmounted, mountpoint empty |

## Scanner changes

- `custody_endpoint_controls_recorded` no longer accepts a bare encryption
  boolean. A workstation satisfies encryption-at-rest **either** by host
  full-disk encryption **or** by a vault that survives every clause of
  `primaryVaultEncrypted()`. Strict identity, so `"false"`, `1` or a missing
  key all fail.
- New `custody_primary_secret_storage_encrypted` targets the one machine that
  will hold the live keystore, and states the host/vault distinction in words
  so the report cannot blur "the backup machine is fine" into "the signing
  machine is fine".
- `disk_encryption` is **removed** from `CUSTODIAN_PERMITTED_FIELDS` rather
  than renamed. A config still carrying it is an unrecognised field and fails
  `custody_records_no_secret_material` — a loud rejection, so the original
  record cannot quietly satisfy the gate a second time.
- `custodianUnknownFields()` now descends into `primary_secret_storage`. It
  previously walked only the top level of a custodian, so the first nested
  array would have been an unpoliced surface underneath the very check that
  exists to keep `unlock_hint` out of committed files.

Gate result: **GO, 41/41 PASS, 0 WATCH, 0 FAIL** (was 40/40; the new check is
the 41st).

## What this does not authorise

Storage readiness is a place to put a key. It is not a key.

Still false: `production_signing_key_provisioned`, `backup_1_key_copy_created`,
`backup_2_key_copy_created`, `sealed_cold_backup_created`,
`offsite_backup_created`, `recovery_verified`,
`production_certificate_sha256` (null), production APK, pilot enforcement,
global enforcement. `DEVICE_ENFORCEMENT_ACTIVE=false`,
`enforcement.current_stage=off`. drg Karmila at Cabang Sunu is unaffected.

## Residual exposure

The vault protects the keystore **at rest**. It does not turn an unencrypted
workstation into an encrypted one.

Swap is `/swap.img`, 4 GB, file-backed on the unencrypted root filesystem.
While the vault is open, key-derived material in process memory can page out in
the clear. The operator disabled swap for the provisioning run and restored it
afterwards; the runbook now requires the same for every signing operation.

`/tmp` is tmpfs, which is RAM-backed but can itself swap.

Custodian 2's `host_full_disk_encryption` remains an operator **declaration**
about a remote Windows machine no scanner has measured. After what the same
boolean concealed on custodian 1 that is worth stating. It is not load-bearing:
custodian 2's copy must arrive inside its own encrypted container regardless,
because Admin Klinik can log in to that machine.

## Key provisioning is still blocked

The storage gap is closed; the physical custody gap is not. Backup 1 needs an
operator at the Admin Klinik workstation; Backup 2 needs the Custodian 3 USB
attached. See
`docs/sprints/production-android-signing-key-provisioning-1-blocked.md`.
