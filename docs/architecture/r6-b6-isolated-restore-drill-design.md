---
kb_status: unverified
kb_verified_against: 674e10e9
kb_updated: 2026-11-25
---

# R6-B6 isolated restore drill: structural redesign (finding A-1)

**Status: version 4, Phase 3 hardening and real-isolation validation (§0.10–§0.13, directive "R6-B6 — Phase 3 Real
Isolation Validation & Final Security Closure", 2026-11-25), on top of version 3 (phase 2, §0.4–§0.9) and version 2
(D1–D10, §0). Not deployed; validated on disposable GitHub-hosted runners from the dedicated validation branch.**
- Finding A-1 (HIGH) stays **OPEN**. This document does not close it. Closure needs the implementation, system-mode
  tests and an independent review of the implemented isolation controls.
- R6-B6-F1 (the rehearsal swallows a failed drop) stays **OPEN** as a separate issue.
- No production change, no push, no restore or drill was run on any shared or production system.

Source directive: "G6 — R6-B6 STRUCTURAL SECURITY REDESIGN" (owner, 2026-11-25). The PR #695 head and every local
remediation commit up to `039a7f59` are kept unchanged.

The design went through three independent adversarial review rounds:
- round 1 on the prototype (DR-1 to DR-19, §11);
- round 2 on the implementation by two reviewers, Linux/systemd/VM and PostgreSQL restore security (VR-1..12 and
  PR-1..9, §0.2);
- round 3 on the phase 2 hardening, by two new reviewers on the same two specialties (§0.7);
- a fix-verification review of round 3 by a further independent reviewer (round 5, §0.9).

## 0. Version 2: owner decisions D1–D10 and the implemented architecture

Owner directive "OWNER DESIGN DIRECTIVE — R6-B6 ISOLATED RESTORE DRILL" (2026-11-25) decided OD-ISO-1 to OD-ISO-9.
**Where they differ, this section supersedes §3–§12** (the version 1 design ran the unit on the production host).
§1–§9 still describe the unit's profile and threats, which now apply INSIDE the VM.

| Decision | Content | Implemented in |
|---|---|---|
| D1 | A separate, disposable VM, not a sandbox on the production kernel | `scripts/restore-drill-vm.sh` refuses the production host. It boots QEMU/KVM with no NIC, from a pinned image, with read-only encrypted input. |
| D2 | An ephemeral unprivileged identity in a systemd system unit inside the VM | guest agent + `dms_sandbox_properties system` (DynamicUser) |
| D3 | An authenticated role manifest; no connection to production PostgreSQL | `scripts/lib/restore-manifest.sh` (ssh-keygen `-Y`, namespace `dms-restore-role-manifest`) |
| D4 | A non-superuser replay role | `drill_restore` NOSUPERUSER; `drill_super` NOLOGIN before the first byte |
| D5 | Restricted mode mandatory; load guard kept | restricted stream + guard from #695, both in the payload |
| D6 | 1 vCPU, 2 GiB, 10 GiB, 30 min, 64 tasks, one drill, fail closed | fixed in the orchestrator; `dms_sandbox_limits_check`; flock |
| D7 | Continue in the local #695 branch, history preserved | commits on top of `039a7f59` (base `bd52f97f` merged) |
| D8 | A real-sandbox CI gate on disposable infrastructure | `deploy/restore-drill-vm/ci/` (two gates + an INACTIVE workflow proposal) |
| D9 | Two independent reviews (Linux/systemd/VM; PostgreSQL restore) | review round 2 (VR-1..12, PR-1..9), below |
| D10 | Authenticated, tamper-evident evidence; readiness kept separate | `scripts/lib/restore-evidence.sh` (signed, sha256-chained) |

The in-place superuser replay is **retired**: `scripts/rollout-restore-drill.sh` verifies read-only and exits 3.

```
 ISOLATED DRILL HOST (never the production VPS; unprivileged operator, never root)       [version 3]
   restore-drill-vm.sh: fixed env -> trusted config -> D6 limits + flock -> pinned image (JSON check)
     -> signed manifest (one private copy) + revocation list -> #695 integrity chain (staged copy, digest)
     -> custom-format archive inspected by pg_restore in a hardened per-user unit (no network, capped output)
     -> TOC allowlist (types, schema, owners, names, extensions) + per-table row counts -> canonical digest
     -> AES-256-CTR input disk -> QEMU/KVM in its own process group -> output disk (strict parse)
     -> decision (host-verified facts) -> evidence REQUEST -> sudo -> SIGNER identity (key, store, head log)
     -> cleanup (kill group, rm run dir)
        |  -nic none, -nodefaults, -sandbox on, -cpu host,-vmx,-svm,pmu=off, vmport/hpet/smm off
        v
 DISPOSABLE VM (one boot, root disk = overlay on the pinned image, deleted after)
   guest agent (root): parse header, decrypt to a private file, verify sha256, write the guard, run the unit
     systemd SYSTEM unit, Type=oneshot, DynamicUser, the §5 profile (+ review fixes), archive + guard read-only
       disposable PG16 -> pg_restore --section=pre-data | psql -> guard -> data -> post-data, as drill_restore
       (NOSUPERUSER, restricted mode, fresh restrict key) -> read-only verification: per-table digest, user code
       on restore paths, extensions outside the allowlist, runtime-owned objects
```

### 0.1 Security acceptance (directive: configuration-only claims are insufficient)

| # | Acceptance item | Evidence | Status |
|---|---|---|---|
| 1 | Actual OS identity separation | system-mode gate `P-uid`, `P-private`, `P-signal`, `P-proc` | **NOT RUN.** It needs root or a disposable CI VM. User mode is a LIMIT: same uid. |
| 2 | VM isolation from production | vm-boot gate V1–V5; guest reports `lo` only | **NOT RUN.** No hypervisor is usable on this workstation (no QEMU, not in the kvm group, no Hyper-V rights). |
| 3 | No production file, socket or network access | real-sandbox gate P-* (user mode): decoys reachable from outside, refused inside (files, sockets, TCP, IPv6, netlink, vsock, io_uring, metadata) | **PROVEN in user mode** (55/0/3). VM layer NOT RUN. |
| 4 | Trusted backup and manifest verification | #695 integrity suites; manifest/evidence suite (real ssh-keygen); orchestrator refusals | **PROVEN locally** (Pest) |
| 5 | Non-superuser replay | runtime suite + gate S-su1..5 (42501), S-super (NOLOGIN), PR-2/3/5 cases | **PROVEN in user mode** |
| 6 | End-to-end restore fidelity | the full application schema through the guest/host protocol: GO, 186/186 tables, 238/238 rows, fidelity `22:1045:5:968`, no user code on a restore path | **PROVEN without the hypervisor.** The VM boot is NOT RUN. |
| 7 | Resource exhaustion resistance | gate X-disk, X-time; prototype memory and fork bombs | **PROVEN in user mode.** VM-level D6 is NOT RUN. |
| 8 | Reliable cleanup | gate C-*; VR-2 process-group test; stale-run sweep | **PROVEN locally.** VM is NOT RUN. |
| 9 | Evidence authenticity | signed and chained records; edit, gap, reorder, re-sign and tail-with-head detection (real ssh-keygen) | **PROVEN locally.** Dropping the newest records without a head anchor is a residual. |
| 10 | Independent adversarial reviews | round 1 (19 findings) and round 2: VR-1..12 and PR-1..9, two reviewers | **DONE.** Their HIGH/MEDIUM findings are fixed or open, below. |

### 0.2 Review round 2: findings and dispositions

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| PR-1 | HIGH (conditional) | With code running as the sandbox identity, a SIGTERM-killed `Type=simple` main process counted as success, so a GO could be forged | **Fixed:** `Type=oneshot` + `TimeoutStartSec` (proven: every terminating signal is a failure). **Residual:** with native code or a cluster superuser inside the unit, every reported fact is attacker-chosen (see VR-1). |
| VR-1 | MEDIUM | Root in the guest can forge GO from the payload alone | **OPEN (inherent).** No host check can separate "restored" from "computed". GO integrity rests on no code reaching the guest's root. The evidence marks counts `guest-asserted`. |
| VR-4 | MEDIUM | QEMU runs as the identity that holds the evidence signing key | **Partly fixed:** root refused, environment fixed. **OPEN:** a separate signer identity, or QEMU under its own uid (system mode, phase 3). |
| VR-2 | MEDIUM | Interrupt killed `timeout`, not QEMU | **Fixed:** process-group kill (tested) |
| VR-3 | MEDIUM | `-drive …,serial=` removed in QEMU 3.0; `qemu-img info` misparsed under QEMU 8 | **Fixed:** `-device virtio-blk-pci,serial=`; JSON parser (tested with QEMU 8-shaped output). The VM boot itself is still unproven. |
| PR-2 | MEDIUM | An index-expression "restore bomb" gets GO in the drill and destroys data in a real restore | **Fixed:** GO needs no user code on a restore path (tested) |
| PR-3 | MEDIUM | A function body blinds the text owner scan (P5) | **Fixed:** P5 is decided from the restored catalog (tested) |
| PR-4 | MEDIUM | Manifest read multiple times (race) | **Fixed:** a single private copy |
| PR-5 | MEDIUM | Completeness totals can be steered; RLS policy code ran during verification | **Partly fixed:** read-only verification, `row_security=off` (tested), real `migrations` table. **OPEN:** per-table counts from a structured source (custom-format TOC or a dump-time count log). |
| VR-5..VR-11 | LOW | window/NUL forgery; disk probing; CPU surface; FAIL reasons; stale data; manifest reads; environment | **Fixed** (VR-6 by payload encryption; probe-suppression udev rule to validate on the real image) |
| PR-6 | LOW | Text heuristics: COPY data matching patterns causes FAIL (DoS); guard call inside quoted names | **OPEN:** structured dump format |
| PR-7 | LOW | The orchestrator could never pass the manifest gate (subshell) | **Fixed** |
| PR-8 | LOW | Trusted extensions run superuser install scripts; role settings DoS | **Partly fixed:** connection options override role and database settings. **OPEN:** an image extension allowlist. |
| PR-9 | INFO | Manifest freshness and revocation; evidence publication atomicity; guard search_path | **Guard search_path fixed. OPEN:** manifest binding, evidence `ln`/`mv` atomicity. |

### 0.3 Verdicts (2026-11-25)

- **A-1: OPEN.** Acceptance items 1 (real identity separation) and 2 (VM isolation) have no real evidence. VR-1, VR-4,
  PR-5 and PR-6 remain open. Closure needs:
  - the system-mode gate and the vm-boot gate run on disposable infrastructure;
  - the open items above resolved;
  - a re-review of the result.
- **PR #695: BLOCKED.** It must not be published as an A-1 fix while A-1 is OPEN. Publishing it as work in progress
  would be a separate owner decision.

### 0.4 Version 3: phase 2 hardening (directive "R6-B6 — APPROVED TO PROCEED", 2026-11-25)

**§0.2 and §0.3 are kept as the round 2 record. Where they differ, §0.4–§0.8 supersede them.**

- **PR-6, structured backup format.**
  - The drill accepts only a pg_dump 16 custom-format archive (`pg_dump -Fc`). A plain-SQL backup is refused before
    anything runs.
  - The host never parses the archive itself. A pinned `pg_restore` (16.15 or later) lists the TOC and renders only the
    data section, inside a hardened per-user unit (`dms_archive_inspect`):
    - no network, AF_UNIX only;
    - no exec outside an allowlist;
    - MemoryMax 512M, a 300 s timeout;
    - the archive bound read-only;
    - output capped at 12 MiB.
  - `dms_archive_toc_validate` accepts only a header that says custom format, dumped from PostgreSQL 16 by pg_dump 16.
    Every entry must be of a type the application produces: ACL, COMMENT, CONSTRAINT, DEFAULT, FK CONSTRAINT,
    FUNCTION, INDEX, SEQUENCE, SEQUENCE OWNED BY, SEQUENCE SET, TABLE, TABLE DATA, TRIGGER, and EXTENSION when
    allowlisted. Further checks:
    - the only schema is `public`;
    - every owner is in the signed manifest (D-Q6-a);
    - table and sequence names are plain lowercase identifiers;
    - every line is printable;
    - every TABLE has exactly one TABLE DATA entry.
  - Inside the unit, the restore is `pg_restore --section=<s> --restrict-key=<fresh>` into restricted psql for
    pre-data, then the load guard, then data, then post-data.
  - No text heuristic over COPY data remains: the trailer check and the text counter are removed.
- **PR-5, per-table completeness.**
  - The host counts each TABLE DATA block's rows with a strict state machine, `dms_archive_count_program`. It checks
    the framing, the field count of every row, and the order against the TOC.
  - The host builds a canonical digest: sha256 over `schema<TAB>table<TAB>rows<LF>`, in C order.
  - The guest computes the same digest in SQL over the restored database. It verifies read-only, with
    `search_path=pg_catalog` and `row_security=off`.
  - GO needs the two digests and the two table counts to be equal.
  - A row-level security policy is refused at the TOC. If one reaches the unit anyway, verification fails instead of
    running policy code.
- **PR-8, extension allowlist.** `DMS_DRILL_EXTENSION_ALLOWLIST` is `none` or a list, enforced three times:
  - in the TOC;
  - after the restore (`extensions_outside_allowlist=0`, else `FAIL:extension_outside_allowlist`);
  - in the image, where `deploy/restore-drill-vm/prune-extensions.sh` removes every extension control file outside
    the list.
- **PR-9, revocation and atomic evidence.**
  - Manifest revocation: a trusted list with `revoked <id>` and `not-before <ISO time>` lines, parsed strictly and
    fail closed.
  - Evidence contract 2: each record is a directory `store/NNNNNN/{record,record.sig}`, staged in `.stage-*` and
    published by one `mv -T -n`.
  - A head log of `NNNNNN sha256` lines.
  - The signer verifies the whole store against the head log before every write and refuses a run id it has already
    recorded.
  - `recover-head` repairs only the one crash window, a head log exactly one record behind a store that verifies. It
    does not rebuild a lost head log.
  - Every record prints its head digest so the operator can keep it outside the drill host.
- **VR-4, separate signer identity.**
  - `scripts/restore-drill-evidence-signer.sh` runs as its own account through one sudoers rule (`sign`, `verify`).
  - The operator, and therefore QEMU, holds no key, store or head log.
  - The signer:
    - refuses a caller with its own uid or one outside its allowlist;
    - reads only its trusted configuration;
    - validates the request schema;
    - fills the contract, sequence, previous digest, signer, caller and time itself.
- **VR-1, explicit guest trust boundary.**
  - Every record separates `HOST_VERIFIED`: the backup digest, manifest signature, revocation, archive TOC, owners,
    extensions, per-table counts, image digest, the no-NIC construction and the limits.
  - It sets that apart from `GUEST_ASSERTED`: the restore result, table count, per-table digest, fidelity and
    interfaces as the guest reported them.
  - The output says so: the restore result is asserted by the disposable guest, and a GO is never stronger than the
    guest's integrity.

**Residual (VR-4, owner choice).** QEMU still runs as the operator's uid. A device-model escape could therefore ask
the signer for a record through sudo. It cannot read the key, rewrite or truncate the history, or replay a run id. A
dedicated QEMU uid is an option for the drill host (§0.8).

### 0.5 Phase 1: CI readiness audit (the workflow stays INACTIVE)

The proposal is `deploy/restore-drill-vm/ci/restore-drill-real-sandbox.workflow-proposal.yml`. It is not in
`.github/workflows/`, so nothing runs.

| Area | Finding |
|---|---|
| Trigger | `pull_request` and `workflow_dispatch`, never `pull_request_target`. A fork's code runs only on its own disposable runner, with a read-only token. |
| Permissions | `contents: read` only. `persist-credentials: false`. No repository secret, environment or OIDC token. |
| Actions | Pinned by full commit SHA, checked against the tags with `gh api` on 2026-11-25: `actions/checkout` v4.4.0 = `11d5960a…` (published 2026-07-20) and `actions/upload-artifact` v4.6.2 = `ea165f8d…` (2025-03-19). |
| Dependencies | Ubuntu archive packages through signed apt. The Ubuntu 24.04 cloud image is checked against `SHA256SUMS`, verified with `gpgv` and `ubuntu-cloudimage-keyring`. psql must be 16.15 or later, or the job fails. |
| KVM | GitHub documents hardware acceleration on its Linux runners, including 2-vCPU runners since 2024-04-02. The udev rule that opens `/dev/kvm` is community-documented, not official. The job checks `test -r /dev/kvm && test -w /dev/kvm`, and the orchestrator refuses without KVM, so a runner without KVM fails rather than skips or emulates. **UNVERIFIED until the first run.** |
| Network | The runner has internet for the packages. The drill VM has no NIC. The gates' decoys listen only on 127.0.0.1, `/dev/shm` and an abstract socket inside the runner. |
| Data | Synthetic only: `tests/Fixtures/restore-drill` and archives built from the gates' own decoy clusters. No production host, backup, credential or patient data. |
| Privilege | `sudo` is used on the throwaway runner only. `provision-drill-host.sh` (formerly `provision-signer.sh`) refuses without `GITHUB_ACTIONS` and on the production hostname. **LIMIT:** the CI operator (`runner`) has passwordless root, so in CI the signer separation proves the mechanism (sudo rule, file modes, caller checks), not a boundary. On the drill host the operator must have no other sudo right (runbook). |
| Artifacts | Only the gates' result files and transcripts, with synthetic data. |

### 0.6 Phase 3: real isolation gates (prepared; NOT RUN)

No authorized isolated runner exists. The workstation has no sudo without a password, no QEMU, no kvm group and no
Hyper-V rights, and activating the workflow needs separate owner approval. Every gate below is **NOT RUN**, never PASS.

| Gate | Prepared in | Status |
|---|---|---|
| System-mode DynamicUser identity separation (P-uid, P-private, P-signal, P-proc) | `real-sandbox-gate.sh system` | **NOT RUN** |
| An actual QEMU/KVM boot (V1) | `vm-boot-gate.sh` | **NOT RUN** |
| An end-to-end isolated restore in the VM (V1, V6) | `vm-boot-gate.sh` | **NOT RUN** (the same guest code ran end to end without a hypervisor; §0.8) |
| Production file, socket, credential and network isolation (P-*) | `real-sandbox-gate.sh` | user mode **PASS** (see §0.8); system mode and VM **NOT RUN** |
| Resource exhaustion (X-disk, X-time) | `real-sandbox-gate.sh` | user mode **PASS**; system mode and VM **NOT RUN** |
| Forced termination and cleanup (C-*) | `real-sandbox-gate.sh` | user mode **PASS**; VM **NOT RUN** |
| Evidence forgery, tampering, truncation, replay (G1–G6, V7, V8) | both gates | Pest (real ssh-keygen) **PASS**; the separate signer account **NOT RUN** |
| Per-table completeness and catalog shape (S-*, V1, V9) | `real-sandbox-gate.sh`, Pest | user mode **PASS**; VM **NOT RUN** |

The user-mode gate at the final head gives 58 passed, 0 failed and 3 LIMIT. The three LIMITs are P-uid, P-private and
P-signal: the same uid, which only system mode can separate.

### 0.7 Review round 3 (phase 4): findings and dispositions

Two new independent reviewers reviewed the phase 2 head `674e10e9`, on the same specialties as round 2.
- **Linux/systemd/VM and signer (P4V-*):** 1 HIGH, 5 MEDIUM, 4 LOW, 3 INFO.
- **PostgreSQL restore and evidence (P4P-*):** 2 HIGH, 2 MEDIUM, 2 LOW, 2 INFO.

Every fix below has an adversarial regression test. Experiments used synthetic data only.

| ID | Sev. | Finding (proved by the reviewer unless marked) | Disposition and evidence |
|---|---|---|---|
| P4P-1 (superseded by P5-1, §0.9) | HIGH | A generated column folds an immutable call at `CREATE TABLE`, so user code runs in pre-data, before the guard. It can drop its own function, or add it to a trusted extension, and the drill still said GO. | **Fixed**, with four independent layers (each tested): (1) the guard refuses a reference that no longer resolves (fixture `dangling`); (2) user code is decided from `pg_depend` and the stored trees of constraints, generated columns, indexes, partition keys, rules and types, including dangling references; (3) `track_functions=all` plus a per-section flush, so any user function that ran fails the drill (`execbomb`); (4) the guest's catalog shape must equal the host's TOC shape. Allowlisted extensions are created by the bootstrap superuser, and the archive's EXTENSION entries are left out, so laundering fails with 42501 (`launder`). With the guard disabled, layers 2–4 still fail `dangling` (user code 2, 1 call, shape 2 vs 3 functions) and `checkgen` (pack 51). |
| P4P-2 | HIGH | The host cut the TOC at 8 MiB without failing, which hid refused entry types. | **Fixed:** the unit refuses a TOC above 8 MiB or a count list above 4 MiB; the stream must end with `@@END` and fit its cap; sizes are re-checked on the host. A real-unit test runs with a small limit. |
| P4V-1 | HIGH | `user_code_reachable` ignored CHECK constraints and generated columns. | **Fixed** by P4P-1 layer 2. The guard already refused these in the drill (fixture `checkgen`: FAIL at the load guard). |
| P4V-2 | MEDIUM | The orchestrator applied a plain-text header check, so it refused every custom-format archive. | **Fixed:** `dms_archive_verify_copy` (digest, then the `PGDMP` magic). Inputs are checked before the hypervisor. A behavioural test drives the installed orchestrator with real archives through integrity, sandboxed inspection and the TOC, up to the hypervisor stage. |
| P4V-3 | MEDIUM | The inspection unit could lift its own cgroup limits, move out of its cgroup, and write the host's `/dev/shm`. | **Fixed:** `ProtectControlGroups`, cgroupfs hidden, private `/dev/shm`, `/etc` hidden, kernel and clock protections, and an empty capability bounding set. Re-probed with bash builtins: cgroupfs hidden, `memory.max` denied, move denied, nothing written to the host's `/dev/shm`. |
| P4V-4, P4P-5 | MEDIUM | The signer signed any well-formed GO, including contradictory ones. Trust roots could be owned by the operator. | **Fixed:** `dms_evidence_request_policy`, with 11 refusal tests. It checks the host name, a pinned image digest, the D6 limits, the fixed HOST_VERIFIED list, time order and recency, and for a GO: equal digests, shapes and tables, restore=ok, no user code, `lo` only. The orchestrator's trust owner is the owner of its own script: in a root-owned install, every library, ancestor and trusted input must be root-owned; the record states `install=root\|operator`. **Residual (VR-4):** QEMU runs as the operator, so a QEMU escape can still state a *consistent* GO. |
| P4V-5 | MEDIUM | A caller's file-size limit or a full disk truncated a signed record, or bricked the head log. The lock could be held from stdin. | **Fixed:** the signer refuses unless `ulimit -f` is unlimited (test); the written record must equal the built one and pass the key-set check; the signature is verified before publication; the head log is replaced atomically (copy, append, `sync`, rename); stdin is read with a 30 s deadline before the lock; signals are ignored during publication; stale staging directories are removed under the lock. |
| P4V-6 | MEDIUM (cond.) | `#!/usr/bin/env bash` found bash through the caller's PATH. | **Fixed:** `#!/bin/bash`, and the clean re-exec runs `/usr/bin/env -i … /bin/bash`. |
| P4P-3 | MEDIUM | Objects owned by `pg_database_owner` bypassed D-Q6-a and P5. | **Fixed:** accepted only on the public schema's ACL or comment (fixture `dbowner`, TOC tests, orchestrator test). |
| P4V-7 | LOW | The signer did not check its own code, its key's read bits, or its head-log path. | **Fixed:** this script, its libraries and every ancestor must be owned by root or the signer and not writable by others; the key must be the signer's, mode 0600 or 0400; the head log must be canonical, in a trusted directory. `DMS_SIGNER_CONF` is still honoured, so **the sudoers rule must not allow SETENV** (runbook). |
| P4V-8 | LOW | Any XDG_RUNTIME_DIR or bus could be used, and a fake manager could forge the inspection. | **Fixed:** only `/run/user/<uid>` (owned by the operator, 0700) and its own bus are kept; a missing manager is reported as such. |
| P4V-9 | LOW | A missing optional `ExecPaths=` entry failed every inspection. | **Fixed:** `-` prefixes, and awk bound by its real path (the alternatives link lives in the hidden `/etc`). |
| P4V-10 | LOW | An interrupted orchestrator left the inspection unit running. | **Fixed:** the unit name is tracked and stopped in the cleanup trap; the behavioural test asserts that no `dmsinspect-*` unit is left. |
| P4P-4 | LOW | The counter refused honest dumps: an empty value, a column name with `, ` or parentheses. | **Fixed:** quote-aware column count, and an empty line is one field (test). **Residual:** the tmpfs is sized from the archive's size, so a very compressible honest archive can still end FAIL (disk full). Fail-closed. |
| P4P-6 | LOW (reasoned) | A refusal before the VM boots leaves no signed record. | **OPEN (owner choice):** refusals are printed to the operator and exit 2. No false GO is possible. Recording them needs a FAIL request shape without VM facts. |
| P4P-7, P4P-8 | INFO | Compound types were read by prefix; `ucode` ignored `classid`. | **Fixed:** `TABLE ATTACH`, `INDEX ATTACH` and `DEFAULT ACL` are refused by name (tests); `classid` is matched. |
| P4V-11 | INFO | The head log has the signer's uid and adds no independent protection. | **Residual (owner choice):** the head digest printed with each record must be copied off the drill host (runbook). An append-only or off-host head store is a separate decision. |
| P4V-12 | INFO | HOST_VERIFIED TOC facts are only as strong as the sandboxed `pg_restore`. | **Recorded:** they are the host's reading of the archive through a hardened, network-less unit, not an independent proof. |
| P4V-13 | INFO | The design doc cited §0.7 and §0.8 before they existed. | **Fixed** (these sections). |

### 0.8 Closure matrix, D1–D10 acceptance, verdict (final head; see the sprint record for the commit)

**Security findings named by the phase 2 directive:**

| Finding | Status | Basis |
|---|---|---|
| VR-1 guest trust boundary | **RESOLVED as an explicit boundary**; residual inherent | HOST_VERIFIED vs GUEST_ASSERTED; the signer refuses inconsistent GO records; a GO is never stronger than the guest's (and the operator's) integrity |
| VR-4 separate signer, protected interface | **PARTLY RESOLVED** | Separate account, one sudoers rule, self-checking signer, request policy. **NOT RUN:** real sudo separation (system-mode gate G1–G6). **Residual:** QEMU under the operator uid. |
| PR-5 per-table completeness | **RESOLVED locally** | Per-table digest; full application schema GO, 186/186 tables, 238/238 rows. VM NOT RUN. |
| Code from the backup running during the restore (P4P-1, P5-1) | **RESOLVED locally, structurally** | Restore order (§0.9): no dump function exists while anything is evaluated. Every code-bearing fixture fails with 42883; every honest one, including the full schema, is GO. |
| PR-6 structured format, strict validation, limits | **RESOLVED locally** | Custom format only; TOC allowlist; sandboxed inspection; refused-not-truncated limits (P4P-2); P4V-2 path fixed |
| PR-8 fail-closed extension allowlist | **RESOLVED locally** | TOC, inner unit, after the restore, image pruning; extensions owned by the bootstrap superuser |
| PR-9 revocation, atomic publication, chain head | **PARTLY RESOLVED** | Revocation, atomic directory publication, robust writes, head log. **Residual:** independent retention of the head is manual (P4V-11). |
| Round 3 HIGH (P4P-1, P4P-2, P4V-1) | **FIXED**, regression-tested; P4P-1 superseded by the structural fix of P5-1 | §0.7, §0.9 |
| Round 5 HIGH (P5-1) | **FIXED**: reproduced as GO on `e0e64389`, now FAIL 42883 | §0.9 |
| Round 3 MEDIUM | **FIXED**, except the VR-4 residual | §0.7 |

