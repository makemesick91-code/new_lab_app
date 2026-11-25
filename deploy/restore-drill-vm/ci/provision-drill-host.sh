#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — provisions a complete DRILL-HOST layout on a DISPOSABLE CI runner (owner directive
# "R6-B6 — Phase 3"). TEST TOOLING: it needs passwordless sudo, so it only ever runs on a throwaway GitHub-hosted VM;
# never on a workstation, a shared machine or the production host. A real drill host is provisioned by the owner
# following docs/runbooks/roll-5-backup-restore-drill-runbook.md; this script shows the same layout.
#
# Identities (all system accounts, no login, no home): the operator (the CI user), and three separate ones:
#   dmsdrill-signer   holds the evidence key, store and head log; may only ask the witness to append and show
#   dmsdrill-witness  holds the independent, append-only head log
#   dmsdrill-qemu     runs QEMU; holds the VM work directory; may sudo nowhere; KVM through /dev/kvm only
# Code: the orchestrator, signer, witness and QEMU launcher installed ROOT-OWNED under /usr/local/lib/dms-restore-drill
# (nobody else can write them), so the signer can verify the install itself (INSTALL=root:<sha256>).
# Configuration: root-owned files in /etc/dms-restore-drill. Sudoers: exactly the rules below, nothing else.
#
# Usage: provision-drill-host.sh <operator user> <drill image sha256 digests, space-separated> [<drill image qcow2>]
set -euo pipefail
OPERATOR="${1:?operator user}"
IMAGES="${2:?the drill image digests the signer may record}"
IMAGE_SRC="${3:-}"
[[ "$OPERATOR" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "bad operator name"; exit 2; }
[[ "$IMAGES" =~ ^[0-9a-f]{64}(\ [0-9a-f]{64}){0,15}$ ]] || { echo "bad image digest list"; exit 2; }
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PROD_HOSTNAME="$(sed -n 's/^DMS_PRODUCTION_HOSTNAME=//p' "$REPO/deploy/production-host.conf" | head -n 1)"
[ "$(hostname)" != "$PROD_HOSTNAME" ] || { echo "never on the production host"; exit 2; }
[ -n "${GITHUB_ACTIONS:-}" ] || { echo "refusing: this provisioning runs only on a disposable CI runner"; exit 2; }
S=dmsdrill-signer; W=dmsdrill-witness; Q=dmsdrill-qemu
L=/usr/local/lib/dms-restore-drill
E=/etc/dms-restore-drill
for u in "$S" "$W" "$Q"; do sudo useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$u"; done

# --- Code, root-owned ------------------------------------------------------------------------------------------------
sudo install -d -o root -g root -m 0755 "$L" "$L/drill" "$L/drill/scripts" "$L/drill/scripts/lib" "$L/drill/deploy" \
  "$L/signer" "$L/signer/lib" "$L/witness" "$L/witness/lib" "$L/qemu" "$L/qemu/lib"
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

# --- State, each identity its own ----------------------------------------------------------------------------------
sudo install -d -o root -g root -m 0755 /var/lib/dms-restore-drill /var/lib/dms-restore-drill/image
sudo install -d -o "$S" -g "$S" -m 0700 /var/lib/dmsdrill-signer /var/lib/dmsdrill-signer/store
sudo install -d -o "$W" -g "$W" -m 0700 /var/lib/dmsdrill-witness
sudo install -d -o "$Q" -g "$Q" -m 0700 /var/lib/dmsdrill-qemu /var/lib/dmsdrill-qemu/work /var/lib/dmsdrill-qemu/console
sudo install -d -o "$OPERATOR" -g "$OPERATOR" -m 0700 /var/lib/dms-restore-drill/operator
sudo -u "$S" ssh-keygen -q -t ed25519 -N '' -C "$S" -f /var/lib/dmsdrill-signer/signing-key
sudo -u "$S" sh -c 'printf "drill-signer@ci %s\n" "$(cat /var/lib/dmsdrill-signer/signing-key.pub)" > /var/lib/dmsdrill-signer/allowed_signers && chmod 0600 /var/lib/dmsdrill-signer/allowed_signers'
IMAGE=/var/lib/dms-restore-drill/image/drill.qcow2
if [ -n "$IMAGE_SRC" ]; then sudo install -o root -g root -m 0644 "$IMAGE_SRC" "$IMAGE"; fi

# --- Configuration, root-owned ------------------------------------------------------------------------------------
sudo install -d -o root -g root -m 0755 "$E"
conf() { # <file> <owner group> <mode> — content on stdin
  sudo install -o root -g "$2" -m "$3" /dev/stdin "$E/$1"
}
printf '%s\n' "DMS_SIGNER_KEY=/var/lib/dmsdrill-signer/signing-key" "DMS_SIGNER_PRINCIPAL=drill-signer@ci" \
  "DMS_SIGNER_ANCHOR=/var/lib/dmsdrill-signer/allowed_signers" "DMS_SIGNER_STORE=/var/lib/dmsdrill-signer/store" \
  "DMS_SIGNER_HEAD_LOG=/var/lib/dmsdrill-signer/head.log" "DMS_SIGNER_ALLOWED_CALLERS=${OPERATOR}" "DMS_SIGNER_IMAGE_SHA256=${IMAGES}" \
  "DMS_SIGNER_PROFILE=drill-host" "DMS_SIGNER_WITNESS_USER=${W}" "DMS_SIGNER_WITNESS_COMMAND=${L}/witness/restore-drill-evidence-witness.sh" \
  "DMS_SIGNER_ORCHESTRATOR_DIR=${L}/drill" "DMS_SIGNER_QEMU_LAUNCHER=${L}/qemu/restore-drill-qemu-launcher.sh" | conf signer.conf "$S" 0640
printf '%s\n' "DMS_WITNESS_LOG=/var/lib/dmsdrill-witness/heads.log" "DMS_WITNESS_APPEND_CALLERS=${S}" "DMS_WITNESS_SHOW_CALLERS=${S} ${OPERATOR}" \
  | conf witness.conf "$W" 0640
printf '%s\n' "DMS_QEMU_BINARY=/usr/bin/qemu-system-x86_64" "DMS_QEMU_IMG=/usr/bin/qemu-img" "DMS_QEMU_IMAGE=${IMAGE}" \
  "DMS_QEMU_IMAGE_SHA256=${IMAGES%% *}" "DMS_QEMU_WORK_DIR=/var/lib/dmsdrill-qemu/work" "DMS_QEMU_ALLOWED_CALLERS=${OPERATOR}" \
  "DMS_QEMU_CONSOLE_DIR=/var/lib/dmsdrill-qemu/console" | conf qemu.conf "$Q" 0640

# --- Sudoers: exactly these rules ---------------------------------------------------------------------------------
{
  printf '%s ALL=(%s) NOPASSWD: %s sign, %s verify\n' "$OPERATOR" "$S" "$L/signer/restore-drill-evidence-signer.sh" "$L/signer/restore-drill-evidence-signer.sh"
  printf '%s ALL=(%s) NOPASSWD: %s run *\n' "$OPERATOR" "$Q" "$L/qemu/restore-drill-qemu-launcher.sh"
  printf '%s ALL=(%s) NOPASSWD: %s show\n' "$OPERATOR" "$W" "$L/witness/restore-drill-evidence-witness.sh"
  printf '%s ALL=(%s) NOPASSWD: %s append *, %s show\n' "$S" "$W" "$L/witness/restore-drill-evidence-witness.sh" "$L/witness/restore-drill-evidence-witness.sh"
} | sudo install -o root -g root -m 0440 /dev/stdin /etc/sudoers.d/dms-restore-drill
sudo visudo -cf /etc/sudoers.d/dms-restore-drill
echo "drill host provisioned: operator=${OPERATOR} signer=${S} witness=${W} qemu=${Q}"
