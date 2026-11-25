#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the VM-BOOT GATE (owner directives D1, D8). TEST TOOLING on disposable infrastructure.
#
# Drives scripts/restore-drill-vm.sh end to end on a host with KVM, with throwaway keys, a signed synthetic role
# manifest and synthetic dumps only:
#   V1 a synthetic dump restores in the VM: exit 0, Decision GO, one signed evidence record that verifies;
#   V2 a backup whose digest does not match is refused before any VM boots;
#   V3 an unsigned (tampered) manifest is refused before any VM boots;
#   V4 a hostile dump (superuser-only statement) boots the VM and ends FAIL, recorded as a second signed record;
#   V5 the evidence chain verifies with both records and the expected head.
#
# Usage: vm-boot-gate.sh <drill image.qcow2 built by scripts/restore-drill-vm-image.sh> <out dir>
set -uo pipefail
IMAGE_SRC="${1:?drill image}"
OUT="$(mkdir -p "${2:?out dir}" && cd "$2" && pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
# shellcheck source=/dev/null
. "$REPO/scripts/lib/restore-evidence.sh"
RESULTS="$OUT/results-vm-boot-gate.txt"
: > "$RESULTS"
PASS=0; FAIL=0
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
W="$(mktemp -d)"
chmod 0700 "$W"
trap 'rm -rf -- "$W"' EXIT
mkdir -m 0700 "$W/store" "$W/work"
ssh-keygen -q -t ed25519 -N '' -C owner -f "$W/owner"
ssh-keygen -q -t ed25519 -N '' -C drill -f "$W/drill"
echo "owner@ci $(cat "$W/owner.pub")" > "$W/manifest-anchor"
echo "drill-host@ci $(cat "$W/drill.pub")" > "$W/evidence-anchor"
cp -- "$IMAGE_SRC" "$W/image.qcow2"
chmod 0600 "$W/image.qcow2" "$W/manifest-anchor" "$W/evidence-anchor" "$W/drill"
cat > "$W/manifest" <<EOF
DMS_ROLE_MANIFEST_CONTRACT=1
DMS_ROLE_MANIFEST_ID=ci-manifest-0001
DMS_ROLE_MANIFEST_ROLES=app_runtime
DMS_ROLE_MANIFEST_RUNTIME_ROLE=app_runtime
DMS_ROLE_MANIFEST_R6_OWNER_ROLE=none
DMS_ROLE_MANIFEST_ISSUED_BY=owner@ci
DMS_ROLE_MANIFEST_ISSUED_AT=$(date -u -d '-1 minute' +%Y-%m-%dT%H:%M:%SZ)
DMS_ROLE_MANIFEST_NOT_AFTER=$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)
EOF
ssh-keygen -q -Y sign -f "$W/owner" -n dms-restore-role-manifest "$W/manifest" > /dev/null
cat > "$W/drill.conf" <<EOF
DMS_DRILL_IMAGE=$W/image.qcow2
DMS_DRILL_IMAGE_SHA256=$(sha256sum "$W/image.qcow2" | cut -d' ' -f1)
DMS_DRILL_MANIFEST_ANCHOR=$W/manifest-anchor
DMS_DRILL_EVIDENCE_KEY=$W/drill
DMS_DRILL_EVIDENCE_SIGNER=drill-host@ci
DMS_DRILL_EVIDENCE_STORE=$W/store
DMS_DRILL_WORK_DIR=$W/work
DMS_DRILL_LOCALE=C.UTF-8
DMS_DRILL_QEMU=/usr/bin/qemu-system-x86_64
DMS_DRILL_QEMU_IMG=/usr/bin/qemu-img
EOF
chmod 0600 "$W/drill.conf"
dump() { # <file> [statement before the trailer]
  printf -- '--\n-- PostgreSQL database dump\n--\n\n\\restrict K1ci\n\nSET statement_timeout = 0;\nSELECT pg_catalog.set_config('"'"'search_path'"'"', '"'"''"'"', false);\nCREATE TABLE public.migrations (id integer NOT NULL);\nALTER TABLE public.migrations OWNER TO app_runtime;\nCOPY public.migrations (id) FROM stdin;\n1\n2\n\\.\n\n%s\n--\n-- PostgreSQL database dump complete\n--\n\n\\unrestrict K1ci\n' "${2:-}" > "$1"
  chmod 0600 "$1"
}
drill() { DMS_RESTORE_DRILL_CONF="$W/drill.conf" bash "$REPO/scripts/restore-drill-vm.sh" "$@" > "$OUT/last.txt" 2>&1; echo $?; }
dump "$W/ok.sql"
rc="$(drill --expected-sha256 "$(sha256sum "$W/ok.sql" | cut -d' ' -f1)" --manifest "$W/manifest" "$W/ok.sql")"
cp "$OUT/last.txt" "$OUT/V1.txt"
check V1 "a synthetic dump restores in the disposable VM: GO, signed evidence" "$([ "$rc" = 0 ] && grep -q '^Decision: GO$' "$OUT/V1.txt"; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V1.txt")"
rc="$(drill --expected-sha256 "$(printf 'a%.0s' {1..64})" --manifest "$W/manifest" "$W/ok.sql")"
cp "$OUT/last.txt" "$OUT/V2.txt"
check V2 "a digest mismatch is refused before any VM boots" "$([ "$rc" = 2 ] && grep -q 'does not match the expected digest' "$OUT/V2.txt" && ! grep -q 'VM boot' "$OUT/V2.txt"; echo $?)" "exit=$rc"
cp "$W/manifest" "$W/manifest-tampered"; cp "$W/manifest.sig" "$W/manifest-tampered.sig"
sed -i 's/^DMS_ROLE_MANIFEST_ROLES=app_runtime$/DMS_ROLE_MANIFEST_ROLES=app_runtime intruder/' "$W/manifest-tampered"
rc="$(drill --expected-sha256 "$(sha256sum "$W/ok.sql" | cut -d' ' -f1)" --manifest "$W/manifest-tampered" "$W/ok.sql")"
cp "$OUT/last.txt" "$OUT/V3.txt"
check V3 "a tampered manifest is refused before any VM boots" "$([ "$rc" = 2 ] && grep -q 'signature does not verify' "$OUT/V3.txt"; echo $?)" "exit=$rc"
dump "$W/hostile.sql" "COPY (SELECT 1) TO PROGRAM 'id';"
rc="$(drill --expected-sha256 "$(sha256sum "$W/hostile.sql" | cut -d' ' -f1)" --manifest "$W/manifest" "$W/hostile.sql")"
cp "$OUT/last.txt" "$OUT/V4.txt"
check V4 "a hostile dump boots the VM and ends FAIL, recorded" "$([ "$rc" = 1 ] && grep -q '^Decision: FAIL:' "$OUT/V4.txt"; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V4.txt")"
v="$(dms_evidence_verify_store "$W/store" "$W/evidence-anchor" "$(sha256sum "$W/store/000002.evidence" 2> /dev/null | cut -d' ' -f1)")"
check V5 "the evidence chain verifies with both records and the expected head" "$(case "$v" in "VERIFIED 2 head="*) echo 0 ;; *) echo 1 ;; esac)" "$v"
cp -- "$W"/store/*.evidence "$OUT/" 2> /dev/null || true
echo "TOTAL pass=${PASS} fail=${FAIL}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
