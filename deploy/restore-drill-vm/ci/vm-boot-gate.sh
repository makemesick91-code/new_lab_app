#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the VM-BOOT GATE (owner directives D1, D8; "R6-B6 — Phase 3"; review round 6). TEST
# TOOLING on a DISPOSABLE GitHub-hosted runner, after provision-drill-host.sh installed the drill-host layout. The
# operator (the CI user) places each synthetic fixture archive and a signed throwaway manifest in the inbox and starts
# the INSTALLED orchestrator as the drill runner; QEMU runs in the root supervisor's system unit. Per check it records
# what it observed:
#   V1  the clean archive restores in the VM: GO; V1-qemu  QEMU ran as dmsdrill-qemu in dmsqemu.service, in its own
#       network namespace, with /dev/kvm open; V1-console  the guest booted; V1-complete  per-table digest, table count
#       and catalog shape equal the host's expectation
#   V2-V5, V10  refusals before any VM (digest, tampered manifest, revoked manifest, plain SQL, extension), recorded
#   V6, V9  code-bearing archives fail in the VM (P5-1), recorded;  V11  an interrupted drill is ABORTED, recorded
#   V12 after every drill: no QEMU process or unit, no VM file, no runner run directory
#   V14 the operator cannot open /dev/kvm;  V15 every drill has a STARTED record and exactly one outcome
#   V7  the signer verifies its store; V13 the operator verifies the exported store against the witness log with the
#       signer key pinned at provisioning, before any record existed (R6C-5);  V8 the operator cannot read the key
# Usage: vm-boot-gate.sh <out dir> <pinned anchor written by provision-drill-host.sh>
set -uo pipefail
OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
PINNED="${2:?pinned anchor}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
FX="$REPO/tests/Fixtures/restore-drill"
L=/usr/local/lib/dms-restore-drill
ORCH="$L/drill/scripts/restore-drill-vm.sh"
RUNR=dmsdrill-runner; SIG=dmsdrill-signer; WIT=dmsdrill-witness; QEU=dmsdrill-qemu
INBOX=/var/lib/dms-restore-drill/inbox
[ -x "$ORCH" ] && id "$QEU" > /dev/null 2>&1 && [ -s "$PINNED" ] || { echo "the drill-host layout is not provisioned"; exit 2; }
RESULTS="$OUT/results-vm-boot-gate.txt"
: > "$RESULTS"
PASS=0; FAIL=0; LIMIT=0
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
limit() { LIMIT=$((LIMIT + 1)); echo "LIMIT  $1  $2  [$3]" >> "$RESULTS"; }
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
cp "$W/manifest" "$W/manifest-tampered"; cp "$W/manifest.sig" "$W/manifest-tampered.sig"
sed -i 's/^DMS_ROLE_MANIFEST_ROLES=.*/DMS_ROLE_MANIFEST_ROLES=app_runtime dms_owner intruder/' "$W/manifest-tampered"
printf -- '--\n-- PostgreSQL database dump\n--\n\nSELECT 1;\n' > "$W/plain.sql"
for f in clean bomb vanish ext; do cp -- "$FX/$f.dump" "$W/$f.dump"; done
# The inbox: the operator's files, group-readable by the runner, writable by nobody else.
for f in manifest manifest.sig manifest-revoked manifest-revoked.sig manifest-tampered manifest-tampered.sig plain.sql clean.dump bomb.dump vanish.dump ext.dump; do
  sudo install -o "$(id -un)" -g dmsdrill-inbox -m 0640 "$W/$f" "$INBOX/$f"
