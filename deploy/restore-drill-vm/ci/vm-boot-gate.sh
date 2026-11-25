#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the VM-BOOT GATE (owner directives D1, D8; "R6-B6 — Phase 3"). TEST TOOLING on a
# DISPOSABLE CI runner, after provision-drill-host.sh installed the drill-host layout (root-owned code, separate signer,
# witness and QEMU identities) with the drill image. It drives the INSTALLED orchestrator end to end under KVM with a
# throwaway manifest key and the synthetic fixture archives (tests/Fixtures/restore-drill), and records, per check,
# what it observed:
#   V1  the clean archive restores in the VM under the QEMU identity: GO, signed and witnessed record
#   V1-qemu  QEMU ran as dmsdrill-qemu (not the operator), with /dev/kvm open; V1-console  the guest booted (console)
#   V1-complete  the record's guest per-table digest and catalog shape equal the host's expectation
#   V2  a digest mismatch is refused before any VM boots, and the refusal is a signed record
#   V3  a tampered manifest is refused (recorded);  V4  a revoked manifest is refused (recorded, PR-9)
#   V5  a plain-SQL backup is refused (recorded, PR-6);  V6  the restore bomb fails in the VM (recorded, PR-2/P5-1)
#   V9  self-erasing pre-data code fails in the VM (P5-1);  V10  an extension outside the allowlist is refused (PR-8)
#   V11 a drill interrupted while the VM runs is stopped and recorded as ABORTED; nothing of it is left
#   V12 after every drill: no QEMU process, no VM file, no operator run directory, no plaintext left
#   V7  the signer verifies its store against its head log and every witnessed digest
#   V13 the operator verifies an exported copy of the store against the witness log independently of the signer
#   V8  the operator cannot read the signer's key
# The store, the head log, the witness log and the guest consoles are exported to <out> (synthetic data only; the
# signing key never), so the chain head is retained outside the runner (the workflow uploads <out>).
#
# Usage: vm-boot-gate.sh <out dir>
set -uo pipefail
OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
FX="$REPO/tests/Fixtures/restore-drill"
L=/usr/local/lib/dms-restore-drill
ORCH="$L/drill/scripts/restore-drill-vm.sh"
SIG=dmsdrill-signer; WIT=dmsdrill-witness; QEU=dmsdrill-qemu
[ -x "$ORCH" ] && id "$QEU" > /dev/null 2>&1 || { echo "the drill-host layout is not provisioned"; exit 2; }
RESULTS="$OUT/results-vm-boot-gate.txt"
: > "$RESULTS"
PASS=0; FAIL=0
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
W="$(mktemp -d)"
chmod 0700 "$W"
trap 'rm -rf -- "$W"' EXIT
ssh-keygen -q -t ed25519 -N '' -C owner -f "$W/owner"
echo "owner@ci $(cat "$W/owner.pub")" | sudo install -o root -g root -m 0644 /dev/stdin /etc/dms-restore-drill/manifest-anchor
sudo install -o root -g root -m 0644 /dev/null /etc/dms-restore-drill/revocations
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
printf '%s\n' "DMS_DRILL_MANIFEST_ANCHOR=/etc/dms-restore-drill/manifest-anchor" "DMS_DRILL_MANIFEST_REVOCATIONS=/etc/dms-restore-drill/revocations" \
  "DMS_DRILL_EXTENSION_ALLOWLIST=none" "DMS_DRILL_SIGNER_USER=${SIG}" "DMS_DRILL_SIGNER_COMMAND=${L}/signer/restore-drill-evidence-signer.sh" \
  "DMS_DRILL_QEMU_USER=${QEU}" "DMS_DRILL_QEMU_COMMAND=${L}/qemu/restore-drill-qemu-launcher.sh" "DMS_DRILL_PG_BINDIR=/usr/lib/postgresql/16/bin" \
  "DMS_DRILL_WORK_DIR=/var/lib/dms-restore-drill/operator" "DMS_DRILL_LOCALE=C.UTF-8" \
  | sudo install -o root -g root -m 0644 /dev/stdin /etc/dms-restore-drill/drill.conf
