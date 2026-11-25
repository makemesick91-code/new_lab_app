---
kb_status: unverified
kb_verified_against: 3bfaacc2
kb_updated: 2026-11-25
---

# R6-B6 dedicated restore-drill host: specification and provisioning plan (design only)

**Status:** design for owner approval (directive "R6-B6 — Final Acceptance Closure", F4). Nothing is provisioned,
purchased or changed by this document. The CI reference layout is `deploy/restore-drill-vm/ci/provision-drill-host.sh`.
The operating procedure is in `docs/runbooks/roll-5-backup-restore-drill-runbook.md`. The isolation design is in
`docs/architecture/r6-b6-isolated-restore-drill-design.md`.

The drill host restores **production backups, which hold patient data**, inside disposable VMs. It is a production-class
system in its own right. It must never be the production host (D1).

## 1. Hardware

| Item | Minimum | Recommended | Why |
|---|---|---|---|
| CPU | 4 cores with VT-x or AMD-V, usable by the host OS (bare metal, or a VM with nested KVM) | 8 cores | 1 vCPU per drill VM (D6); one drill at a time; headroom for the inspection unit and the signer |
| RAM | 16 GB | 32 GB | VM 2 GiB plus QEMU overhead (unit cap 3 GiB), the runner's RAM work directory (about 3 × archive + 1 GiB), inspection 512 MiB, OS |
| Storage | 200 GB SSD | 2 × 480 GB SSD/NVMe in RAID 1 | partitions in §2; the OS; the drill image |
| Network | outbound only, for maintenance | same | §7 |
| KVM proof | `grep -cE 'vmx|svm' /proc/cpuinfo` is above 0, and `/dev/kvm` exists | — | the drill never falls back to emulation; CI gate V1-qemu shows `kvm_fds=1` |

## 2. Dedicated file systems

The supervisor and the signer enforce these; they are not advice. Each is a real partition or logical volume, never a
bind mount, subvolume or thin volume that shares space.

| Mount point | Owner, mode | Size | Enforced by |
|---|---|---|---|
| `/var/lib/dmsdrill-qemu/work` | QEMU account, 0700; root-owned parents | at least 12 GiB (overlay of the 10 GiB image disk plus margin; the supervisor requires 11 GiB free) | supervisor: mount point, device differs from the signer store, root-owned ancestors |
| `/var/lib/dmsdrill-signer` (store, head log, working files) | signer, 0700 | 1–2 GiB (records are about 2 KiB each) | signer INSTALL: mount point, not the root file system's device |
| `/var/lib/dmsdrill-witness` | witness, 0700 | 256 MiB | signer INSTALL: mount point, not the root file system's device |
| `/var/lib/dms-restore-drill/runner` | runner, 0700, **tmpfs** | about 3 × largest archive + 1 GiB | runbook (staged copy and input disk are plaintext-equivalent) |
| `/var/lib/dms-restore-drill/inbox` | root:dmsdrill-inbox 2770 | 2 × largest archive | runbook |
| swap | **none** | — | orchestrator refuses with active swap |

## 3. Identities, installation, sudo

- **Accounts.** Five, none with a login shell or sudo beyond §3's rules:
  - operator (a person);
  - `dmsdrill-runner` (member of `dmsdrill-inbox`);
  - `dmsdrill-signer`;
  - `dmsdrill-witness`;
  - `dmsdrill-qemu` (member of `kvm`; `/dev/kvm` root:kvm 0660).
- **Code.** Root-owned under `/usr/local/lib/dms-restore-drill/`, installed from a reviewed release.
- **Configuration.** Root-owned under `/etc/dms-restore-drill/`. It holds the five `.conf` files, the manifest
  anchor, the revocation list, the image properties (from the image build), and the drill's sudoers rules (0444, via
  `@include`).
- **Sudoers.** Exactly the runbook's rules, with the `Defaults!` lines `!use_pty`, `!log_input`, `!log_output` and the
  pinned `rlimit_*` values. The operator has no route to the signer.
- **Verification.** The signer records `INSTALL=root:<code>,config:<config>` and refuses a GO from any other install.

## 4. Key and digest custody (off the host)