done
# The drill configuration is the provisioned one (provision-drill-host.sh), verified by the signer as part of the install.
sudo test -f /etc/dms-restore-drill/drill.conf || { echo "vm-boot-gate: the drill host is not provisioned"; exit 2; }
# shellcheck disable=SC2024  # the operator, not the runner, writes the transcript
drill() { sudo -n -u "$RUNR" -- "$ORCH" "$@" > "$OUT/last.txt" 2>&1; echo $?; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
has() { grep -q -- "$2" "$OUT/$1.txt"; }
recorded() { grep -Eq '^Evidence: started RECORD [0-9]{6} [0-9a-f]{64} witnessed' "$OUT/$1.txt" && grep -Eq '^Evidence: RECORD [0-9]{6} [0-9a-f]{64} witnessed' "$OUT/$1.txt"; }
clean_after() { # V12 after every drill
  local q v o u
  q="$(pgrep -u "$QEU" -c 2> /dev/null || true)"
  v="$(sudo find /var/lib/dmsdrill-qemu/work -mindepth 1 | wc -l)"
  o="$(sudo find /var/lib/dms-restore-drill/runner -mindepth 1 -maxdepth 1 -name 'dmsdrill-*' | wc -l)"
  u="$(systemctl list-units --all --plain --no-legend 'dmsqemu*' 'dmsinspect-*' | wc -l)"
  check "V12-$1" "nothing left after $1: no QEMU process or unit, no VM file, no runner run directory" "$([ "${q:-0}" = 0 ] && [ "$v" = 0 ] && [ "$o" = 0 ] && [ "$u" = 0 ]; echo $?)" "qemu_processes=${q:-0} vm_files=${v} run_dirs=${o} units=${u}"
}

# --- V1, with a sampler: QEMU's account, cgroup, network namespace and /dev/kvm ------------------------------------
( for _ in $(seq 1 900); do
    # The QEMU process itself (its executable), never the timeout wrapper whose command line also names it (CI run 13).
    p=""
    for c in $(pgrep -u "$QEU" 2> /dev/null); do
      [ "$(sudo readlink "/proc/${c}/exe" 2> /dev/null)" = /usr/bin/qemu-system-x86_64 ] && { p="$c"; break; }
    done
    if [ -n "$p" ]; then
      # QEMU opens /dev/kvm a moment after it starts (CI run 13): sample until it holds it, or has ended.
      k=0
      for _ in $(seq 1 120); do
        k="$(sudo ls -l "/proc/${p}/fd" 2> /dev/null | grep -c /dev/kvm)"
        [ "$k" -gt 0 ] || ! sudo test -d "/proc/${p}" && break
        sleep 0.25
      done
      echo "qemu pid=${p} user=$(ps -o user= -p "$p") kvm_fds=${k}"
      echo "cgroup=$(sudo cat "/proc/${p}/cgroup")"
      echo "netns_qemu=$(sudo readlink "/proc/${p}/ns/net") netns_host=$(sudo readlink /proc/1/ns/net)"
      sudo cat "/proc/${p}/status" | grep -E '^(Uid|Gid|Groups|NoNewPrivs|Seccomp|CapEff):'
      break
    fi
    sleep 0.5
  done ) > "$OUT/V1-qemu-identity.txt" 2>&1 &
SAMPLER=$!
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$INBOX/manifest" "$INBOX/clean.dump")"; cp "$OUT/last.txt" "$OUT/V1.txt"
wait "$SAMPLER"
check V1 "the clean archive restores in the VM: GO, signed and witnessed record" "$([ "$rc" = 0 ] && has V1 '^Decision: GO ' && recorded V1; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V1.txt" | head -c 60)"
ns_q="$(sed -n 's/^netns_qemu=\([^ ]*\) .*/\1/p' "$OUT/V1-qemu-identity.txt")"; ns_h="$(sed -n 's/.* netns_host=//p' "$OUT/V1-qemu-identity.txt")"
check V1-qemu "QEMU ran as ${QEU} in dmsqemu.service, in its own network namespace, with /dev/kvm open" \
  "$(grep -q "user=${QEU} kvm_fds=[1-9]" "$OUT/V1-qemu-identity.txt" && grep -q 'cgroup=0::/system.slice/dmsqemu.service' "$OUT/V1-qemu-identity.txt" && [ -n "$ns_q" ] && [ "$ns_q" != "$ns_h" ]; echo $?)" \
  "$(head -n 1 "$OUT/V1-qemu-identity.txt" | head -c 70) netns=${ns_q}/${ns_h}"