**D1–D10 acceptance (real evidence only; a configuration-only claim is not acceptance):**

| Decision | Status |
|---|---|
| D1 disposable VM, never on production | Implemented; the production-host refusal is tested. **VM boot NOT RUN.** |
| D2 system unit, DynamicUser | Implemented. **System mode NOT RUN.** User mode PASS, with the same-uid LIMIT. |
| D3 authenticated manifest | **PROVEN locally:** real ssh-keygen, revocation, and the orchestrator behavioural test with a signed manifest |
| D4 non-superuser replay | **PROVEN in user mode:** the bootstrap superuser is NOLOGIN; laundering fails with 42501 |
| D5 restricted mode and load guard | **PROVEN in user mode** (metabody, dangling, checkgen) |
| D6 limits | Constants and the limits check tested; unit-level exhaustion PASS in user mode. **VM-level NOT RUN.** |
| D7 local #695 branch, history preserved | **DONE:** fast-forward only |
| D8 real-sandbox CI gate on disposable infrastructure | **Prepared**; the workflow is INACTIVE. **NOT RUN.** |
| D9 two independent reviews | **DONE:** rounds 2 and 3 (two reviewers each). The fix-verification review of round 3 is in §0.9. |
| D10 authenticated, tamper-evident evidence; readiness separate | **PROVEN locally** (Pest, real ssh-keygen). The separate-account path is **NOT RUN**. Readiness is not wired. |

