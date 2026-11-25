#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — provisions a complete DRILL-HOST layout on a DISPOSABLE GitHub-hosted runner (owner
# directive "R6-B6 — Phase 3"; review round 6). TEST TOOLING: it needs passwordless sudo, so it only ever runs on a
# throwaway GitHub-hosted VM; never on a workstation, a self-hosted runner, a shared machine or the production host.
# A real drill host is provisioned by the owner following docs/runbooks/roll-5-backup-restore-drill-runbook.md.
#
# Identities (system accounts, no login, no home), besides the operator (the CI user):
#   dmsdrill-runner   runs the INSTALLED orchestrator; the operator may only start that (R6C-1); the only caller of
#                     the signer's sign and of the root supervisor
#   dmsdrill-signer   evidence key, store, head log;  dmsdrill-witness  the append-only head log
#   dmsdrill-qemu     QEMU, in the supervisor's system unit; member of kvm; may sudo nowhere
# Code root-owned under /usr/local/lib/dms-restore-drill; configuration root-owned in /etc/dms-restore-drill (0644:
# paths only, no secret, readable for the signer's config digest); exactly the sudoers rules below.
#
# Usage: provision-drill-host.sh <operator user> <drill image sha256 digests, space-separated> <evidence dir> [<image>]
set -euo pipefail
OPERATOR="${1:?operator user}"
IMAGES="${2:?the drill image digests the signer may record}"
EVID="${3:?a directory for the pinned signer key (written before any record)}"
IMAGE_SRC="${4:-}"
[[ "$OPERATOR" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "bad operator name"; exit 2; }
[[ "$IMAGES" =~ ^[0-9a-f]{64}(\ [0-9a-f]{64}){0,15}$ ]] || { echo "bad image digest list"; exit 2; }
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PROD_HOSTNAME="$(sed -n 's/^DMS_PRODUCTION_HOSTNAME=//p' "$REPO/deploy/production-host.conf" | head -n 1)"
[ "$(hostname)" != "$PROD_HOSTNAME" ] || { echo "never on the production host"; exit 2; }
# R6C-12: a GitHub-HOSTED, disposable runner only (GITHUB_ACTIONS is also set on self-hosted runners).
[ -n "${GITHUB_ACTIONS:-}" ] && [ "${RUNNER_ENVIRONMENT:-}" = github-hosted ] || { echo "refusing: this provisioning runs only on a GitHub-hosted runner"; exit 2; }
R=dmsdrill-runner; S=dmsdrill-signer; W=dmsdrill-witness; Q=dmsdrill-qemu
L=/usr/local/lib/dms-restore-drill
E=/etc/dms-restore-drill
for u in "$R" "$S" "$W" "$Q"; do sudo useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$u"; done
sudo groupadd --system dmsdrill-inbox
sudo usermod -aG dmsdrill-inbox "$OPERATOR"
sudo usermod -aG dmsdrill-inbox "$R"
# R6A-11: KVM only for the QEMU account; /dev/kvm stays root:kvm 0660.
getent group kvm > /dev/null || sudo groupadd --system kvm
sudo usermod -aG kvm "$Q"
if [ -c /dev/kvm ]; then sudo chgrp kvm /dev/kvm && sudo chmod 0660 /dev/kvm; fi

# --- Code, root-owned ------------------------------------------------------------------------------------------------
sudo install -d -o root -g root -m 0755 "$L" "$L/drill" "$L/drill/scripts" "$L/drill/scripts/lib" "$L/drill/deploy" \
  "$L/signer" "$L/signer/lib" "$L/witness" "$L/witness/lib" "$L/qemu" "$L/qemu/lib" "$L/supervisor" "$L/supervisor/lib"
sudo install -o root -g root -m 0755 "$REPO/scripts/restore-drill-vm.sh" "$L/drill/scripts/"
sudo install -o root -g root -m 0644 "$REPO"/scripts/lib/trusted-path.sh "$REPO"/scripts/lib/restore-integrity.sh "$REPO"/scripts/lib/restore-sandbox.sh \
  "$REPO"/scripts/lib/restore-manifest.sh "$REPO"/scripts/lib/restore-evidence.sh "$REPO"/scripts/lib/restore-vm.sh "$REPO"/scripts/lib/restore-archive.sh "$L/drill/scripts/lib/"
sudo install -o root -g root -m 0644 "$REPO/deploy/production-host.conf" "$L/drill/deploy/"
sudo install -o root -g root -m 0755 "$REPO/scripts/restore-drill-evidence-signer.sh" "$L/signer/"
sudo install -o root -g root -m 0644 "$REPO/scripts/lib/trusted-path.sh" "$REPO/scripts/lib/restore-evidence.sh" "$L/signer/lib/"
sudo install -o root -g root -m 0755 "$REPO/scripts/restore-drill-evidence-witness.sh" "$L/witness/"
sudo install -o root -g root -m 0644 "$REPO/scripts/lib/trusted-path.sh" "$L/witness/lib/"
sudo install -o root -g root -m 0755 "$REPO/scripts/restore-drill-qemu-launcher.sh" "$L/qemu/"
sudo install -o root -g root -m 0644 "$REPO/scripts/lib/trusted-path.sh" "$REPO/scripts/lib/restore-vm.sh" "$L/qemu/lib/"
sudo install -o root -g root -m 0755 "$REPO/scripts/restore-drill-supervisor.sh" "$L/supervisor/"
sudo install -o root -g root -m 0644 "$REPO/scripts/lib/trusted-path.sh" "$REPO/scripts/lib/restore-vm.sh" "$REPO/scripts/lib/restore-archive.sh" "$L/supervisor/lib/"

# --- State, each identity its own --------------------------------------------------------------------------------
sudo install -d -o root -g root -m 0755 /var/lib/dms-restore-drill /var/lib/dms-restore-drill/image
sudo install -d -o root -g dmsdrill-inbox -m 2770 /var/lib/dms-restore-drill/inbox
sudo install -d -o "$R" -g "$R" -m 0700 /var/lib/dms-restore-drill/runner
# R6A-6: the runner's staged copy and input disk live in RAM, gone with the run or the host.
sudo mount -t tmpfs -o "size=1536m,mode=0700,uid=$(id -u "$R"),gid=$(id -g "$R"),nosuid,nodev,noexec" tmpfs /var/lib/dms-restore-drill/runner
# R6F-E8: the signer's and the witness's state each on a file system of its own (here a small tmpfs; on a drill host a
# dedicated partition), so no world-writable directory shares it.
sudo install -d -o root -g root -m 0755 /var/lib/dmsdrill-signer /var/lib/dmsdrill-witness
sudo mount -t tmpfs -o "size=64m,mode=0700,uid=$(id -u "$S"),gid=$(id -g "$S"),nosuid,nodev,noexec" tmpfs /var/lib/dmsdrill-signer
sudo mount -t tmpfs -o "size=16m,mode=0700,uid=$(id -u "$W"),gid=$(id -g "$W"),nosuid,nodev,noexec" tmpfs /var/lib/dmsdrill-witness
sudo install -d -o "$S" -g "$S" -m 0700 /var/lib/dmsdrill-signer/store
# R6F-L10: the directory above the QEMU work directory is root's. R6F-L4: the work directory is its own bounded file
# system, so an escaped QEMU cannot fill the file system of the evidence store. On this disposable runner it is a
# 12 GiB tmpfs: no disk at all, and whatever QEMU writes there counts against its unit's 3 GiB memory cap (R6F-L14).
# A drill host uses a dedicated partition (runbook).
sudo install -d -o root -g root -m 0755 /var/lib/dmsdrill-qemu
sudo install -d -o "$Q" -g "$Q" -m 0700 /var/lib/dmsdrill-qemu/work /var/lib/dmsdrill-qemu/console
sudo mount -t tmpfs -o "size=12g,mode=0700,uid=$(id -u "$Q"),gid=$(id -g "$Q"),nosuid,nodev,noexec" tmpfs /var/lib/dmsdrill-qemu/work
# R6F-L8: no swap on the drill host (the runner's run directory is RAM only).
sudo swapoff -a
sudo -u "$S" ssh-keygen -q -t ed25519 -N '' -C "$S" -f /var/lib/dmsdrill-signer/signing-key
# R6C INFO: the anchor accepts the key for the evidence namespace only.
sudo -u "$S" sh -c 'printf "drill-signer@ci namespaces=\"dms-restore-drill-evidence\" %s\n" "$(cat /var/lib/dmsdrill-signer/signing-key.pub)" > /var/lib/dmsdrill-signer/allowed_signers && chmod 0600 /var/lib/dmsdrill-signer/allowed_signers'
# R6C-5: the signer's public key is pinned OUTSIDE the evidence, before any record exists.
mkdir -p "$EVID"
# shellcheck disable=SC2024  # the CI user writes the pinned copy; only the read needs root
sudo cat /var/lib/dmsdrill-signer/signing-key.pub > "$EVID/pinned-signer-key.pub"
printf 'drill-signer@ci namespaces="dms-restore-drill-evidence" %s\n' "$(cat "$EVID/pinned-signer-key.pub")" > "$EVID/pinned-anchor"
echo "pinned signer key: $(ssh-keygen -lf "$EVID/pinned-signer-key.pub")"
IMAGE=/var/lib/dms-restore-drill/image/drill.qcow2
if [ -n "$IMAGE_SRC" ]; then sudo install -o root -g root -m 0644 "$IMAGE_SRC" "$IMAGE"; fi

# --- Configuration, root-owned ------------------------------------------------------------------------------------
sudo install -d -o root -g root -m 0755 "$E"
conf() { sudo install -o root -g root -m 0644 /dev/stdin "$E/$1"; }
printf '%s\n' "DMS_SIGNER_KEY=/var/lib/dmsdrill-signer/signing-key" "DMS_SIGNER_PRINCIPAL=drill-signer@ci" \
  "DMS_SIGNER_ANCHOR=/var/lib/dmsdrill-signer/allowed_signers" "DMS_SIGNER_STORE=/var/lib/dmsdrill-signer/store" \
  "DMS_SIGNER_HEAD_LOG=/var/lib/dmsdrill-signer/head.log" "DMS_SIGNER_ALLOWED_CALLERS=${R}" "DMS_SIGNER_IMAGE_SHA256=${IMAGES}" \
  "DMS_SIGNER_PROFILE=drill-host" "DMS_SIGNER_WITNESS_USER=${W}" "DMS_SIGNER_WITNESS_COMMAND=${L}/witness/restore-drill-evidence-witness.sh" \
  "DMS_SIGNER_ORCHESTRATOR_DIR=${L}/drill" "DMS_SIGNER_QEMU_LAUNCHER=${L}/qemu/restore-drill-qemu-launcher.sh" \
  "DMS_SIGNER_SUPERVISOR=${L}/supervisor/restore-drill-supervisor.sh" | conf signer.conf
printf '%s\n' "DMS_WITNESS_LOG=/var/lib/dmsdrill-witness/heads.log" "DMS_WITNESS_APPEND_CALLERS=${S}" "DMS_WITNESS_SHOW_CALLERS=${S} ${R} ${OPERATOR}" | conf witness.conf
printf '%s\n' "DMS_QEMU_BINARY=/usr/bin/qemu-system-x86_64" "DMS_QEMU_IMG=/usr/bin/qemu-img" "DMS_QEMU_IMAGE=${IMAGE}" \
  "DMS_QEMU_IMAGE_SHA256=${IMAGES%% *}" "DMS_QEMU_WORK_DIR=/var/lib/dmsdrill-qemu/work" "DMS_QEMU_CONSOLE_DIR=/var/lib/dmsdrill-qemu/console" | conf qemu.conf
printf '%s\n' "DMS_SUPERVISOR_CALLER=${R}" "DMS_SUPERVISOR_QEMU_USER=${Q}" "DMS_SUPERVISOR_SIGNER_USER=${S}" \
  "DMS_SUPERVISOR_QEMU_LAUNCHER=${L}/qemu/restore-drill-qemu-launcher.sh" "DMS_SUPERVISOR_QEMU_WORK_DIR=/var/lib/dmsdrill-qemu/work" \
  "DMS_SUPERVISOR_QEMU_CONSOLE_DIR=/var/lib/dmsdrill-qemu/console" \
  "DMS_SUPERVISOR_PG_BINDIR=/usr/lib/postgresql/16/bin" "DMS_SUPERVISOR_SIGNER_STORE=/var/lib/dmsdrill-signer/store" | conf supervisor.conf
# The drill's own configuration is part of the install the signer verifies (R6C-8): its signer and supervisor paths.
printf '%s\n' "DMS_DRILL_MANIFEST_ANCHOR=${E}/manifest-anchor" "DMS_DRILL_MANIFEST_REVOCATIONS=${E}/revocations" \
  "DMS_DRILL_EXTENSION_ALLOWLIST=none" "DMS_DRILL_SIGNER_USER=${S}" "DMS_DRILL_SIGNER_COMMAND=${L}/signer/restore-drill-evidence-signer.sh" \
  "DMS_DRILL_SUPERVISOR_COMMAND=${L}/supervisor/restore-drill-supervisor.sh" "DMS_DRILL_PG_BINDIR=/usr/lib/postgresql/16/bin" \
  "DMS_DRILL_WORK_DIR=/var/lib/dms-restore-drill/runner" "DMS_DRILL_LOCALE=C.UTF-8" | conf drill.conf

# --- Sudoers: exactly these rules (R6C-1: the operator has no route to the signer's sign) ---------------------------
ORCH="$L/drill/scripts/restore-drill-vm.sh"; SIGN="$L/signer/restore-drill-evidence-signer.sh"
WIT="$L/witness/restore-drill-evidence-witness.sh"; SUP="$L/supervisor/restore-drill-supervisor.sh"
{
  # R6A-6, R6A-10: no pseudo-terminal and no I/O logging for the drill's commands (the input disk is never logged).
  printf 'Defaults!%s,%s,%s,%s !use_pty, !log_input, !log_output\n' "$ORCH" "$SIGN" "$WIT" "$SUP"
  # R6F-E1: the caller's resource limits never reach the drill's identities (a lowered process limit could make the
  # signer fail between publication and the witness).
  printf 'Defaults!%s,%s,%s,%s rlimit_nproc=infinity, rlimit_nofile=65536, rlimit_as=infinity, rlimit_cpu=infinity, rlimit_data=infinity, rlimit_fsize=infinity, rlimit_stack=8388608, rlimit_memlock=8388608, rlimit_locks=infinity, rlimit_core=0\n' "$ORCH" "$SIGN" "$WIT" "$SUP"
  printf '%s ALL=(%s) NOPASSWD: %s *\n' "$OPERATOR" "$R" "$ORCH"
  # R6F-E1: the operator reads the witness log (lock-free); it has no route to the signer at all.
  printf '%s ALL=(%s) NOPASSWD: %s show\n' "$OPERATOR" "$W" "$WIT"
  printf '%s ALL=(%s) NOPASSWD: %s sign, %s verify\n' "$R" "$S" "$SIGN" "$SIGN"
  printf '%s ALL=(root) NOPASSWD: %s inspect *, %s vm *\n' "$R" "$SUP" "$SUP"
  printf '%s ALL=(%s) NOPASSWD: %s show\n' "$R" "$W" "$WIT"
  printf '%s ALL=(%s) NOPASSWD: %s append *, %s show\n' "$S" "$W" "$WIT" "$WIT"
} | sudo install -o root -g root -m 0440 /dev/stdin /etc/dms-restore-drill/sudoers
sudo visudo -cf /etc/dms-restore-drill/sudoers
# R6F-E4: then readable (0444, root-owned, writable by nobody; sudo refuses only writable files), so the signer's
# install digest covers these rules; /etc/sudoers.d itself is readable by root only, so the drop-in only includes them.
sudo chmod 0444 /etc/dms-restore-drill/sudoers
printf '@include /etc/dms-restore-drill/sudoers\n' | sudo install -o root -g root -m 0440 /dev/stdin /etc/sudoers.d/dms-restore-drill
sudo visudo -cf /etc/sudoers.d/dms-restore-drill
echo "drill host provisioned: operator=${OPERATOR} runner=${R} signer=${S} witness=${W} qemu=${Q}"