# shellcheck disable=SC2024  # the operator, not the sudo target, writes this evidence file
sudo sh -c "cat /var/lib/dmsdrill-qemu/console/*.log" > "$OUT/V1-console.txt" 2> /dev/null || true
# shellcheck disable=SC2024  # the operator writes this evidence file
sudo sh -c "cat /var/lib/dmsdrill-qemu/console/*.stderr" > "$OUT/V1-qemu-stderr.txt" 2> /dev/null || true
check V1-console "the guest kernel booted and the guest agent powered the VM off" "$(grep -Eq 'Linux version' "$OUT/V1-console.txt"; echo $?)" "$(grep -m1 -o 'Linux version [^ ]*' "$OUT/V1-console.txt")"
rec="$(sudo sh -c 'for f in /var/lib/dmsdrill-signer/store/*/record; do grep -q "^DMS_DRILL_EVIDENCE_DECISION=GO$" "$f" && cat "$f"; done' | head -n 40)"
exp="$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_EXPECTED=//p')"; gst="$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_GUEST_ASSERTED=//p')"
dig() { printf '%s' "$1" | tr ',' '\n' | sed -n "s/^$2=//p"; }
check V1-complete "the guest's per-table digest, table count and catalog shape equal the host's expectation (PR-5)" \
  "$([ -n "$(dig "$exp" per_table_digest)" ] && [ "$(dig "$exp" per_table_digest)" = "$(dig "$gst" per_table_digest)" ] && [ "$(dig "$exp" catalog_shape)" = "$(dig "$gst" catalog_shape)" ] && [ "$(dig "$exp" tables)" = "$(dig "$gst" tables)" ]; echo $?)" \
  "tables=$(dig "$gst" tables) shape=$(dig "$gst" catalog_shape) install=$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_INSTALL=//p' | head -c 24)"
clean_after V1

# --- Refusals before any VM boots: each one is a signed record ----------------------------------------------------
rc="$(drill --expected-sha256 "$(printf 'a%.0s' {1..64})" --manifest "$INBOX/manifest" "$INBOX/clean.dump")"; cp "$OUT/last.txt" "$OUT/V2.txt"
check V2 "a digest mismatch is refused before any VM boots, and recorded" "$([ "$rc" = 2 ] && has V2 'DIGEST MISMATCH' && ! has V2 'VM boot' && recorded V2; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$INBOX/manifest-tampered" "$INBOX/clean.dump")"; cp "$OUT/last.txt" "$OUT/V3.txt"
check V3 "a tampered manifest is refused before any VM boots, and recorded" "$([ "$rc" = 2 ] && has V3 'signature does not verify' && recorded V3; echo $?)" "exit=$rc"
echo "revoked ci-manifest-0099" | sudo install -o root -g root -m 0644 /dev/stdin /etc/dms-restore-drill/revocations
rc="$(drill --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$INBOX/manifest-revoked" "$INBOX/clean.dump")"; cp "$OUT/last.txt" "$OUT/V4.txt"
check V4 "a revoked manifest is refused before any VM boots, and recorded (PR-9)" "$([ "$rc" = 2 ] && has V4 'is revoked' && recorded V4; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/plain.sql")" --manifest "$INBOX/manifest" "$INBOX/plain.sql")"; cp "$OUT/last.txt" "$OUT/V5.txt"
check V5 "a plain-SQL backup is refused (a custom-format archive is required, PR-6), and recorded" "$([ "$rc" = 2 ] && has V5 'custom-format archive is required' && recorded V5; echo $?)" "exit=$rc"
rc="$(drill --expected-sha256 "$(sha "$W/ext.dump")" --manifest "$INBOX/manifest" "$INBOX/ext.dump")"; cp "$OUT/last.txt" "$OUT/V10.txt"
check V10 "an extension outside the allowlist is refused at the TOC, and recorded (PR-8)" "$([ "$rc" = 2 ] && has V10 'not in the allowlist (PR-8)' && recorded V10; echo $?)" "exit=$rc"

# --- Code-bearing archives in the VM (P5-1: they fail with 42883 before any of their code runs) ---------------------
rc="$(drill --expected-sha256 "$(sha "$W/bomb.dump")" --manifest "$INBOX/manifest" "$INBOX/bomb.dump")"; cp "$OUT/last.txt" "$OUT/V6.txt"
check V6 "the restore-bomb archive boots the VM and ends FAIL, recorded (PR-2, P5-1)" "$([ "$rc" = 1 ] && has V6 '^Decision: FAIL:unit_exit_20 ' && recorded V6; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V6.txt" | head -c 70)"
clean_after V6
rc="$(drill --expected-sha256 "$(sha "$W/vanish.dump")" --manifest "$INBOX/manifest" "$INBOX/vanish.dump")"; cp "$OUT/last.txt" "$OUT/V9.txt"
check V9 "self-erasing pre-data code fails in the VM, recorded (P5-1)" "$([ "$rc" = 1 ] && has V9 '^Decision: FAIL:unit_exit_20 ' && recorded V9; echo $?)" "exit=$rc $(grep '^Decision' "$OUT/V9.txt" | head -c 70)"
clean_after V9