**Verdict: NO-GO for A-1 closure.** A-1 stays **OPEN**, PR #695 stays **BLOCKED**, and R6-B6 is **WATCH**. Closure needs:
- the system-mode gate and the vm-boot gate run on authorized disposable infrastructure (owner approval to activate
  the workflow);
- owner decisions on the residuals: a QEMU uid separate from the operator, off-host head retention, and recording
  refusals (P4P-6);
- a re-review of the gate evidence and of the round 5 fixes (§0.9).

### 0.9 Review round 5: fix verification of round 3

An independent reviewer verified the round 3 fixes on `e0e64389`. Result: 15 HOLD, 4 PARTIAL, 2 BROKEN, and one new HIGH.

| ID | Sev. | Finding | Disposition |
|---|---|---|---|
| P5-1 | HIGH (proved) | Pre-data code (folded from a generated column at CREATE TABLE) can run and then erase every trace before the guard. It renames and recreates its functions, and a deferred trigger on a temp table drops the generation expression and the originals at commit. The guard, the user-code check, the call count and the shape all came out clean: GO. | **Fixed structurally:** the restore ORDER is now the boundary. Dump FUNCTION entries (with their comments and ACLs), TRIGGER entries and the remaining ACLs are restored only after pre-data, the guard, data and post-data. Anything evaluated before then that names a dump function fails with 42883, so no backup code can run as the restore role, and none can run and erase itself. Reproduced here as GO on `e0e64389`, and FAIL (pre-data 42883) after the change. Fixture `vanish`; every earlier code-bearing fixture now fails with 42883 too. Honest archives stay GO: the full application schema (5 trigger functions, 22 triggers, 186 tables) with unchanged fidelity `22:1045:5:968`. Cost: a DEFAULT naming a user function now fails, which is fail-closed (the application has none). |
| P5-2 | MEDIUM | The call count (`track_functions`) and the catalog shape can be erased by code that runs. | **Accepted:** they are defence-in-depth signals, no longer the boundary (P5-1). They stay in the GO conditions. |
| P5-3 | LOW | The signer took the first of duplicated keys in a comma list. | **Fixed:** EXPECTED and GUEST_ASSERTED must match one fixed-key, fixed-order, typed pattern (two new refusal tests). |
| P5-4 | LOW | `install=root\|operator` is the operator's own statement. | **Recorded:** the field is operator-asserted. The signer does not inspect the drill host's install. |
| P5-5 | LOW | Evidence was not durable across a power loss; `recover-head` appended in place. | **Fixed:** the record, its signature and the staging directory are synced before the rename, and the store and head-log directories after it; `recover-head` uses the atomic replace. |
| P5-6 | LOW | The orchestrator did not check its own script's mode or the production-identity file, and accepted a sticky install root. | **Fixed:** the script, its directory, the install root, `deploy/` and `production-host.conf` must be owned by root or the trust owner and writable by nobody else, with no sticky exception (four refusal tests); the signer's install directory likewise. |
| P5-7 | INFO | Inspection unit escape probes failed. Extension pre-creation is safe. The flush is ordered correctly. Inlined SQL functions are not a vector. | Recorded. `ProtectProc=invisible` is optional defence in depth (not added). |

