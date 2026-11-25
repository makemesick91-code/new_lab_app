#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — provisions the SEPARATE evidence-signer identity (VR-4) on a DISPOSABLE CI runner.
# TEST TOOLING: it needs passwordless sudo, so it only ever runs on a throwaway GitHub-hosted VM; never on a workstation,
# a shared machine, the drill host (where the owner provisions the signer) or the production host.
#
# Creates: the system account dmsdrill-signer (no login, no home), its key, allowed_signers anchor, store, head log and
# configuration (all 0600/0700, owned by it), the signer entrypoint and its two libraries installed root-owned outside
# the checkout, and ONE sudoers rule: <operator> may run exactly that entrypoint as dmsdrill-signer.
#
# Usage: provision-signer.sh <operator user>
set -euo pipefail
OPERATOR="${1:?operator user}"
[[ "$OPERATOR" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "bad operator name"; exit 2; }
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PROD_HOSTNAME="$(sed -n 's/^DMS_PRODUCTION_HOSTNAME=//p' "$REPO/deploy/production-host.conf" | head -n 1)"
[ "$(hostname)" != "$PROD_HOSTNAME" ] || { echo "never on the production host"; exit 2; }
[ -n "${GITHUB_ACTIONS:-}" ] || { echo "refusing: this provisioning runs only on a disposable CI runner"; exit 2; }
S=dmsdrill-signer
BIN=/usr/local/lib/dms-restore-drill/signer
HOME_S=/var/lib/dmsdrill-signer
sudo useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$S"
sudo install -d -o root -g root -m 0755 "$BIN" "$BIN/lib"
sudo install -o root -g root -m 0755 "$REPO/scripts/restore-drill-evidence-signer.sh" "$BIN/"
sudo install -o root -g root -m 0644 "$REPO/scripts/lib/trusted-path.sh" "$REPO/scripts/lib/restore-evidence.sh" "$BIN/lib/"
sudo install -d -o "$S" -g "$S" -m 0700 "$HOME_S" "$HOME_S/store"
sudo -u "$S" ssh-keygen -q -t ed25519 -N '' -C "$S" -f "$HOME_S/signing-key"
sudo -u "$S" sh -c "printf 'drill-signer@ci %s\n' \"\$(cat $HOME_S/signing-key.pub)\" > $HOME_S/allowed_signers && chmod 0600 $HOME_S/allowed_signers"
sudo install -d -o root -g root -m 0755 /etc/dms-restore-drill
printf 'DMS_SIGNER_KEY=%s\nDMS_SIGNER_PRINCIPAL=drill-signer@ci\nDMS_SIGNER_ANCHOR=%s\nDMS_SIGNER_STORE=%s\nDMS_SIGNER_HEAD_LOG=%s\nDMS_SIGNER_ALLOWED_CALLERS=%s\n' \
  "$HOME_S/signing-key" "$HOME_S/allowed_signers" "$HOME_S/store" "$HOME_S/head.log" "$OPERATOR" \
  | sudo install -o "$S" -g "$S" -m 0600 /dev/stdin /etc/dms-restore-drill/signer.conf
printf '%s ALL=(%s) NOPASSWD: %s/restore-drill-evidence-signer.sh sign, %s/restore-drill-evidence-signer.sh verify\n' "$OPERATOR" "$S" "$BIN" "$BIN" \
  | sudo install -o root -g root -m 0440 /dev/stdin /etc/sudoers.d/dms-restore-drill-signer
sudo visudo -cf /etc/sudoers.d/dms-restore-drill-signer
echo "signer provisioned: $S"