# --- V11: forced termination while the VM runs: stopped, recorded as ABORTED, nothing left -----------------------------
# shellcheck disable=SC2024  # the operator, not the runner, writes the transcript
sudo -n -u "$RUNR" -- "$ORCH" --expected-sha256 "$(sha "$W/clean.dump")" --manifest "$INBOX/manifest" "$INBOX/clean.dump" > "$OUT/V11.txt" 2>&1 &
ORCH_PID=$!
for _ in $(seq 1 240); do grep -q 'VM boot' "$OUT/V11.txt" && pgrep -u "$QEU" > /dev/null && break; sleep 0.5; done
sleep 5
kill -TERM "$ORCH_PID"
wait "$ORCH_PID"; rc=$?
check V11 "a drill interrupted while the VM runs is stopped and recorded as ABORTED" "$([ "$rc" = 143 ] && has V11 '^ABORTED: signal at stage vm' && recorded V11; echo $?)" "exit=$rc"
clean_after V11

# --- V14: KVM belongs to the QEMU account only ------------------------------------------------------------------------
if id -nG | tr ' ' '\n' | grep -qx kvm; then limit V14 "the CI user is in the kvm group on this runner image" "kvm_member"
else check V14 "the operator cannot open /dev/kvm (only the QEMU account can, R6A-11)" "$([ ! -w /dev/kvm ] && sudo -n -u "$QEU" test -w /dev/kvm; echo $?)" "$(stat -c '%U:%G %a' /dev/kvm)"; fi

# --- The evidence chain ---------------------------------------------------------------------------------------------
NREC="$(sudo find /var/lib/dmsdrill-signer/store -mindepth 1 -maxdepth 1 -name '[0-9][0-9][0-9][0-9][0-9][0-9]' | wc -l)"
r="$(sudo -n -u "$RUNR" -- sudo -n -u "$SIG" -- "$L/signer/restore-drill-evidence-signer.sh" verify 2>&1)"
check V7 "the signer's store verifies against its head log and every witnessed digest" "$(case "$r" in "VERIFIED ${NREC} head="*) echo 0 ;; *) echo 1 ;; esac)" "$r"
mkdir -p "$OUT/evidence"
sudo cp -a /var/lib/dmsdrill-signer/store /var/lib/dmsdrill-signer/head.log "$OUT/evidence/"
# shellcheck disable=SC2024  # the operator, not the sudo target, writes this evidence file
sudo -n -u "$WIT" -- "$L/witness/restore-drill-evidence-witness.sh" show > "$OUT/evidence/witness.log" 2>&1
sudo sh -c "cp /var/lib/dmsdrill-qemu/console/*.log /var/lib/dmsdrill-qemu/console/*.stderr '$OUT/evidence/'" 2> /dev/null || true
sudo chown -R "$(id -un):" "$OUT/evidence"
cp -- "$PINNED" "$OUT/evidence/pinned-anchor"
r="$(bash -c '. "$1/scripts/lib/restore-evidence.sh"; dms_evidence_verify_store "$2/store" "$2/pinned-anchor" "$2/head.log" "$2/witness.log" 1' _ "$REPO" "$OUT/evidence" 2>&1)"
check V13 "the operator verifies the exported store against the witness with the key pinned before any record (R6C-5)" "$(case "$r" in "VERIFIED ${NREC} head="*) echo 0 ;; *) echo 1 ;; esac)" "$r"
check V8 "the operator cannot read the signer's key" "$([ ! -r /var/lib/dmsdrill-signer/signing-key ]; echo $?)" "$(sudo stat -c '%U %a' /var/lib/dmsdrill-signer/signing-key 2> /dev/null)"
for f in "$OUT"/evidence/store/*/record; do grep -h '^DMS_DRILL_EVIDENCE_\(SEQ\|RUN_ID\|INSTALL\|DECISION\)=' "$f" | tr '\n' ' '; echo; done > "$OUT/evidence/decisions.txt"
starts="$(grep -c 'DECISION=STARTED' "$OUT/evidence/decisions.txt")"
check V15 "every drill has a STARTED record and exactly one outcome (contract 4)" "$([ "$starts" = 9 ] && [ "$NREC" = 18 ]; echo $?)" "started=${starts} records=${NREC}"
echo "TOTAL pass=${PASS} fail=${FAIL} limitation=${LIMIT}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