Round 5 fixes are verified by tests and by the fixture runs (pack 51). They have not had a further independent review:
any closure review must include them.

### 0.10 Version 4: Phase 3 hardening (directive "R6-B6 — Phase 3")

**Identities: four, each with one job.**

| Identity | Holds | Can reach |
|---|---|---|
| drill operator | the run directory (staged archive, encrypted input disk), the per-user manager that inspects the archive | the signer (`sign`, `verify`), the QEMU launcher (`run`), the witness (`show`), each through one sudoers rule |
| QEMU (`scripts/restore-drill-qemu-launcher.sh`) | the pinned drill image, the QEMU binaries, `/dev/kvm`, the VM work directory | nothing: no sudoers rule, no access to the operator's, signer's or witness's files |
| signer (`scripts/restore-drill-evidence-signer.sh`) | the signing key, the evidence store, its head log | the witness (`append`, `show`) |
| witness (`scripts/restore-drill-evidence-witness.sh`) | the append-only log of every record digest | nothing |

- **QEMU identity (mandatory hardening 1).**
  - The launcher builds the QEMU command line itself, from its own root-owned configuration (image, digest, binaries,
    work directory) and the fixed D6 limits. The QEMU identity cannot change that configuration, so an escape cannot
    re-pin the next run.
  - The operator sends only the encrypted input disk (stdin, bounded) and the run id. It receives the fixed-size output
    disk (stdout) and one status line. A relayed TERM stops QEMU and removes every VM file.
- **Independent chain heads.**
  - The witness keeps `<NNNNNN> <sha256>` for every published record. It accepts only the next sequence number,
    exactly once, and only from the signer.
  - `dms_evidence_verify_store` checks EVERY witnessed digest against the store. Rollback, truncation, rewrite and
    unwitnessed publication are all detected, even if the signer's own identity did them.
  - The CI gate exports the store and the witness log as an artifact (off-host retention). On a drill host, the owner
    keeps the printed head off the host.
- **Refused and aborted drills are records.**
  - Once the drill configuration is trusted, every refusal is signed as `REFUSED:<stage>` and every interrupt as
    `ABORTED:<stage>`.
  - Such a record carries no host-verified and no guest facts; an unknown digest is `none`.
  - A refusal before the configuration is trusted (bad arguments, the production host, an untrusted configuration)
    has no trusted path and is printed only.
- **Verified install, never stated.**
  - The signer's `drill-host` profile requires a root-owned configuration and a separate witness. It verifies that the
    orchestrator, the QEMU launcher, the witness and the signer itself are root-owned, in root-owned directories, and
    records `INSTALL=root:<sha256 of their digests>`. It refuses a GO from any other install.
  - The `development` profile, for local tests only, records `INSTALL=unverified:development`. It can never be
    acceptance evidence.
  - Limit: the signer verifies the install, not which copy the caller ran. A compromised operator could run a modified
    copy; with QEMU under its own identity, that compromise is now outside QEMU's reach.
- **Plaintext at rest.** In the VM, the guest agent decrypts the archive onto a RAM tmpfs it mounts. The plaintext never
  reaches the root overlay, which is a file on the drill host.
- **Decompression limit.** The inspection unit counts the uncompressed bytes of every row. The working space is sized
  from the larger of 8 × the archive and 4 × the data, and refused above the 1024 MiB D6 ceiling, so a small but very
  compressible archive is refused up front.
- **Kept:** the structural restore order of P5-1 (§0.9); the fail-closed refusals for oversized, malformed and
  over-limit archives; invalid or revoked manifests; resource exhaustion.

### 0.11 The guest trust boundary (explicit)

- **Host-verified:** what the operator's host checks itself before the VM boots. Recorded as
  `HOST_VERIFIED=backup_sha256,archive_custom_format,manifest_signature,manifest_not_revoked,archive_toc,owners,extensions,per_table_counts,catalog_shape,image_sha256,no_nic_by_construction,limits`.
  - The TOC facts are the host's reading of the archive through `pg_restore` in a hardened, network-less unit
    (P4V-12).
  - The image digest is reported by the QEMU launcher, which verified it against its own root-owned pin.
- **Guest-asserted:** everything the disposable guest reports. Recorded as `GUEST_ASSERTED=…`: the restore result,
  table count, per-table digest, catalog shape, user code reachable or executed, fidelity, interfaces.
  - The host COMPARES these with its own expectations, but cannot verify how the guest produced them.
  - A compromised guest root can assert anything (VR-1).
- **A GO therefore means:** host-verified inputs AND a guest assertion that matched the host's independent expectation,
  from a guest that never held a network interface and ran no backup code before verification (P5-1). It is never
  stronger than the guest's integrity.
- **Never** is a guest assertion presented as host evidence: the record keeps the two in separate fields, and the
  signer refuses a record that mixes them.

### 0.12 Review round 6 (Phase 3): findings and dispositions

Round 6 had three independent reviewers on `23abd183`:
- Linux/systemd/QEMU and UID separation (R6A-*);
- PostgreSQL restore and P5-1 (R6B-*);
- evidence, key custody and the CI supply chain (R6C-*).

They found **4 HIGH**, all fixed in `6b8fee92` with regression tests. The PostgreSQL reviewer found no way to run code
from the backup: the P5-1 restore order held under every attack tried.

**Architecture after round 6 (supersedes the identity table in §0.10):**

| Component | Runs as | Reached by |
|---|---|---|
| operator | a person's account | places the backup and the signed manifest in the inbox (group `dmsdrill-inbox`, 2770); may start only the installed orchestrator, as the runner; may read the witness and verify the store |
| orchestrator (`restore-drill-vm.sh`) | `dmsdrill-runner` | the operator, through `sudo` of exactly the installed script. It is the only caller of the signer's `sign` and of the supervisor. |
| supervisor (`restore-drill-supervisor.sh`) | root | the runner only. Two fixed modes: `inspect` (a DynamicUser system unit, archive on stdin) and `vm` (QEMU in `dmsqemu.service`) |
| QEMU launcher | `dmsdrill-qemu`, inside `dmsqemu.service` | the supervisor only. No sudo; KVM through the `kvm` group; `/dev/kvm` is root:kvm 0660 |
| signer | `dmsdrill-signer` | the runner (`sign`, `verify`), the operator (`verify`) |
| witness | `dmsdrill-witness` | the signer (`append`, `show`); the runner and the operator (`show`) |

| ID | Sev. | Finding | Disposition |
|---|---|---|---|
| R6C-1 | HIGH (proved) | The operator could sudo to the signer and obtain a signed, witnessed GO for a drill that never ran. | **Fixed:** only the runner identity may call `sign`, and the operator can only start the root-owned orchestrator as the runner. The signer refuses any other caller (CI G2). |
| R6A-1 | HIGH | Everything that bounded QEMU shared its uid: an escape could kill its timeout, outlive the run, keep the lock and see the next run. | **Fixed:** QEMU runs in the supervisor's system unit. The cgroup is killed whole at `RuntimeMaxSec`; stale run files are removed by root; there is no lock file; the unit's name gives exclusivity (CI Q6). |
| R6A-2 | HIGH | The QEMU account had host networking. | **Fixed:** `PrivateNetwork`, `IPAddressDeny=any`, AF_UNIX only (CI Q5, V1-qemu network namespace). |
| R6B-1 | HIGH (proved) | CHECKs depending on the session or the clock (`CURRENT_USER`, `CURRENT_TIMESTAMP`, `'now'::date`) passed the guard: GO in the drill, FAIL in a real restore. | **Fixed:** the guard tests every CHECK with PostgreSQL's own immutability check (as an index expression on a temporary copy; 42P17 refuses) and denies `SQLVALUEFUNCTION`. Restore role and database names are random per run. Fixtures `rolebomb` and `timebomb`. |
| R6A-3 / R6C-7 | MEDIUM | The AppArmor user-namespace grant was host-wide in effect. | **Fixed:** the inspection runs in a DynamicUser system unit, which needs no user namespace; the grant is removed. |
| R6C-3 / R6A-5 | MEDIUM | Outcomes could go unrecorded: lock holding, SIGKILL, uncaught signals, a VM booted directly. | **Fixed:** contract 4. A signed STARTED record opens every attempt; an outcome closes only the newest open start; a start that never closed is closed by the signer as `ABORTED:unclosed`; the supervisor boots a VM only for the open run; all catchable signals are recorded aborts. |
| R6C-2 | MEDIUM | Published but unwitnessed, the store wedged; signals and lock holding could force that. | **Fixed:** the witness is appended before the head log; `recover-head` repairs every window, verifying each earlier digest; publication runs in the signer's own shell; signals are ignored; outputs are printed after the locks are released. |
| R6A-4 / R6C-4 | MEDIUM | Long guest values, or no guest output, made a FAIL unrecordable. | **Fixed:** limits for the two lists; a FAIL may carry no guest facts (tests). |
| R6C-5 / R6C-6 | MEDIUM | V13 trusted the signer's own anchor; the verifier did not re-check consistency; the witness parser dropped a last line without a newline. | **Fixed:** the signer key is pinned before any record; every record is re-checked at verification and must carry the same store id; the witness log must be exact lines. |
| R6A-6 | MEDIUM | Plaintext-equivalent data could reach swap, core dumps or sudo I/O logs. | **Fixed:** `MemorySwapMax=0`, no core dumps, `dump-guest-core=off`, disks on the unit's RAM runtime directory, the runner's work directory on tmpfs (CI; runbook), sudoers `!log_input !log_output !use_pty`. |
| R6A-7..11, R6C-8..12 | LOW | Free space and cgroup limits for QEMU; LC_ALL for byte counting; root-only launcher code; use_pty; KVM separation; INSTALL covering configuration and configured paths; fixed values; package provenance; store identity; self-hosted runners. | **Fixed** (see the commit). The offline image packages must match the image's own package index. |
| R6B-2 | LOW | With an extension on the allowlist, a table added to the extension vanishes from every backup. | **Residual (condition):** the allowlist is `none`. Widening it requires a production-side check first (runbook). |
| R6B-3, R6B-4, R6B-5, R6A I4/I6/I8 | INFO | An honest DEFAULT or CHECK naming an app function fails with 42883 (fail-closed; the app has none); the drill's locale must equal production's; whitespace in TOC names; console directory; environment markers; q35 built-in devices. | **Recorded** (runbook notes: locale, 42883). |

