#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — builds the disposable drill VM image (owner directive D1). OWNER-RUN, on the isolated
# drill host only, never on the production host. Needs qemu-img and virt-customize (libguestfs-tools); the package
# installation inside the image is the only step that uses a network, and it happens here, never at drill time.
#
# Usage: restore-drill-vm-image.sh <ubuntu-24.04 cloud image> <its sha256 from Ubuntu's signed SHA256SUMS> <output.qcow2> [extension allowlist, comma-separated, default none]
# Prints the output image's sha256: the owner pins it as DMS_DRILL_IMAGE_SHA256 in the drill configuration.
# Hardening baked in: every PostgreSQL extension control file outside plpgsql and the allowlist is deleted (PR-8, so
# CREATE EXTENSION of anything else cannot even be attempted in the VM). Review VR-6: apport purged and
# kernel.core_pattern neutralised (no root crash handler fed by the
# sandbox). The input payload is encrypted per run, so udev/blkid probing the drill disks only ever sees the host's
# header and random-looking bytes; a probe-suppression udev rule is to be validated on the real image (phase 3).
set -euo pipefail
SRC="${1:?ubuntu cloud image}"; SRC_SHA="${2:?its sha256}"; OUT="${3:?output qcow2}"; EXT_ALLOW="${4:-none}"; PKG_MODE="${5:-guest-apt}"
# PKG_MODE guest-apt  the image's own apt installs postgresql-16 (needs DNS inside the libguestfs appliance);
#          host-apt   offline: a no-network simulation inside a scratch copy lists the missing packages, this host's apt
#                     downloads them (verified against the signed Ubuntu archive), and dpkg installs them with no
#                     network at all in the appliance.
case "$PKG_MODE" in guest-apt | host-apt) : ;; *) echo "FATAL: package mode must be guest-apt or host-apt"; exit 2 ;; esac
[[ "$EXT_ALLOW" =~ ^(none|[a-z_][a-z0-9_]{0,62}(,[a-z_][a-z0-9_]{0,62}){0,15})$ ]] || { echo "FATAL: bad extension allowlist"; exit 2; }
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
install -m 0644 "$REPO/scripts/lib/restore-integrity.sh" "$REPO/scripts/lib/restore-sandbox.sh" "$REPO/scripts/lib/restore-vm.sh" "$STAGE/dms/lib/"
qemu-img convert -O qcow2 "$SRC" "$OUT"
qemu-img resize -q "$OUT" 10G
PKG_ARGS=(--install postgresql-16)
if [ "$PKG_MODE" = host-apt ]; then
  qemu-img convert -O qcow2 "$SRC" "$STAGE/sim.qcow2"
  virt-customize -a "$STAGE/sim.qcow2" --no-network --run-command 'apt-get install -s -y postgresql-16 > /var/tmp/dms-sim.txt 2>&1 || true'
  virt-cat -a "$STAGE/sim.qcow2" /var/tmp/dms-sim.txt > "$STAGE/sim.txt"
  # Exactly the versions the IMAGE's own apt resolved ("Inst <pkg> (<version> <origin> [arch])"): pkg=version, so the
  # host never substitutes a version from another repository it may have configured.
  mapfile -t PKGS < <(awk '/^Inst / { v = $3; sub(/^\(/, "", v); print $2 "=" v }' "$STAGE/sim.txt")
  [ "${#PKGS[@]}" -ge 1 ] && printf '%s\n' "${PKGS[@]}" | grep -q '^postgresql-16=16\.' || { echo "FATAL: the package simulation did not resolve postgresql-16"; cat "$STAGE/sim.txt"; exit 2; }
  mkdir -p "$STAGE/debs"
  (cd "$STAGE/debs" && apt-get download "${PKGS[@]}")
  echo "offline packages: ${PKGS[*]}"
  PKG_ARGS=(--no-network --copy-in "$STAGE/debs:/var/tmp" --run-command 'dpkg -i /var/tmp/debs/*.deb && rm -rf /var/tmp/debs')
fi
virt-customize -a "$OUT" \
  "${PKG_ARGS[@]}" \
  --run-command 'pg_dropcluster --stop 16 main || true; systemctl disable postgresql.service' \
  --run-command 'mkdir -p /usr/local/lib/dms-restore-drill' \
  --copy-in "$STAGE/dms/.:/usr/local/lib/dms-restore-drill" \
  --copy-in "$REPO/deploy/restore-drill-vm/dms-restore-drill-guest.service:/etc/systemd/system" \
  --run-command 'chown -R root:root /usr/local/lib/dms-restore-drill && chmod -R go-w /usr/local/lib/dms-restore-drill' \
  --run-command 'systemctl enable dms-restore-drill-guest.service' \
  --run-command 'apt-get purge -y apport apport-symptoms whoopsie || true' \
  --copy-in "$REPO/deploy/restore-drill-vm/prune-extensions.sh:/usr/local/lib/dms-restore-drill" \
  --run-command "/usr/local/lib/dms-restore-drill/prune-extensions.sh /usr/share/postgresql/16/extension ${EXT_ALLOW}" \
  --write '/etc/sysctl.d/90-dms-drill-no-core.conf:kernel.core_pattern=|/bin/false' \
  --run-command 'test ! -e /usr/share/apport && grep -qx "kernel.core_pattern=|/bin/false" /etc/sysctl.d/90-dms-drill-no-core.conf' \
  --run-command 'systemctl mask ssh.service ssh.socket systemd-networkd.service systemd-resolved.service cloud-init.service cloud-init-local.service cloud-config.service cloud-final.service snapd.service unattended-upgrades.service apt-daily.timer apt-daily-upgrade.timer' \
  --run-command '/usr/lib/postgresql/16/bin/psql --version | grep -Eq "^psql \(PostgreSQL\) 16\.(1[5-9]|[2-9][0-9])"' \
  --root-password disabled
sha256sum -- "$OUT" | cut -d' ' -f1