| Item | Created | Kept | Checked |
|---|---|---|---|
| Signer key fingerprint (`ssh-keygen -lf …/signing-key.pub`) | once, before the first record | owner's offline record (paper or the encrypted off-host store), never on the drill host | every independent verification pins it (CI V13) |
| Expected INSTALL digest | after provisioning, per host (the configuration part names this host's accounts) | same | compare with every record's `INSTALL` |
| Witness log (head retention) | after every drill (`witness show`) | off the host, append-only copies | an exported store must match every witnessed digest |
| Owner key for manifests and environment contracts | already the owner's | owner only; never on the drill host (only the public anchor is) | — |

## 5. Monitoring

- **Every drill outcome is a signed record.** The owner checks the newest records after each drill: GO, FAIL,
  REFUSED or ABORTED, and the witness head.
- **Host health.** Watch free space on the four dedicated file systems, failed `dmsqemu`/`dmsinspect-*` units, and
  stray QEMU-account processes. None should ever exist outside a drill.
- **No remote monitoring agent.** Nothing on the host may listen or send data out (§7). Checks are run by the
  operator, or by a scheduled local job that writes only a local report.

## 6. Backup, recovery, emergency shutdown

- **Back up:**
  - the configuration and the code release (both reproducible from the repository);
  - the signer key, encrypted and offline, or else regenerated with a new anchor and fingerprint;
  - copies of the evidence store and the witness log.
- **Never back up:** plaintext archives, the runner tmpfs, or VM disks. None should exist outside a drill.
- **Recovery.**
  - Rebuild from the runbook.
  - Restore the evidence store and the witness log.
  - Check that the store verifies against the off-host witness copy and the pinned fingerprint.
  - If the key was lost, start a new store with a new key and record why.
- **Emergency shutdown.**
  - Stop the running drill: TERM the orchestrator; the supervisor stops `dmsqemu`.
  - Power off.
  - If compromise is suspected, revoke the manifests and environment contracts that were in use, and retire the
    signer key.
  - No data needs wiping beyond the RAM-only run directories, which die with the power.

## 7. Network policy

- No inbound service at all, including SSH, unless the owner chooses an administrative path. If so, it is key-only
  from one administrative address, and never from the clinic network.
- No outbound connection during a drill. The VM has no network device by construction, and the QEMU unit has no
  socket at all.
- Outbound connections only in maintenance windows, for operating-system and package updates, then rebuild the drill
  image and re-record the image properties.
- Archives arrive through the inbox by a reviewed transfer. They are never pulled from production by the drill host.

## 8. Capacity planning

The current D6 limits cap what one drill can restore:
- archive at most 128 MiB (inspection);
- input disk at most 256 MiB;
- restored database at most 1 GiB (the guest's tmpfs);
- a 30-minute VM.

**Planning rule.** Let A be the custom-format archive size and R the restored size (heap, indexes and TOAST). Both
must be measured read-only from production when authorized: `pg_database_size`, and the size of a `-Fc` archive.
- A ≤ 128 MiB and R ≤ ~900 MiB: the current limits fit.
- Above that, the owner must approve larger D6 limits before the first real drill: VM RAM and tmpfs, input-disk cap,
  inspection cap, time. Each change is re-tested in CI.
- Custom-format archives typically compress table data several-fold, so R is usually a multiple of A. The expansion
  must be measured, not assumed.
- Storage then scales as in §2: the runner tmpfs about 3·A + 1 GiB, the inbox 2·A, the QEMU work directory fixed at
  12 GiB. The 10 GiB image disk is not the restored database, which lives in guest RAM.

## 9. Cost estimate (RAB), researched 2026-11-25

These are list prices and calculations, not quotes. The exchange rate is about Rp18,200 per USD. Indonesian VAT
treatment is noted where the source states it. Sources were accessed on 2026-11-25 and are listed in Evidence Pack 53.

| Option | What | Monthly | One-time | Data in Indonesia | Notes |
|---|---|---|---|---|---|
| A1 | Nevacloud Nevametal 48GB (bare metal, Xeon Gold 18C, 48 GB, 2×480 GB RAID 1, Jakarta) | about Rp3.0 million (Rp2.64 million on 12 months); provider says no VAT is added | none ("free setup") | yes (Cyber 2, Jakarta) | provider allows its use as a virtualization host |
| A2 | Biznet Gio NEO Metal t1/t2.small (bare metal, 8C, 32 GB, 2×480 GB RAID 1) | Rp4.699 million (VAT not stated) | not stated | yes | same vendor as production; hypervisor policy not stated |
| B | Cloud VM with documented nested KVM, Jakarta region (AWS m7i-flex.xlarge, Azure D4s_v5 or GCP n2-standard-4; 4 vCPU/16 GB) | 24×7: about $175–230 (Rp3.2–4.2 million); **only while drilling: about $2–3 (Rp40–50 thousand) per 8-hour drill** plus a few dollars a month for a kept template disk | none | compute and disks yes; snapshot or backup settings must be pinned to the region | foreign-parent provider; legal review needed (Permenkes 24/2022, the PDP law) |
| C | On-premises mini PC (Ryzen 7 or Core Ultra, 32 GB, 1 TB NVMe) plus a UPS | electricity, about Rp30–60 thousand | about Rp11–18 million (2026 memory prices are volatile) | yes (clinic premises) | physical security, power and maintenance are the clinic's |

Not priced: administration time, the owner's off-host custody media, legal review.

**Recommendation for the owner's decision:**
- Option A1, or A2 to stay with the production vendor, for a permanent drill host in Indonesia. Option C is
  acceptable if physical security at the clinic is adequate.
- Option B is the cheapest for monthly drills, but needs a legal decision on a foreign-parent provider holding
  restored patient data, even in-region.

## 10. Provisioning sequence (after approval)

1. Choose the option. Have the host provisioned, then verify KVM (§1).
2. Partition as in §2. Set up no swap and an outbound-only firewall.
3. Install the reviewed release and create the accounts, configuration and sudoers as in the runbook. Build the drill
   image and install its properties.
4. Generate the signer key. Record its fingerprint and the expected INSTALL digest off the host.
5. Collect production's restore environment read-only (`scripts/restore-env-contract-collect.sh`; separate
   authorization). Sign the contract and a role manifest naming it.
6. Run a first drill on a synthetic archive, then on a production `-Fc` archive (separate approval, F5), and verify
   the evidence off the host.