Round 6 fixes were validated in CI and then had an independent fix-verification review (§0.13).

## 1. Problem and objective

Since D-Q7, the restore drill replays a plain-SQL backup as the PostgreSQL superuser on the production cluster. Before
R6-D, the runtime role owns the whole application schema, so it can plant schema code that pg_dump faithfully writes
into the backup: CHECK and domain expressions, defaults, generated columns, partition expressions, casts, operators,
aggregates. A backup that matches its trusted digest is therefore still attacker-influenced content. Replaying it as
the superuser runs that code as the superuser, and from there as the cluster's OS account, with every production
database in reach.

PR #695 answers this with a deny-list:
- psql restricted mode;
- a load guard that inspects the catalog before each data block;
- refusal of split CHECK entries.

Three independent review rounds each found a new superuser-execution path, so the deny-list cannot be shown complete.

**Objective (owner):** an untrusted backup is never replayed with superuser privileges on the production PostgreSQL
cluster.

**Design principle:** stop asking "which schema code is dangerous?" (a deny-list). Instead, make every piece of code in
the backup run where it cannot matter:
- in a disposable cluster;
- as an unprivileged, ephemeral OS identity;
- with no network and no view of production files or sockets;
- with no writable place that can hold executable code;
- with hard resource limits and a lifetime bounded by the kernel.

Then decide GO only from facts the host can check itself.

## 2. Threat model