stage() { cp -- "$1" "$W/$2"; chmod 0600 "$W/$2"; }
for f in clean bomb vanish ext; do stage "$FX/$f.dump" "$f.dump"; done
printf -- '--\n-- PostgreSQL database dump\n--\n\nSELECT 1;\n' > "$W/plain.sql"; chmod 0600 "$W/plain.sql"
drill() { bash "$ORCH" "$@" > "$OUT/last.txt" 2>&1; echo $?; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
has() { grep -q -- "$2" "$OUT/$1.txt"; }
recorded() { grep -Eq '^Evidence: RECORD [0-9]{6} [0-9a-f]{64} witnessed' "$OUT/$1.txt"; }
clean_after() { # V12 after every drill
  local q v o
  q="$(pgrep -u "$QEU" -c 2> /dev/null || true)"
  v="$(sudo find /var/lib/dmsdrill-qemu/work -mindepth 1 ! -name .lock | wc -l)"
  o="$(find /var/lib/dms-restore-drill/operator -mindepth 1 -maxdepth 1 -name 'dmsdrill-*' | wc -l)"
  check "V12-$1" "nothing left after $1: no QEMU process, no VM file, no operator run directory" "$([ "${q:-0}" = 0 ] && [ "$v" = 0 ] && [ "$o" = 0 ]; echo $?)" "qemu_processes=${q:-0} vm_files=${v} run_dirs=${o}"
}

# --- V1: the clean archive, with a sampler that records which identity QEMU runs as and that it holds /dev/kvm --------
( for _ in $(seq 1 600); do
    p="$(pgrep -o -x qemu-system-x86 || pgrep -o -f '^/usr/bin/qemu-system-x86_64 ' || true)"
    if [ -n "$p" ]; then
      echo "qemu pid=${p} user=$(ps -o user= -p "$p") kvm_fds=$(sudo ls -l "/proc/${p}/fd" 2> /dev/null | grep -c /dev/kvm)"
      sudo cat "/proc/${p}/status" | grep -E '^(Uid|Gid|Groups|NoNewPrivs|Seccomp):'
      break
    fi
    sleep 0.5
  done ) > "$OUT/V1-qemu-identity.txt" 2>&1 &
SAMPLER=$!
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V1.txt"
wait "$SAMPLER"
check V1 "the clean archive restores in the VM: GO, signed and witnessed record" "$([ "$rc" = 0 ] && has V1 '^Decision: GO ' && recorded V1; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V1.txt" | head -c 60)"
check V1-qemu "QEMU ran as ${QEU}, not as the operator, with /dev/kvm open (no software emulation)" "$(grep -q "user=${QEU} kvm_fds=[1-9]" "$OUT/V1-qemu-identity.txt"; echo $?)" "$(head -n 1 "$OUT/V1-qemu-identity.txt" | head -c 90)"
# shellcheck disable=SC2024  # the operator, not the sudo target, writes this evidence file
sudo sh -c "cat /var/lib/dmsdrill-qemu/console/*.log" > "$OUT/V1-console.txt" 2> /dev/null || true
check V1-console "the guest kernel booted and the guest agent powered the VM off" "$(grep -Eq 'Linux version|reboot: Power down' "$OUT/V1-console.txt"; echo $?)" "$(grep -m1 -o 'Linux version [^ ]*' "$OUT/V1-console.txt")"
rec="$(sudo cat /var/lib/dmsdrill-signer/store/000001/record 2> /dev/null)"
exp="$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_EXPECTED=//p')"; gst="$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_GUEST_ASSERTED=//p')"
dig() { printf '%s' "$1" | tr ',' '\n' | sed -n "s/^$2=//p"; }
check V1-complete "the guest's per-table digest, table count and catalog shape equal the host's expectation (PR-5, P4P-1)" \
  "$([ -n "$(dig "$exp" per_table_digest)" ] && [ "$(dig "$exp" per_table_digest)" = "$(dig "$gst" per_table_digest)" ] && [ "$(dig "$exp" catalog_shape)" = "$(dig "$gst" catalog_shape)" ] && [ "$(dig "$exp" tables)" = "$(dig "$gst" tables)" ]; echo $?)" \
  "tables=$(dig "$gst" tables) shape=$(dig "$gst" catalog_shape) install=$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_INSTALL=//p' | head -c 20)"
clean_after V1

# --- Refusals before any VM boots: each one is a signed record (Phase 3) -------------------------------------------
rc="$(drill --expected-sha256 "$(printf 'a%.0s' {1..64})" --manifest "$W/manifest" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V2.txt"
check V2 "a digest mismatch is refused before any VM boots, and recorded" "$([ "$rc" = 2 ] && has V2 'DIGEST MISMATCH' && ! has V2 'VM boot' && recorded V2; echo $?)" "exit=$rc"
cp "$W/manifest" "$W/manifest-tampered"; cp "$W/manifest.sig" "$W/manifest-tampered.sig"
sed -i 's/^DMS_ROLE_MANIFEST_ROLES=.*/DMS_ROLE_MANIFEST_ROLES=app_runtime dms_owner intruder/' "$W/manifest-tampered"
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest-tampered" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V3.txt"
check V3 "a tampered manifest is refused before any VM boots, and recorded" "$([ "$rc" = 2 ] && has V3 'signature does not verify' && recorded V3; echo $?)" "exit=$rc"
echo "revoked ci-manifest-0099" | sudo install -o root -g root -m 0644 /dev/stdin /etc/dms-restore-drill/revocations
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest-revoked" "$W/clean.dump")"; cp "$OUT/last.txt" "$OUT/V4.txt"
check V4 "a revoked manifest is refused before any VM boots, and recorded (PR-9)" "$([ "$rc" = 2 ] && has V4 'is revoked' && recorded V4; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/plain.sql")" --manifest "$W/manifest" "$W/plain.sql")"; cp "$OUT/last.txt" "$OUT/V5.txt"
check V5 "a plain-SQL backup is refused (a custom-format archive is required, PR-6), and recorded" "$([ "$rc" = 2 ] && has V5 'custom-format archive is required' && recorded V5; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/ext.dump")" --manifest "$W/manifest" "$W/ext.dump")"; cp "$OUT/last.txt" "$OUT/V10.txt"
check V10 "an extension outside the allowlist is refused at the TOC, and recorded (PR-8)" "$([ "$rc" = 2 ] && has V10 'not in the allowlist (PR-8)' && recorded V10; echo $?)" "exit=$rc"

