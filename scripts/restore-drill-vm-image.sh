#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — builds the disposable drill VM image (owner directive D1). OWNER-RUN, on the isolated
# drill host only, never on the production host. Needs qemu-img and virt-customize (libguestfs-tools); the package
# installation inside the image is the only step that uses a network, and it happens here, never at drill time.
#
# Usage: restore-drill-vm-image.sh <ubuntu-24.04 cloud image> <its sha256 from Ubuntu's signed SHA256SUMS> <output.qcow2>
# Prints the output image's sha256: the owner pins it as DMS_DRILL_IMAGE_SHA256 in the drill configuration.
set -euo pipefail
SRC="${1:?ubuntu cloud image}"; SRC_SHA="${2:?its sha256}"; OUT="${3:?output qcow2}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
[[ "$SRC_SHA" =~ ^[0-9a-f]{64}$ ]] || { echo "FATAL: the source sha256 must be 64 hex digits"; exit 2; }
[ "$(sha256sum -- "$SRC" | cut -d' ' -f1)" = "$SRC_SHA" ] || { echo "FATAL: ${SRC} does not match the given sha256"; exit 2; }
PROD_HOSTNAME="$(sed -n 's/^DMS_PRODUCTION_HOSTNAME=//p' "$REPO/deploy/production-host.conf" | head -n 1)"
[ "$(hostname)" != "$PROD_HOSTNAME" ] || { echo "FATAL: never build or run the drill VM on the production host (D1)"; exit 2; }
command -v virt-customize > /dev/null && command -v qemu-img > /dev/null || { echo "FATAL: needs qemu-img and virt-customize"; exit 2; }
[ ! -e "$OUT" ] || { echo "FATAL: ${OUT} exists"; exit 2; }
STAGE="$(mktemp -d)"
trap 'rm -rf -- "$STAGE"' EXIT
mkdir -p "$STAGE/dms/lib"
install -m 0755 "$REPO/scripts/restore-drill-vm-guest.sh" "$REPO/scripts/restore-drill-sandbox-inner.sh" "$STAGE/dms/"
install -m 0644 "$REPO/scripts/lib/restore-sandbox.sh" "$REPO/scripts/lib/restore-vm.sh" "$STAGE/dms/lib/"
qemu-img convert -O qcow2 "$SRC" "$OUT"
qemu-img resize -q "$OUT" 10G
virt-customize -a "$OUT" \
  --install postgresql-16 \
  --run-command 'pg_dropcluster --stop 16 main || true; systemctl disable postgresql.service' \
  --run-command 'mkdir -p /usr/local/lib/dms-restore-drill' \
  --copy-in "$STAGE/dms/.:/usr/local/lib/dms-restore-drill" \
  --copy-in "$REPO/deploy/restore-drill-vm/dms-restore-drill-guest.service:/etc/systemd/system" \
  --run-command 'chown -R root:root /usr/local/lib/dms-restore-drill && chmod -R go-w /usr/local/lib/dms-restore-drill' \
  --run-command 'systemctl enable dms-restore-drill-guest.service' \
  --run-command 'systemctl mask ssh.service ssh.socket systemd-networkd.service systemd-resolved.service cloud-init.service cloud-init-local.service cloud-config.service cloud-final.service snapd.service unattended-upgrades.service apt-daily.timer apt-daily-upgrade.timer' \
  --run-command '/usr/lib/postgresql/16/bin/psql --version | grep -Eq "^psql \(PostgreSQL\) 16\.(1[5-9]|[2-9][0-9])"' \
  --root-password disabled
sha256sum -- "$OUT" | cut -d' ' -f1