| Item | Content |
|---|---|
| Adversary | Whoever controls the runtime database role or the application before R6-D. They can shape any schema object and any row the backup contains. They can also write the application's own files, including the app copy of the drill evidence (DR-8). |
| Not in scope | Root on the host. The operator passing a wrong trusted digest (a residual carried over from #695). Physical access. |
| Assumed capability inside the sandbox | Arbitrary native code as the sandbox identity. This is the worst case: a disposable superuser reaches it through an untrusted procedural language if one is installed, and a psql parser flaw could too. Every control below is a kernel or service-manager control, never a PostgreSQL privilege check alone. |
| Assets | The production cluster (data, roles, its OS account, data directory, socket, TCP port). The application (checkout, environment file, clinical evidence storage). Backups and deploy evidence. Host services (Redis, PHP-FPM, nginx, D-Bus, systemd and its varlink sockets). The cloud metadata service and hypervisor channels (vsock). Host availability (RAM, CPU, disk). The operator's terminal and the drill evidence. Patient data privacy (CG-11). |
| Security goal | Nothing the backup contains can read, write, connect to or signal a production asset, keep running after the drill, exhaust the host, or turn a broken restore into a GO. |
| Non-goal | Certifying that a backup is free of malicious content. The drill proves restorability of honest backups and containment of malicious ones. |

## 3. Architecture and trust boundaries

```
 TRUSTED: root on the production host                    ║  UNTRUSTED: transient unit (per-run name)
                                                         ║  (assume arbitrary code from the first byte replayed)
 operator ── sudo ──> scripts/rollout-restore-drill.sh   ║
   TB-2     (unchanged entry point, root)                ║   identity  DynamicUser (ephemeral uid, no groups),
   │                                                     ║             no capabilities, no_new_privs
   ├─ TB-1  trusted digest (root-only deploy log or      ║   files     read-only system; minimal /etc (5 files);
   │        an independent --expected-sha256)            ║             hidden /home /root /run /var /opt /mnt
   ├─ TB-1  source checks; root-only staged copy; sha256 ║             /media, host cgroups, /dev/pts;
   ├─ dump trailer check; expected table and row counts  ║             tmpfs /srv (size cap): PGDATA, socket, logs;
   │        taken from the dump text                     ║             private noexec /dev/shm; /tmp and /var/tmp
   ├─ TB-3  read-only preflight on production: role      ║             read-only (PrivateTmp is host disk)
   │        names (constant SQL, no backup content)      ║   network   own namespace (loopback only), AF_UNIX
   ├─ capacity check; lock; refuse while a unit exists   ║             only, IPAddressDeny=any
   │                                                     ║   exec      NoExecPaths=/ + ExecPaths allowlist;
   │  systemd-run (system manager) ═══ TB-4 ════════════>║             MemoryDenyWriteExecute; seccomp, also
   │    stdin:  restricted stream of the staged copy ───>║             without memfd, io_uring, keyring
   │    stdout: result channel <── ≤8 KiB, ≤64 lines ────║   limits    MemoryMax, MemorySwapMax=0, TasksMax,
   │    stderr: bounded sink                             ║             CPUQuota, OOMPolicy=stop, RuntimeMaxSec
   ├─ TB-10 strict parse; GO only from host-checkable    ║   self-check  attests the effective profile before
   │        facts (exits, bytes, completeness)           ║             it reads any input
   ├─ evidence: root-attested copy + app copy            ║   disposable PG16 (same pinned binaries)
   └─ cleanup: stop unit; staging copy already unlinked  ║   TB-5  drill_super: trusted setup, then NOLOGIN
                                                         ║         drill_restore NOSUPERUSER, member of the
                                                         ║         mirrored roles: replay + verification
 PROTECTED: never visible or reachable from the unit     ║
   production cluster (socket dir under /run, TCP port, data dir, OS account), app checkout, environment file,
   storage, backups, deploy logs, Redis/PHP-FPM/nginx sockets, D-Bus, systemd sockets, metadata service, vsock
```

| Boundary | What crosses it | What is trusted | Control |
|---|---|---|---|
| TB-1 Backup origin | the backup file | only the digest from the root-only deploy log, or an independent record | #695 chain, unchanged: digest validation, source checks, one root-only staged copy and its sha256. New: pg_dump trailer and `\unrestrict` key check (DR-7). |
| TB-2 Operator | the command line | root, and the digest the administrator passes | argument validation; no default backup |
| TB-3 Production cluster | constant read-only catalog SQL from the wrapper | the wrapper's own SQL | role names compared in bash, never interpolated; nothing is created or replayed on production |
| TB-4 OS identity | the unit start | systemd and the property set | DynamicUser, NoNewPrivileges, empty capability set, seccomp, RestrictNamespaces. The unit's own self-check attests the effective profile (DR-3). |
| TB-5 Database role, in the disposable cluster | the replay | nothing after the replay starts | replay and verification run as `drill_restore` (NOSUPERUSER). `drill_super` becomes NOLOGIN after setup (DR-5). Psql restricted mode is **load-bearing**: it stops `\c`, `\!` and `\o` before PostgreSQL sees them. |
| TB-6 Cluster | none | n/a | separate initdb, private socket in the 0700 PGDATA, `listen_addresses=''` |
| TB-7 Filesystem | the dump bytes (stdin only); the read-only inner script | a read-only system tree | allowlist mount view. Every writable directory is a size-capped noexec tmpfs, or the private mqueue. The self-check refuses anything else (DR-1). |
| TB-8 Network | none | n/a | the private network namespace is the network layer that holds alone. The family filter covers socket(2) only; io_uring is closed by seccomp (DR-2). IPAddressDeny is unproven (DR-3). |
| TB-9 External commands | exec | the pinned PG16 binaries, bash and dash | NoExecPaths=/ + ExecPaths; W^X (MemoryDenyWriteExecute, noexec writable mounts, no memfd_create: DR-4) |
| TB-10 Result channel and evidence | the unit's stdout | nothing it says | bounded strict parse; no cluster process inherits it (DR-10); GO only from host-checkable facts; error text never leaves (SQLSTATE only); the evidence is root-attested (DR-8) |
| TB-11 Kernel | everything | the kernel and systemd | shared kernel: residual R-1 |

## 4. Drill flow (target)

1. **Unchanged from #695:**
   - entry and arguments;
   - library and environment-file ownership checks;
   - the pinned client in `DMS_PG_BINDIR`. The check extends to `postgres`, `initdb` and `pg_isready`, and
     `postgres --version` must also report 16.15 or later.
2. **Integrity:**
   - unchanged from #695: trusted digest, source checks, root-only staged copy, sha256 of the copy. Phase 1 keeps the
     split-CHECK refusal;
   - new: the copy must end with pg_dump's trailer and a `\unrestrict` matching its `\restrict` key;
   - new: the host counts the `CREATE TABLE public.` statements and the COPY rows in the copy;
   - new: the copy is opened, then unlinked, and is hashed and streamed from the open descriptor, so no plaintext copy
     outlives the wrapper (DR-13).
3. **Read-only preflight on production.** Constant SQL as today; no database is created there.
   - D-Q6-a: every owner role named in the dump exists on production.
   - P5: the runtime ownership gate.
   - Non-system role names are read for mirroring (OD-ISO-2).
4. **Capacity and exclusivity:**
   - take a root-only lock; also refuse while any drill unit is loaded, since an orphaned unit outlives the lock (DR-13);
   - the per-run `User=` name has 31 characters or fewer and must not resolve through `getent` (DR-11);
   - tmpfs size = max(1 GiB, 6 × staged size), at most `DMS_RESTORE_DRILL_MAX_TMPFS_MB`;
   - MemoryMax = tmpfs + 512 MiB;
   - refuse (fail closed) when MemAvailable minus MemoryMax falls below a reserve.
5. **Launch the unit** with the property set in §5. `--wait --pipe --collect`; stderr goes to a bounded sink (DR-14).
   Environment: role names, locale, `TMPDIR=/srv`. Stdin: the restricted stream. The wrapper counts the bytes it
   streams.
6. **Inside the unit**, using bash builtins and pinned binaries only:
   - **profile self-check**, before any input, attesting the effective profile:
     - every writable directory is a noexec, size-capped tmpfs or the private mqueue;
     - Seccomp 2, NoNewPrivs 1, CapEff 0;
     - only `lo` in `/sys/class/net`;
     - in system mode, the uid is in the DynamicUser range (DR-3, DR-11);
   - initdb, then the server (§5.6);
   - mirror roles NOLOGIN; create `drill_restore`; create the database;
   - `ALTER ROLE drill_super NOLOGIN` (DR-5);
   - replay as `drill_restore` with `ON_ERROR_STOP` and `VERBOSITY=sqlstate`; fd 3 is closed for the server, the
     replay and verification (DR-10);
   - verify as `drill_restore` with `search_path = pg_catalog` and qualified names: tables, fidelity counts, base
     tables and total rows;
   - emit `DRILL key=value` lines;
   - stop the server immediately (SIGQUIT, F-ISO-4).
7. **Wrapper decision.** GO only when every condition holds; no condition rests on the sandbox's word alone:
   - unit exit 0 and feeder exit 0;
   - streamed bytes = the size of the restricted stream;
   - `result=GO`, no duplicate key, no rejected line;
   - **completeness**: the restored base tables and rows equal the counts the host took from the dump (DR-7);
   - `migrations_table` = 1.
8. **Evidence:**
   - schema v1, `production_overwrite: false`;
   - target `disposable_cluster_restore_drill_<stamp>` (a safe marker);
   - notes: the effective isolation profile (`systemctl show` of the security properties, DR-3), replay identity,
     fidelity and completeness counts;
   - root writes an **attested copy** with its sha256 into the trusted deploy-evidence directory. Readiness trusts that
     copy, not the runtime-writable app copy (DR-8).

   The app boot and health-route checks run outside the unit, as before.
9. **Cleanup:**
   - traps on EXIT, TERM and HUP stop the unit; the tmpfs dies with the mount namespace, DynamicUser and IPC are
     released;
   - cleanup never relies on SIGINT (F-ISO-3);
   - after a SIGKILL of the wrapper, the unit ends by itself at stdin EOF or RuntimeMaxSec, and the next run refuses to
     start until it is gone.

## 5. Isolation requirements

### 5.1 OS identity
- `DynamicUser=yes` with a per-run `User=` name of at most 31 characters (for example `dmsdrill-<12 hex>`) that does not
  resolve before launch. Otherwise systemd silently reuses an existing static user of that name (DR-11).
- After start, the uid must lie in 61184–65519.
- `NoNewPrivileges=yes`, `CapabilityBoundingSet=` (empty), `AmbientCapabilities=` (empty), `RestrictSUIDSGID=yes`,
  `KeyringMode=private`, `UMask=0077`, `LimitCORE=0`, `CoredumpFilter=0`, `LimitNOFILE=1024`.
- The identity owns nothing outside the unit, is not a role on the production cluster, and holds no credential.
- **Name resolution (DR-6, now certain):** PG16 `initdb` aborts when its effective uid has no passwd entry. The choice is
  part of OD-ISO-1:
  - **Option A:** bind only the socket `/run/systemd/userdb/io.systemd.DynamicUser` read-only. This is a small parser
    surface in PID 1; verify it in phase 3.
  - **Option B:** a static nologin system user. Then `/etc/passwd` is enough, but the uid is shared between runs, so
    the "refuse while any drill unit exists" rule becomes mandatory.

### 5.2 Filesystem (allowlist view)
- `ProtectSystem=strict`, `ProtectHome=yes`, `PrivateTmp=yes` (implied by DynamicUser, and it cannot be turned off),
  `PrivateDevices=yes`, `PrivateIPC=yes`.
- `TemporaryFileSystem=` with `:ro` for `/run`, `/var`, `/opt`, `/mnt`, `/media`.
- **`/etc` allowlist (DR-16):** `TemporaryFileSystem=/etc:ro` plus `BindReadOnlyPaths=` of `/etc/passwd`, `/etc/group`,
  `/etc/nsswitch.conf`, `/etc/ld.so.cache` and `/etc/localtime`. That is enough for initdb, the server and psql. Unit
  files, drop-ins and service configs are no longer visible.
- `InaccessiblePaths=/sys/fs/cgroup /dev/pts` (DR-12). The host cgroup tree exposes the PIDs and resource statistics of
  production services; `PrivateDevices` otherwise binds the host's devpts.
- `TemporaryFileSystem=/srv:size=<cap>,mode=1777,noexec,nosuid,nodev` for PGDATA, the socket, logs and `TMPDIR`.
- **F-ISO-1:** `PrivateDevices=yes` binds the host `/dev/shm`. Fix: `TemporaryFileSystem=/dev/shm:size=64M,mode=1777,noexec,nosuid,nodev`.
- **F-ISO-2:** `NoExecPaths=/` does not cover the mounts systemd adds itself. Fix: explicit `noexec`, and
  `NoExecPaths=/ /tmp /var/tmp`.
- **DR-1:** the `PrivateTmp=` directories are on the host root filesystem with no cap, and a dump could fill them
  through psql `\o`, or through native code. A `TemporaryFileSystem=/tmp` is silently overridden by PrivateTmp. Fix:
  `ReadOnlyPaths=/tmp /var/tmp` and `TMPDIR=/srv`.
- The inner script is bind-mounted read-only, from a root-owned file that is not writable by others.

### 5.3 Network
- `PrivateNetwork=yes`: loopback only, and a separate abstract Unix socket namespace. This layer was proven to hold on
  its own, including against an io_uring-created AF_INET socket.
- `RestrictAddressFamilies=AF_UNIX`. It filters socket(2) only, and holds alone for that path (L3). **DR-2:**
  `IORING_OP_SOCKET` bypasses it; io_uring is therefore removed by seccomp (L3b).
- AF_VSOCK is not covered by PrivateNetwork; both socket paths to it are now closed. Phase 5 checks `/dev/vsock` on
  the VPS.
- `IPAddressDeny=any`: **DR-3**, it is a silent no-op in the user-mode prototype and was never tested alone. It counts
  as a layer only after phase 3 proves it in system mode.
- PostgreSQL inside: `listen_addresses=''`, socket in the 0700 PGDATA.

### 5.4 Kernel, IPC and devices
- `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectKernelLogs`, `ProtectControlGroups`, `ProtectClock`,
  `ProtectHostname`.
- `ProtectProc=invisible`, `ProcSubset=pid`, `RestrictNamespaces=yes`, `LockPersonality=yes`, `RestrictRealtime=yes`,
  `MemoryDenyWriteExecute=yes`.
- `SystemCallArchitectures=native`, `SystemCallFilter=@system-service`, and
  `SystemCallFilter=~@privileged @resources @mount @swap @reboot @raw-io @module @debug @obsolete @cpu-emulation @clock`.
- **DR-2, DR-4, DR-12:** also `~memfd_create io_uring_setup io_uring_enter io_uring_register @keyring`. Before this
  change, a memfd ran a binary past every exec control. The default action kills the process (proven: `mount`,
  `setuid(0)`, `ptrace`, `memfd_create`, io_uring and `add_key` all end in SIGSYS).
- **DR-17:** the real ExecPaths set is wider than "PG16 plus a shell". It includes every PG16 tool and a dozen
  non-library executables in `/usr/lib/x86_64-linux-gnu`, and the loader can start any ELF file inside it. This is
  acceptable once memfd is closed; narrowing it to per-file ExecPaths is optional hardening.

### 5.5 Resources and lifetime
| Property | Value |
|---|---|
| MemoryMax | tmpfs cap + 512 MiB; tmpfs pages are charged to the unit |
| MemorySwapMax | 0: patient data never reaches swap |
| TasksMax | 64 |
| CPUQuota | 50% on production |
| Nice | 10 |
| OOMPolicy | stop: stopping the unit is what frees the tmpfs |
| OOMScoreAdjust | 800 |
| RuntimeMaxSec | for example 1800 |
| TimeoutStopSec | 10 |
| KillMode | control-group |

- `IOWeight=10` is only effective with the io controller and BFQ or io.cost. Disk writes are prevented outright instead
  (DR-1).

### 5.6 PostgreSQL inside the unit
- The same pinned PG16 binaries, which must report 16.15 or later.
- `initdb -E UTF8 --locale=<DMS_RESTORE_DRILL_LOCALE> --auth=trust -U drill_super`.
- Server settings:
  - `listen_addresses=''`, `unix_socket_directories=<PGDATA>`, `unix_socket_permissions=0700`;
  - `fsync`, `synchronous_commit` and `full_page_writes` off; `jit=off`; `autovacuum=off`;
  - `max_connections=8`, `shared_buffers` 64 MB, `max_locks_per_transaction=1024`;
  - log to a file in the tmpfs; no archiving and no replication; empty preload libraries.
- Roles:
  - mirrored role names NOLOGIN (names only);
  - `drill_restore`: LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS, a member of every mirrored
    role (INHERIT, SET);
  - the database is owned by `drill_restore`;
  - after setup, **`drill_super` becomes NOLOGIN** (DR-5).
- **Fidelity deviation:** each mirrored role gets CREATE on `public` before the replay.
- **What still runs with superuser rights (DR-15):** no session of `drill_super` runs after setup. However, a trusted
  extension that the dump creates runs its install script with superuser rights (PostgreSQL's trusted-extension rule),
  and the restore role can set per-role and per-database parameters (a denial-of-service lever only). Both stay inside
  the unit.
- **Psql restricted mode is load-bearing for TB-5:** without it, `\c - drill_super` would have reached the superuser
  before DR-5. It is never retired.
- The server always stops with SIGQUIT (F-ISO-4).
- Privacy: psql and server output stay in the tmpfs. Only the SQLSTATE and the stage leave the unit.
- **Prototype-only paths must not reach the implementation (DR-18):**
  - superuser replay mode;
  - the test report;
  - the `--relax`, `--compat-r4` and `--no-trailer-check` switches.

## 6. Files to change in the implementation PR (not in this document's commit)

| File | Change |
|---|---|
| `scripts/rollout-restore-drill.sh` | Replace createdb, replay, counts and dropdb on production with the unit launch. Keep the integrity chain and the read-only preflight. Add the trailer and count extraction, unlink-after-open staging, the lock plus the existing-unit refusal, the capacity check, the name and `getent` checks, and the host-side GO conditions (§4 step 7). Write the root-attested evidence copy. |
| `scripts/lib/restore-integrity.sh` | The pinned-client check also covers `postgres`, `initdb` and `pg_isready`, with a `postgres --version` 16.15+ check. Add the trailer and count helpers. Restricted stream, load guard and split-CHECK refusal stay. |
| `scripts/lib/restore-sandbox.sh` (new) | The single source of truth for the property set, unit naming, launch, bounded stderr, byte-counted feed, strict parse, effective-profile capture, cleanup and capacity check. |
| `scripts/restore-drill-sandbox-inner.sh` (new) | The in-unit orchestrator: builtins and pinned binaries only; profile self-check; NOLOGIN; fd hygiene; counts. |
| `app/Services/Foundation/RestoreDrillEvidenceService.php`, `config/rollout_readiness.php` | Verify the root-attested evidence copy, or treat the app copy as unattested (DR-8). |
| `.cursor/rules/235-deploy-least-privilege-sudo.mdc` §5 | The boundary becomes isolation; restricted mode is load-bearing; the residuals are restated. A new durable rule (next free number at implementation time) records the isolation profile. |
| `.claude/rules/deploy-infra.md` | One index line. |
| `docs/runbooks/roll-5-backup-restore-drill-runbook.md` | Prerequisites, result reading, cleanup, the orphaned-unit rule. |
| `docs/evidence/rollout/restore-drill-template.md` | New notes and the attested copy. |
| `docs/sprints/dms-spn4-ready-pilot-1.md` §13 | R6-B6 row: A-1 "isolation implemented, review pending"; R6-B6-F1 stays OPEN. |
| `config/ci_runner.php` | New suite token and mandatory suite (CG-19). |
| `tests/Feature/Foundation/RestoreDrillIsolationProfileTest.php` (new) | Static checks: the complete property set (including DR-1, DR-2, DR-4 and DR-12 lines); no production createdb, replay or dropdb; parser and GO conditions; inner script uses builtins only; DR-18 paths absent. |
| `tests/Feature/Foundation/RestoreDrillSandboxRuntimeTest.php` (new) | The real sandbox (this matrix, final-profile parts). It must actually run in CI (OD-ISO-7); a skip is not a pass. |
| `RestoreDrillTrustedBackupTest`, `RestoreDrillOwnershipPrivilegeTest`, `RestoreDrillOwnerRolePreflightTest` | Update expectations: no database on production; replay identity; completeness. |
| `config/release_safety.php` | The drill script is listed: check whether a manifest or hash refresh is needed. |
| `scripts/restore-rehearsal.sh`, `scripts/restore_postgres.sh` | No change in this PR (residual R-5). |

## 7. Migration path from the deny-list

| Phase | Content | Gate |
|---|---|---|
| 0 (now) | #695 held: remote `1892b0bd`, local `039a7f59`, A-1 OPEN. This design, the prototype, and review round 1 fixed in the prototype. | none |
| 1 | Owner decisions OD-ISO-1 to OD-ISO-9 (§10) | owner |
| 2 | Implement on a branch on top of `039a7f59` (every #695 commit kept), with base `bd52f97f` merged in. The unit becomes the boundary. Restricted mode stays (load-bearing); the load guard and split-CHECK refusal stay as defense in depth. | local tests |
| 3 | System-mode verification on a non-production Linux host or a CI runner with sudo. It covers: uid separation (the LIMIT rows); DynamicUser name resolution (DR-6); name reuse (DR-11); IPAddressDeny alone (DR-3); `/dev/shm` and devpts behaviour; the effective profile capture. | evidence |
| 4 | **Independent review of the implemented isolation controls** (directive item 13, review round 2). Only after it may A-1 be reported CLOSED. | reviewer |
| 5 | Production readiness, read-only, owner-run: systemd version; MemAvailable; database size; locale; untrusted PLs installed; `/dev/vsock` and vsock modules; `kernel.core_pattern` handler (DR-9); `vm.memfd_noexec` and `kernel.io_uring_disabled`; checkout and config ownership (DR-19). A first production drill only by a separate owner decision (forbidden today). | owner |
| 6 (later) | Retire the load guard and the split-CHECK refusal if the review agrees (they cost fidelity and are not the boundary). **Restricted mode is not retired.** Move `restore-rehearsal.sh` onto the unit. R6-D gives `restore_postgres.sh` a non-superuser path. | owner |

## 8. Positive and negative test matrix

The local results are in §11. Status values:
- **PROVEN-L**: proven locally in the user-mode prototype, final profile;
- **B→A**: the finding reproduced before its fix and was closed after it;
- **NOT RUN**: needs system mode or production;
- **PLANNED**: an implementation test.

| ID | Case | Expected | Status |
|---|---|---|---|
| POS-1 | Synthetic runtime-owned dump | GO, completeness match | PROVEN-L |
| POS-2 | Full application schema (238 migrations, 186 tables), final profile, restore role, restricted stream | decision GO; 186/186 tables, 238/238 rows; triggers, constraints, functions and indexes equal the source | PROVEN-L |
| POS-3 | Three concurrent drills | all GO, separate units, nothing left behind | PROVEN-L (production: one at a time) |
| NEG-F | Read or write production stand-ins (decoy secrets in home, `/dev/shm`, `/var/tmp`; the system cluster socket; `/run`; D-Bus; host cgroups; devpts) | refused | PROVEN-L (F-ISO-1, DR-12 B→A) |
| NEG-D | Writes to host disk (`/tmp`, `/var/tmp`) | refused (EROFS) | B→A (DR-1) |
| NEG-N | TCP, UDP, IPv6, netlink, packet, metadata service, abstract socket, decoy cluster by socket and TCP, dblink; io_uring sockets including AF_VSOCK | refused; the network namespace and the family filter each hold alone for socket(2); io_uring is killed | PROVEN-L, DR-2 B→A; IPAddressDeny NOT RUN (DR-3) |
| NEG-X | Exec outside the allowlist; exec or mmap of code written to any writable mount; anonymous RWX; `LOAD` of a copied library; memfd exec | refused | PROVEN-L (F-ISO-2, DR-4 B→A) |
| NEG-K | User and mount namespaces, `mount`, `setuid(0)`, `ptrace`, `add_key`, `/proc/sys`, `/sys` and cgroup writes, capabilities | refused | PROVEN-L |
| NEG-D2 | The disposable superuser (superuser replay mode): `pg_read_file`, `COPY TO` file, `COPY … PROGRAM`, `lo_export`, `LOAD`, dblink | contained by the unit | PROVEN-L |
| NEG-R | The same statements as the restore role | refused by PostgreSQL itself (42501) | PROVEN-L |
| NEG-E | `\c - drill_super` in the dump, without restricted mode | before: the superuser was reached and shell ran. After: NOLOGIN, replay FAIL. | B→A (DR-5) |
| NEG-M | A psql meta-command in the dump | stops the replay under restricted mode | PROVEN-L |
| NEG-V | Forged result lines: through `\o /proc/self/fd/3`, and by the disposable superuser through `/proc/<pid>/fd/3` | before: one forged line arrived. After: fd 3 is closed, nothing arrives, decision FAIL. The superuser path is seen as duplicates, FAIL. | B→A (DR-10); R-2 |
| NEG-T7 | Truncated dump; rows lost during the replay | refused before launch (no trailer); completeness mismatch gives decision FAIL although the sandbox says GO | B→A (DR-7) |
| NEG-S | Disk fill, memory bomb, endless statement, fork bomb | FAIL inside the unit; no host-disk write; nothing left behind | PROVEN-L |
| NEG-C | SIGINT, SIGTERM, SIGKILL of the wrapper mid-replay; replay failure | the unit ends; no process or mount left behind | PROVEN-L |
| NEG-PF | A weakened profile (drill tmpfs without noexec) | refused by the self-check before any input | PROVEN-L |
| NEG-P | Privacy: no row content, secret or error text in the result channel or the journal | none | PROVEN-L |
| NEG-I | Tampered backup, untrusted digest, symlink, group-writable file, swap race, missing evidence, old psql | refused before launch | PROVEN by #695 (26 PASS), unchanged |
| NEG-U | Another uid's files, signals and `/proc`; DynamicUser NSS (DR-6); name reuse (DR-11); IPAddressDeny alone (DR-3) | refused, or as specified | NOT RUN (phase 3) |
| NEG-H | Production host checks: vsock, core_pattern, sysctls, ownership (DR-2, DR-9, DR-19) | as specified | NOT RUN (phase 5) |
| NEG-T | Pest: profile completeness, parser, GO conditions, no production replay path, no DR-18 paths | as specified | PLANNED |

## 9. Residual risks

| ID | Risk | Treatment |
|---|---|---|
| R-1 | Shared kernel and systemd: a kernel or service-manager flaw could cross the boundary | seccomp (no io_uring, memfd or keyring), no capabilities, no_new_privs, RestrictNamespaces. A VM is the stronger alternative (OD-ISO-1). |
| R-2 | Verdict integrity: native code in the unit can still write to the inner script's result channel through `/proc/<pid>/fd/3` (same uid) | Forgery yields FAIL, never GO: duplicate keys fail, and GO rests on host-checkable facts (exits, bytes, completeness against the host's own counts). Containment never depends on the verdict. |
| R-3 | Restore fidelity: the disposable cluster is not the production cluster | Role attributes and memberships are not mirrored. CREATE on `public` is pre-granted. Locale comes from config. Server settings differ. The drill no longer proves a restore into the production cluster itself. |
| R-4 | Resource contention on the production host (RAM tmpfs, CPU) | cgroup limits, a fail-closed capacity check, one drill at a time, a quiet window |
| R-5 | `restore_postgres.sh` (real DR) and `restore-rehearsal.sh` still replay as the superuser into production; R6-B6-F1 OPEN | separate owner decisions; R6-D |
| R-6 | A wrong trusted digest, or root compromise before the deploy hashed the dump | unchanged from #695 |
| R-7 | Within the allowlisted `/etc` files and the read-only system tree, world-readable non-secret content is visible; inotify works on readable trees | the `/etc` allowlist (DR-16) |
| R-8 | systemd version: the profile was validated on systemd 255 in user mode. A known property can be silently ineffective (DR-3). | effective-profile capture plus the in-unit attestation; phase 3 and 5 checks |
| R-9 | The prototype runs in user mode under the operator's uid, so uid separation is not proven locally | phase 3 |
| R-10 | `/bin/sh` is executable inside the unit because initdb needs it | the replay runs as the restore role with restricted mode, and the superuser is NOLOGIN; a two-unit split is optional hardening |
| R-11 | A SIGKILLed wrapper leaves the unit running until stdin EOF or RuntimeMaxSec | bounded lifetime; the next run refuses to start while it exists |
| R-12 | Patient data sits in RAM (tmpfs) during the drill | MemorySwapMax=0; nothing in the journal; see R-13 |
| R-13 | Core dumps (DR-9): with a pipe `core_pattern`, the kernel ignores `LimitCORE`, so a crash could hand memory with restored rows to the host crash handler | `CoredumpFilter=0`; phase 5 reads `core_pattern` and confirms how the handler treats dynamic uids |
| R-14 | Evidence forgery by the runtime (DR-8, pre-existing): the app copy of the evidence is runtime-writable | root-attested copy (OD-ISO-9) |
| R-15 | Hypervisor channels (vsock) and device side channels | io_uring and socket(2) paths closed; phase 5 checks `/dev/vsock` |

## 10. Owner decisions needed

| ID | Decision | Recommendation |
|---|---|---|
| OD-ISO-1 | Architecture: a transient systemd unit on the production host, versus an off-host VM. Identity: DynamicUser with the single userdb socket (option A) or a static nologin user (option B). | the unit (keeps patient data in its current custody); option A if phase 3 confirms it, otherwise B with the existing-unit refusal |
| OD-ISO-2 | Role mirroring: names read read-only from the production catalog, versus a trusted manifest. Roles are created only inside the disposable cluster (amends how D-Q6-a is applied). | read-only catalog read |
| OD-ISO-3 | Replay identity inside the disposable cluster | the non-superuser restore role, with `drill_super` NOLOGIN after setup; accept the CREATE-on-`public` deviation |
| OD-ISO-4 | Defense-in-depth layers | restricted mode is **mandatory** (load-bearing); keep the load guard and split-CHECK refusal in phase 2, review their retirement in phase 6 |
| OD-ISO-5 | Resource policy (tmpfs cap, MemoryMax, CPUQuota, RuntimeMaxSec, one drill at a time, refuse when too large) | as in §5.5 |
| OD-ISO-6 | Delivery: fast-forward update of #695, versus a new PR stacked on it | update #695 (one review of one boundary) |
| OD-ISO-7 | CI: a job that runs the real sandbox in system mode (workflow change) | yes, otherwise the runtime suite only skips |
| OD-ISO-8 | The independent reviewer for phase 4 (review round 2) | owner's choice |
| OD-ISO-9 | Root-attested drill evidence, with readiness reading the attested copy (DR-8) | yes; it touches the readiness service, so it is a separate scoped change |

## 11. Local feasibility evidence

Local runs used WSL2, the systemd 255 per-user manager and PostgreSQL 16.15, with synthetic data only. No shared or
production system was touched; the workstation's own cluster socket was only `stat()`ed from inside the unit. The
evidence pack holds the scripts and every run's raw output.

**Final run (run 6): 116 PASS, 0 FAIL, 8 LIMITATION.**

**Defects found by our own runs (1–4), all fixed:**

| Finding | Defect | Fix |
|---|---|---|
| F-ISO-1 | `PrivateDevices=yes` exposes the host `/dev/shm`: the disposable superuser read and wrote a decoy secret | private `/dev/shm` tmpfs |
| F-ISO-2 | `NoExecPaths=/` misses `TemporaryFileSystem=` and `PrivateTmp=` mounts: code written there executed | explicit `noexec`; the self-check refuses a weakened profile |
| F-ISO-3 | SIGINT is ignored by a background-started wrapper | cleanup on EXIT/TERM/HUP plus unit limits |
| F-ISO-4 | a fast shutdown hung on a full tmpfs until RuntimeMaxSec | immediate shutdown |

**Independent adversarial review, round 1 (19 findings):** the reviewer ran 51 tool actions and created no files outside
its own scratch directory, which it removed.

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| DR-1 | HIGH | PrivateTmp is uncapped host disk, writable even through psql `\o`; a TemporaryFileSystem over it is silently dropped | **fixed**: `ReadOnlyPaths=/tmp /var/tmp`, `TMPDIR=/srv`, self-check requires capped tmpfs. B→A proven. |
| DR-2 | MEDIUM | io_uring creates sockets past `RestrictAddressFamilies`; AF_VSOCK reachable | **fixed**: io_uring syscalls filtered. B→A proven; L3 restated; vsock check in phase 5. |
| DR-3 | MEDIUM | IPAddressDeny untested alone, a no-op in user mode | **open to phase 3**; no longer counted; effective-profile capture plus attestation added |
| DR-4 | MEDIUM | memfd executes past ExecPaths and noexec | **fixed**: `memfd_create` filtered. B→A proven. |
| DR-5 | MEDIUM | `\c - drill_super` from the dump reached the superuser without restricted mode | **fixed**: NOLOGIN after setup. B→A proven. Restricted mode declared load-bearing. |
| DR-6 | MEDIUM | initdb needs a passwd entry, so hiding `/run` breaks DynamicUser | **design choice** OD-ISO-1 (option A or B); phase 3 |
| DR-7 | MEDIUM | a truncated dump got GO | **fixed**: trailer and key check, plus host-counted completeness. Proven: truncated → refused; lost rows → FAIL. |
| DR-8 | MEDIUM | the runtime can forge the app copy of the evidence (pre-existing) | **design change**: root-attested copy (OD-ISO-9) |
| DR-9 | MEDIUM | a pipe `core_pattern` ignores LimitCORE | **mitigated** in the profile (`CoredumpFilter=0`, accepted by systemd; not exercisable locally); handler check in phase 5 |
| DR-10 | LOW | every cluster process inherited the result channel | **fixed**: fd 3 closed. B→A proven. |
| DR-11 | LOW | DynamicUser silently reuses a static user; the unit name was too long for `User=` | **design**: short random name, `getent` check, uid-range assert |
| DR-12 | LOW | host cgroup tree, devpts and `add_key` exposed | **fixed**: InaccessiblePaths and `@keyring` filtered. B→A proven for cgroup. |
| DR-13 | LOW | an orphaned unit outlives the lock; the staging copy lingers | **design**: refuse while a unit exists; unlink-after-open staging; `OOMPolicy=stop` |
| DR-14 | LOW | the unit's stderr was a host file | **fixed**: bounded pipe (proven) |
| DR-15 | LOW | "nothing runs as the superuser after the first byte" was overstated | **reworded** in §5.6 |
| DR-16 | LOW | the `/etc` protection was a deny-list | **fixed**: `/etc` allowlist (5 files), initdb proven |
| DR-17 | INFO | ExecPaths is wider than stated | **documented** in §5.4 |
| DR-18 | INFO | prototype-only paths | **NEG-T** asserts their absence |
| DR-19 | INFO | the wrapper trusts checkout config files | phase 5 ownership check |

**What the final run shows (run 6):**
- **Production stand-ins**, each first proven reachable from the host (H1–H4): a decoy cluster with trust auth on a
  Unix socket in `/dev/shm` and on TCP 127.0.0.1, world-readable decoy secrets, and an abstract socket. From inside the
  unit, every one was hidden or unreachable.
- **Arbitrary native code** (a Python probe), as listed in NEG-F, NEG-D, NEG-N, NEG-X and NEG-K: every case refused.
  Process state: CapEff 0, NoNewPrivs 1, Seccomp 2.
- **The disposable superuser:** every escape was contained. **The restore role:** every escape was refused by
  PostgreSQL itself.
- **Before and after for each fix:** DR-1, DR-2, DR-4, DR-5, DR-7, DR-10 and DR-12 reproduced first, then were closed.
- **Resources:**
  - disk fill: FAIL in 1 s at the tmpfs cap;
  - memory bomb: stopped by MemoryMax;
  - endless statement: killed at RuntimeMaxSec;
  - fork bomb: 23 forks under TasksMax 32.
- **Cleanup:** SIGINT and SIGTERM stop the unit at once; after SIGKILL the unit ends by itself (17 s); nothing is left
  behind.
- **Fidelity:** the full schema restores with decision GO and completeness 186/186 tables, 238/238 rows. Triggers,
  constraints, functions and indexes (22, 1045, 5, 968) equal the source. Three concurrent drills all GO.
- **Privacy:** no stray line on the result channel, and nothing from inside any drill in the journal.
- **LIMITATION (8):** the user-mode prototype shares the operator's uid, so same-uid `/proc` and signals are not
  prevented here (phase 3). The 8 also cover R-2 as the disposable superuser and the shared abstract namespace when the
  network namespace is relaxed (by design).

Not run here: system-mode DynamicUser (phase 3), any production host, the deploy runner.

## 12. Recommendation

| Question | Recommendation |
|---|---|
| Implement this design (phases 1–2) | **GO**, after the owner decisions in §10. Locally it is feasible: the full schema restores with exact fidelity and completeness, every isolation layer held in the final matrix, and every proven review-round-1 finding is fixed and re-tested in the prototype. |
| Finding A-1 | **Stays OPEN.** NO-GO for any closure claim until the implementation, the system-mode tests (phase 3) and the independent review of the implemented controls (phase 4, round 2) are done. |
| A production drill | **NO-GO** (forbidden by the current directives; needs the phase 5 checks and a separate owner decision). |
| Merge of #695 | **NO-GO** (held). |
| R6-B6-F1 | Stays OPEN, separately. |