# --- Code-bearing archives in the VM (P5-1: they fail with 42883 before any of their code runs) ---------------------
rc="$(drill --expected-sha256 "$(sha "$W/bomb.dump")" --manifest "$W/manifest" "$W/bomb.dump")"; cp "$OUT/last.txt" "$OUT/V6.txt"
check V6 "the restore-bomb archive boots the VM and ends FAIL, recorded (PR-2, P5-1)" "$([ "$rc" = 1 ] && has V6 '^Decision: FAIL:' && recorded V6; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V6.txt" | head -c 70)"
clean_after V6
rc="$(drill --expected-sha256 "$(sha "$W/vanish.dump")" --manifest "$W/manifest" "$W/vanish.dump")"; cp "$OUT/last.txt" "$OUT/V9.txt"
check V9 "self-erasing pre-data code fails in the VM, recorded (P5-1)" "$([ "$rc" = 1 ] && has V9 '^Decision: FAIL:' && recorded V9; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V9.txt" | head -c 70)"
clean_after V9

# --- V11: forced termination while the VM runs: stopped, recorded as ABORTED, nothing left -----------------------------
bash "$ORCH" --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$W/manifest" "$W/clean.dump" > "$OUT/V11.txt" 2>&1 &
ORCH_PID=$!
for _ in $(seq 1 240); do grep -q 'VM boot' "$OUT/V11.txt" && pgrep -u "$QEU" > /dev/null && break; sleep 0.5; done
sleep 5
kill -TERM "$ORCH_PID"
wait "$ORCH_PID"; rc=$?
check V11 "a drill interrupted while the VM runs is stopped and recorded as ABORTED" "$([ "$rc" = 143 ] && has V11 '^ABORTED: signal at stage vm' && recorded V11; echo $?)" "exit=$rc"
clean_after V11

# --- The evidence chain, verified by the signer and, independently, by the operator against the witness ------------
r="$(sudo -n -u "$SIG" -- "$L/signer/restore-drill-evidence-signer.sh" verify 2>&1)"
check V7 "the signer's store verifies against its head log and every witnessed digest" "$(case "$r" in "VERIFIED 9 head="*) echo 0 ;; *) echo 1 ;; esac)" "$r"
mkdir -p "$OUT/evidence"
sudo cp -a /var/lib/dmsdrill-signer/store /var/lib/dmsdrill-signer/head.log /var/lib/dmsdrill-signer/allowed_signers "$OUT/evidence/"
# shellcheck disable=SC2024  # the operator, not the sudo target, writes this evidence file
sudo -n -u "$WIT" -- "$L/witness/restore-drill-evidence-witness.sh" show > "$OUT/evidence/witness.log" 2>&1
sudo sh -c "cp /var/lib/dmsdrill-qemu/console/*.log '$OUT/evidence/'" 2> /dev/null || true
sudo chown -R "$(id -un):" "$OUT/evidence"
r="$(bash -c '. "$1/scripts/lib/restore-evidence.sh"; dms_evidence_verify_store "$2/store" "$2/allowed_signers" "$2/head.log" "$2/witness.log"' _ "$REPO" "$OUT/evidence" 2>&1)"
check V13 "the operator independently verifies the exported store against the witness log (off-host head retention)" "$(case "$r" in "VERIFIED 9 head="*) echo 0 ;; *) echo 1 ;; esac)" "$r"
check V8 "the operator cannot read the signer's key" "$([ ! -r /var/lib/dmsdrill-signer/signing-key ]; echo $?)" "$(sudo stat -c '%U %a' /var/lib/dmsdrill-signer/signing-key 2> /dev/null)"
for f in "$OUT"/evidence/store/*/record; do grep -h '^DMS_DRILL_EVIDENCE_\(SEQ\|INSTALL\|DECISION\)=' "$f" | tr '\n' ' '; echo; done > "$OUT/evidence/decisions.txt"
echo "TOTAL pass=${PASS} fail=${FAIL}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
