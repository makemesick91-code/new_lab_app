#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the VM-BOOT GATE (owner directives D1, D8). TEST TOOLING on disposable infrastructure.
#
# Drives scripts/restore-drill-vm.sh end to end on a host with KVM, with throwaway keys, a signed synthetic role
# manifest, the synthetic fixture archives (tests/Fixtures/restore-drill) and a SEPARATE signer identity that the CI
# job provisions (DMS_GATE_SIGNER_USER, DMS_GATE_SIGNER_COMMAND installed outside the checkout, sudoers rule):
#   V1 the clean archive restores in the VM: exit 0, Decision GO, a signed record (RECORD 000001)
#   V2 a digest mismatch is refused before any VM boots
#   V3 a tampered manifest is refused before any VM boots
#   V4 a revoked manifest is refused before any VM boots (PR-9)
#   V5 a plain-SQL backup is refused: a structured archive is required (PR-6)
#   V6 the restore-bomb archive boots the VM and ends FAIL:user_code_in_restore_path, recorded (PR-2)
#   V7 the signer's store verifies with both records against its head log (D10, PR-9)
#   V8 the operator cannot read the signer's key (VR-4)
#
# Usage: vm-boot-gate.sh <drill image.qcow2 built by scripts/restore-drill-vm-image.sh> <out dir>
set -uo pipefail
IMAGE_SRC="${1:?drill image}"
OUT="$(mkdir -p "${2:?out dir}" && cd "$2" && pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
FX="$REPO/tests/Fixtures/restore-drill"
: "${DMS_GATE_SIGNER_USER:?the CI job provisions a separate signer account}"
: "${DMS_GATE_SIGNER_COMMAND:?the signer entrypoint installed outside the checkout}"
: "${DMS_GATE_SIGNER_KEY:?the signer key path (must be unreadable here)}"
RESULTS="$OUT/results-vm-boot-gate.txt"
: > "$RESULTS"
PASS=0; FAIL=0
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
W="$(mktemp -d)"
chmod 0700 "$W"
trap 'rm -rf -- "$W"' EXIT
mkdir -m 0700 "$W/work"
ssh-keygen -q -t ed25519 -N '' -C owner -f "$W/owner"
echo "owner@ci $(cat "$W/owner.pub")" > "$W/manifest-anchor"
cp -- "$IMAGE_SRC" "$W/image.qcow2"
: > "$W/revocations"
chmod 0600 "$W/image.qcow2" "$W/manifest-anchor" "$W/revocations"
manifest() { # <file> <id>
  {
    printf 'DMS_ROLE_MANIFEST_CONTRACT=1\nDMS_ROLE_MANIFEST_ID=%s\nDMS_ROLE_MANIFEST_ROLES=app_runtime dms_owner\n' "$2"
    printf 'DMS_ROLE_MANIFEST_RUNTIME_ROLE=app_runtime\nDMS_ROLE_MANIFEST_R6_OWNER_ROLE=none\nDMS_ROLE_MANIFEST_ISSUED_BY=owner@ci\n'
    printf 'DMS_ROLE_MANIFEST_ISSUED_AT=%s\nDMS_ROLE_MANIFEST_NOT_AFTER=%s\n' "$(date -u -d '-1 minute' +%Y-%m-%dT%H:%M:%SZ)" "$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)"
  } > "$1"
  ssh-keygen -q -Y sign -f "$W/owner" -n dms-restore-role-manifest "$1" > /dev/null
}
manifest "$W/manifest" ci-manifest-0001
manifest "$W/manifest-revoked" ci-manifest-0099
cat > "$W/drill.conf" << EOF
DMS_DRILL_IMAGE=$W/image.qcow2
DMS_DRILL_IMAGE_SHA256=$(sha256sum "$W/image.qcow2" | cut -d' ' -f1)
DMS_DRILL_MANIFEST_ANCHOR=$W/manifest-anchor
DMS_DRILL_MANIFEST_REVOCATIONS=$W/revocations
DMS_DRILL_EXTENSION_ALLOWLIST=none
DMS_DRILL_SIGNER_USER=${DMS_GATE_SIGNER_USER}
DMS_DRILL_SIGNER_COMMAND=${DMS_GATE_SIGNER_COMMAND}
DMS_DRILL_PG_BINDIR=/usr/lib/postgresql/16/bin
DMS_DRILL_WORK_DIR=$W/work
DMS_DRILL_LOCALE=C.UTF-8
DMS_DRILL_QEMU=/usr/bin/qemu-system-x86_64
DMS_DRILL_QEMU_IMG=/usr/bin/qemu-img
EOF
chmod 0600 "$W/drill.conf"
stage() { cp -- "$1" "$W/$2"; chmod 0600 "$W/$2"; }
stage "$FX/clean.dump" clean.dump
stage "$FX/bomb.dump" bomb.dump
printf -- '--\n-- PostgreSQL database dump\n--\n\nSELECT 1;\n' > "$W/plain.sql"; chmod 0600 "$W/plain.sql"
drill() { DMS_RESTORE_DRILL_CONF="$W/drill.conf" bash "$REPO/scripts/restore-drill-vm.sh" "$@" > "$OUT/last.txt" 2>&1; echo $?; }
sha() { sha256sum "$1" | cut -d' ' -f1; }

rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V1.txt"
check V1 "the clean archive restores in the disposable VM: GO, signed record" "$([ "$rc" = 0 ] && grep -q '^Decision: GO ' "$OUT/V1.txt" && grep -q '^Evidence: RECORD 000001 ' "$OUT/V1.txt"; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V1.txt" | head -c 60)"
rc="$(drill --expected-sha256 "$(printf 'a%.0s' {1..64})" --manifest "$W/manifest" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V2.txt"
check V2 "a digest mismatch is refused before any VM boots" "$([ "$rc" = 2 ] && grep -q 'does not match the expected digest' "$OUT/V2.txt" && ! grep -q 'VM boot' "$OUT/V2.txt"; echo $?)" "exit=$rc"
cp "$W/manifest" "$W/manifest-tampered"; cp "$W/manifest.sig" "$W/manifest-tampered.sig"
sed -i 's/^DMS_ROLE_MANIFEST_ROLES=.*/DMS_ROLE_MANIFEST_ROLES=app_runtime dms_owner intruder/' "$W/manifest-tampered"
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest-tampered" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V3.txt"
check V3 "a tampered manifest is refused before any VM boots" "$([ "$rc" = 2 ] && grep -q 'signature does not verify' "$OUT/V3.txt"; echo $?)" "exit=$rc"
echo "revoked ci-manifest-0099" > "$W/revocations"
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest-revoked" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V4.txt"
check V4 "a revoked manifest is refused before any VM boots (PR-9)" "$([ "$rc" = 2 ] && grep -q 'is revoked' "$OUT/V4.txt"; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/plain.sql")" --manifest "$W/manifest" "$W/plain.sql")"; cp "$OUT/last.txt" "$OUT/V5.txt"
check V5 "a plain-SQL backup is refused: a structured archive is required (PR-6)" "$([ "$rc" = 2 ] && grep -q 'custom-format archive is required' "$OUT/V5.txt"; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/bomb.dump")" --manifest "$W/manifest" "$W/bomb.dump")"; cp "$OUT/last.txt" "$OUT/V6.txt"
check V6 "the restore-bomb archive boots the VM and ends FAIL:user_code_in_restore_path, recorded" "$([ "$rc" = 1 ] && grep -q '^Decision: FAIL:user_code_in_restore_path ' "$OUT/V6.txt" && grep -q '^Evidence: RECORD 000002 ' "$OUT/V6.txt"; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V6.txt" | head -c 70)"
v="$(sudo -n -u "$DMS_GATE_SIGNER_USER" -- "$DMS_GATE_SIGNER_COMMAND" verify 2>&1)"
check V7 "the signer's store verifies with both records against its head log" "$(case "$v" in "VERIFIED 2 head="*) echo 0 ;; *) echo 1 ;; esac)" "$v"
check V8 "the operator cannot read the signer's key (VR-4)" "$([ ! -r "$DMS_GATE_SIGNER_KEY" ]; echo $?)" "$(stat -c '%U %a' "$DMS_GATE_SIGNER_KEY" 2> /dev/null)"
echo "TOTAL pass=${PASS} fail=${FAIL}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
