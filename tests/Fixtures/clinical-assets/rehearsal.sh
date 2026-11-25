#!/usr/bin/env bash
# R6-B13 synthetic rehearsal of the clinical asset backup and reconciliation (ClinicalAssetBackupTest).
# Synthetic files and a synthetic database only. It needs a PostgreSQL server reachable through the libpq environment
# with the right to create databases; it creates two throwaway databases and drops them on exit. Never point it at a
# server that holds real data: it refuses a database name that is not its own throwaway name.
# Usage: rehearsal.sh <new work dir>      prints "PASS|FAIL <case> <detail>" lines and "TOTAL pass=N fail=M"
set -uo pipefail
umask 077
export LC_ALL=C
W="${1:?work dir}"; [ ! -e "$W" ] || { echo "the work dir must not exist"; exit 2; }
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
B="$REPO/scripts/clinical-asset-backup.sh"; R="$REPO/scripts/clinical-asset-reconcile.sh"; ROOTS="$REPO/deploy/clinical-assets/roots.conf"
mkdir -p -m 0700 "$W"; W="$(cd "$W" && pwd)"
export GNUPGHOME="$W/gnupg"; mkdir -m 0700 "$GNUPGHOME"
TAG="cab_$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"; LIVE="${TAG}_live"; REST="${TAG}_restored"
cleanup() {
  psql -X -q -d postgres -c "DROP DATABASE IF EXISTS ${LIVE}" -c "DROP DATABASE IF EXISTS ${REST}" > /dev/null 2>&1
  gpgconf --kill gpg-agent > /dev/null 2>&1
  chmod -R u+w "$W" 2> /dev/null; rm -rf -- "$W"
}
trap cleanup EXIT
P=0; F=0
ok() { if [ "$2" = 0 ]; then P=$((P + 1)); echo "PASS $1 ${3:-}"; else F=$((F + 1)); echo "FAIL $1 ${3:-}"; fi; }
q() { psql -X -q -At -v ON_ERROR_STOP=1 -d "$1" -c "$2"; }
sha() { sha256sum | cut -d' ' -f1; }

# --- keys: the source holds only the PUBLIC key; the private key lives in a separate keyring (offline custody) ------
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key 'cab-synthetic <synthetic@example.invalid>' ed25519 sign never 2> /dev/null
FPR="$(gpg --list-keys --with-colons | awk -F: '/^fpr/{print $10; exit}')"
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-add-key "$FPR" cv25519 encr never 2> /dev/null
mkdir -m 0700 "$W/pubonly"; gpg --export "$FPR" | GNUPGHOME="$W/pubonly" gpg --batch --quiet --import 2> /dev/null

# --- the synthetic estate -------------------------------------------------------------------------------------------
S="$W/live/storage/app"; mkdir -p "$S"
put() { mkdir -p "$(dirname "$S/$1")"; head -c "$2" /dev/urandom > "$S/$1"; }
q postgres "CREATE DATABASE ${LIVE}"
q "$LIVE" "CREATE TABLE hw (id serial, branch_id int, path text, hash text);
  CREATE TABLE consent (id serial, branch_id int, path text);
  CREATE TABLE ktp (id serial, branch_id int, path text, checksum text, deleted_at timestamptz);
  CREATE TABLE upload (id serial, branch_id int, disk text, path text, sha text)"
for i in $(seq 1 30); do
  b=$(( i % 2 + 1 )); p="handwritings/${b}/${i}/handwriting_p1_${i}.png"; put "clinical-evidence-private/$p" 2048
  q "$LIVE" "INSERT INTO hw (branch_id, path, hash) VALUES ($b, '$p', '$(sha < "$S/clinical-evidence-private/$p")')"
done
for i in $(seq 1 10); do b=$(( i % 2 + 1 )); p="rme-consents/C${i}/consenter-${i}.png"; put "clinical-evidence-private/$p" 900; q "$LIVE" "INSERT INTO consent (branch_id, path) VALUES ($b, '$p')"; done
# an older consent signature still on the local disk (before the move to the evidence disk): found by fallback
put "private/rme-consents/C99/consenter-99.png" 900; q "$LIVE" "INSERT INTO consent (branch_id, path) VALUES (1, 'rme-consents/C99/consenter-99.png')"
for i in $(seq 1 6); do p="patient-documents/${i}/ktp-${i}.jpg"; put "private/$p" 4096; q "$LIVE" "INSERT INTO ktp (branch_id, path, checksum) VALUES (1, '$p', '$(sha < "$S/private/$p")')"; done
put "legacy-rme-private/rme-legacy/imports/p1/u1/source/source.pdf" 30000
q "$LIVE" "INSERT INTO upload (branch_id, disk, path, sha) VALUES (2, 'legacy_rme_private', 'rme-legacy/imports/p1/u1/source/source.pdf', '$(sha < "$S/legacy-rme-private/rme-legacy/imports/p1/u1/source/source.pdf")')"
put "public/logo.png" 100   # a non-clinical public file: an orphan by design
MAP="$W/map.tsv"
printf '%s\t%s\t%s\t%s\t%s\n' \
  hw durable 0 public "SELECT 'clinical_evidence', path, hash, branch_id FROM hw" \
  consent durable 1 local "SELECT 'clinical_evidence', path, NULL, branch_id FROM consent" \
  ktp durable 1 - "SELECT 'local', path, checksum, branch_id FROM ktp WHERE deleted_at IS NULL" \
  upload durable 1 - "SELECT disk, path, sha, branch_id FROM upload" > "$MAP"
STATE="$W/state"; STORE="$W/store"; OFF="$W/offhost"

# --- C1 first snapshot, verify, integrity --------------------------------------------------------------------------
out="$(bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR")"; rc=$?
ok C1-snapshot "$rc" "$(echo "$out" | cut -c1-140)"
ANCHOR1="$(tail -n 1 "$STORE/CHAIN")"
out="$(bash "$B" verify --store "$STORE" --deep)"; ok C1-verify-deep $? "$out"
out="$(bash "$B" integrity --storage "$S" --roots "$ROOTS" --state "$STATE")"; ok C1-integrity-clean $? "$out"
out="$(PGDATABASE="$LIVE" bash "$R" --map "$MAP" --out "$W/rec1" --storage "$S" --roots "$ROOTS")"; rc=$?
ok C1-reconcile-live-consistent "$rc" "$(echo "$out" | grep -E '^(ASSET consent|VERDICT)' | tr '\n' ' ')"
grep -q 'ok_fallback_unhashed=1' "$W/rec1/report.txt"; ok C1-fallback-local-disk $? "the pre-move consent signature is found on the local disk"
grep -q '^ROOT public-legacy-clinical files=1 orphans=1' "$W/rec1/report.txt"; ok C1-orphan-reported $? "$(grep '^ROOT public' "$W/rec1/report.txt")"

# --- C2 no plaintext in the store; the source's public key cannot decrypt ------------------------------------------
if grep -rqaF -e 'handwriting_p1_' -e 'rme-consents' -e 'patient-documents' "$STORE"; then ok C2-no-plaintext-paths 1; else ok C2-no-plaintext-paths 0 "no path or file name readable in the store"; fi
one="$(find "$STORE/objects" -name '*.gpg' | head -1)"
GNUPGHOME="$W/pubonly" gpg --batch --quiet --decrypt -- "$one" > /dev/null 2>&1; [ $? != 0 ]; ok C2-public-key-cannot-decrypt $? "the source host's keyring (public key only) cannot read the store"

# --- C3 incremental: nothing changed -> no new object; one new file -> one new object ------------------------------
out="$(bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR")"
echo "$out" | grep -q 'new_objects=0 '; ok C3-incremental-unchanged $? "$(echo "$out" | grep -o 'files=[0-9]* .*new_bytes=[0-9]*')"
# --- C4 the database's recovery point T, then later changes ---------------------------------------------------------
pg_dump -Fc -d "$LIVE" -f "$W/db-at-T.dump" || ok C4-dump 1
DELETED_KTP="patient-documents/3/ktp-3.jpg"
rm -f "$S/private/$DELETED_KTP"; q "$LIVE" "UPDATE ktp SET deleted_at = now() WHERE path = '$DELETED_KTP'"   # hard delete after T
for i in 31 32; do p="handwritings/1/${i}/handwriting_p1_${i}.png"; put "clinical-evidence-private/$p" 2048
  q "$LIVE" "INSERT INTO hw (branch_id, path, hash) VALUES (1, '$p', '$(sha < "$S/clinical-evidence-private/$p")')"; done  # written after T
put "clinical-evidence-private/handwritings/2/99/handwriting_p1_uncommitted.png" 2048   # an upload whose row never committed
out="$(bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR")"; ok C4-snapshot-after-T $? "$(echo "$out" | grep -o 'files=[0-9]*.*new_objects=[0-9]*')"
# restore both sides into isolation: the database at T, the files from the first snapshot after T
q postgres "CREATE DATABASE ${REST}"; pg_restore -d "$REST" --no-owner "$W/db-at-T.dump" 2> /dev/null
out="$(bash "$B" restore --store "$STORE" --target "$W/restored" --storage "$S")"; ok C4-restore-isolated $? "$out"
out="$(PGDATABASE="$REST" bash "$R" --map "$MAP" --out "$W/rec2" --tree "$W/restored")"; rc=$?
[ "$rc" = 1 ] && grep -q '^ASSET ktp .*missing=1' "$W/rec2/report.txt" && grep -q '^MISSING_RECOVERABLE_BY_SHA 1' "$W/rec2/report.txt"
ok C4-db-file-inconsistency-detected $? "$(grep -E '^(ASSET ktp|ROOT clinical|VERDICT)' "$W/rec2/report.txt" | tr '\n' ' ')"
grep -q '^ROOT clinical-evidence files=43 orphans=3' "$W/rec2/report.txt"; ok C4-files-after-T-are-orphans $? "2 written after T + 1 uncommitted upload"
# reconciliation: the file the database at T still needs is restored by its digest from the content-addressed store
out="$(bash "$B" restore --store "$STORE" --target "$W/restored-fix" --by-sha "$W/rec2/missing-by-sha.txt" --storage "$S")"
cp -a "$W/restored-fix/." "$W/restored/"
out="$(PGDATABASE="$REST" bash "$R" --map "$MAP" --out "$W/rec3" --tree "$W/restored")"; ok C4-reconciled-consistent $? "$(grep VERDICT "$W/rec3/report.txt")"

# --- C5 missing, corrupted (content changed in an immutable root), duplicate references, interrupted upload ---------
cp "$S/clinical-evidence-private/handwritings/2/1/handwriting_p1_1.png" "$W/orig-1.png"
printf 'X' | dd of="$S/clinical-evidence-private/handwritings/2/1/handwriting_p1_1.png" bs=1 seek=10 conv=notrunc status=none
rm -f "$S/clinical-evidence-private/handwritings/1/2/handwriting_p1_2.png"
q "$LIVE" "INSERT INTO consent (branch_id, path) VALUES (2, 'rme-consents/C1/consenter-1.png')"           # a second row, unique asset
q "$LIVE" "INSERT INTO hw (branch_id, path, hash) VALUES (1, 'handwritings/1/77/handwriting_p1_77.png', '$(printf x | sha)')"  # row, no file
out="$(bash "$B" integrity --storage "$S" --roots "$ROOTS" --state "$STATE")"; rc=$?
[ "$rc" = 1 ] && echo "$out" | grep -q '^MODIFIED clinical-evidence ' && echo "$out" | grep -q '^DELETED clinical-evidence '
ok C5-integrity-alerts "$?" "$(echo "$out" | tail -1)"
out="$(PGDATABASE="$LIVE" bash "$R" --map "$MAP" --out "$W/rec4" --storage "$S" --roots "$ROOTS")"; rc=$?
[ "$rc" = 1 ] && grep -q '^ASSET hw .*missing=2 mismatch=1' "$W/rec4/report.txt" && grep -q '^ASSET consent .*duplicate=1' "$W/rec4/report.txt"
ok C5-reconcile-missing-mismatch-duplicate "$?" "$(grep -E '^ASSET (hw|consent)' "$W/rec4/report.txt" | tr '\n' ' ')"
# the backup still holds the original bytes of the corrupted file
mkdir -m 0700 "$W/r5"
out="$(bash "$B" restore --store "$STORE" --target "$W/r5/t" --only <(echo 'clinical-evidence/handwritings/2/1/handwriting_p1_1.png'))"
cmp -s "$W/r5/t/clinical-evidence/handwritings/2/1/handwriting_p1_1.png" "$W/orig-1.png"; ok C5-corrupted-file-recoverable $? "$out"
cp "$W/orig-1.png" "$S/clinical-evidence-private/handwritings/2/1/handwriting_p1_1.png"

# --- C6 an interrupted backup leaves the store valid; the next run completes ---------------------------------------
for i in $(seq 1 400); do put "clinical-evidence-private/rme-clinical-photos/2026/11/bulk-${i}.jpg" 20000; done
before="$(wc -l < "$STORE/CHAIN")"
setsid bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR" > /dev/null 2>&1 & pid=$!
for _ in $(seq 1 200); do [ "$(find "$STORE/objects" -newer "$STORE/CHAIN" -name '*.gpg' 2> /dev/null | wc -l)" -ge 20 ] && break; sleep 0.05; done
kill -TERM -- "-$pid" 2> /dev/null; wait "$pid" 2> /dev/null
for _ in $(seq 1 100); do pgrep -g "$pid" > /dev/null || break; sleep 0.05; done   # the whole run, children included
after="$(wc -l < "$STORE/CHAIN")"; out="$(bash "$B" verify --store "$STORE")"; rc=$?
[ "$before" = "$after" ] && [ "$rc" = 0 ]; ok C6-interrupted-backup-no-snapshot "$?" "chain ${before}->${after}; ${out}"
out="$(bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR")"; rc=$?
[ "$rc" = 0 ] && [ -z "$(find "$STORE" -name '*.tmp')" ]; ok C6-next-run-completes "$?" "$(echo "$out" | grep -o 'files=[0-9]*.*seconds=[0-9.]*')"
out="$(bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR")"
ok C6-incremental-cost 0 "unchanged incremental over $(echo "$out" | grep -o 'files=[0-9]*') files: $(echo "$out" | grep -o 'seconds=[0-9.]*')"

# --- C7 cross-branch: a branch-scoped restore holds only that branch's files ----------------------------------------
out="$(PGDATABASE="$LIVE" bash "$R" --map "$MAP" --out "$W/rec5" --storage "$S" --roots "$ROOTS" --branch 2)"
bash "$B" restore --store "$STORE" --target "$W/branch2" --only "$W/rec5/branch-2.list" > /dev/null
n_out="$(q "$LIVE" "SELECT count(*) FROM hw WHERE branch_id <> 2")"
leak=0; while read -r p; do [ -e "$W/branch2/clinical-evidence/$p" ] && leak=$((leak + 1)); done < <(q "$LIVE" "SELECT path FROM hw WHERE branch_id <> 2")
mine="$(find "$W/branch2" -type f | wc -l)"
[ "$leak" = 0 ] && [ "$mine" -gt 0 ]; ok C7-branch-scoped-restore "$?" "branch 2 files restored=${mine}; other-branch files present=${leak} of ${n_out}"

# --- C8 isolation and access: no restore into the live tree; restored files are private -----------------------------
bash "$B" restore --store "$STORE" --target "$S/restore-here" --storage "$S" > /dev/null 2>&1; [ $? = 2 ]; ok C8-no-restore-into-live $?
[ -z "$(find "$W/restored" "$W/branch2" \( -type f ! -perm 0600 \) -o \( -type d ! -perm 0700 \))" ]; ok C8-restored-tree-private $? "files 0600, directories 0700"

# --- C9 off-host replication (append-only) and tampering -------------------------------------------------------------
out="$(bash "$B" replicate --store "$STORE" --to "$OFF")"; ok C9-replicate $? "$out"
T1="$W/t1"; cp -a "$STORE" "$T1"; o="$(find "$T1/objects" -name '*.gpg' | head -1)"; printf 'X' | dd of="$o" bs=1 seek=50 conv=notrunc status=none
bash "$B" verify --store "$T1" > /dev/null 2>&1; ok C9-tampered-object-detected "$([ $? = 3 ]; echo $?)"
T2="$W/t2"; cp -a "$STORE" "$T2"; rm -f "$(find "$T2/objects" -name '*.gpg' | head -1)"
bash "$B" verify --store "$T2" > /dev/null 2>&1; ok C9-deleted-object-detected "$([ $? = 3 ]; echo $?)"
T3="$W/t3"; cp -a "$STORE" "$T3"; m="$(ls "$T3"/snapshots/*.manifest.gpg | head -1)"; printf 'X' | dd of="$m" bs=1 seek=40 conv=notrunc status=none
bash "$B" verify --store "$T3" > /dev/null 2>&1; ok C9-tampered-manifest-detected "$([ $? = 3 ]; echo $?)"
T4="$W/t4"; cp -a "$STORE" "$T4"; sed -i '1d' "$T4/CHAIN"
bash "$B" verify --store "$T4" --anchor "$ANCHOR1" > /dev/null 2>&1; ok C9-rewritten-history-detected "$([ $? = 3 ]; echo $?)"
bash "$B" replicate --store "$T4" --to "$OFF" > /dev/null 2>&1; ok C9-replication-refuses-diverged-source "$([ $? = 3 ]; echo $?)"
cp -a "$OFF" "$W/off2"; o="$(find "$W/off2/objects" -name '*.gpg' | head -1)"; printf 'X' | dd of="$o" bs=1 seek=50 conv=notrunc status=none
bash "$B" replicate --store "$STORE" --to "$W/off2" > /dev/null 2>&1; ok C9-replication-refuses-altered-copy "$([ $? = 3 ]; echo $?)"
out="$(bash "$B" verify --store "$OFF" --anchor "$ANCHOR1" --deep)"; ok C9-offhost-copy-verifies-deep $? "$out"

# --- C10 retention: daily snapshots kept, intra-day ones pruned, the head and its objects intact --------------------
nsnap="$(wc -l < "$STORE/CHAIN")"; days=0
out="$(bash "$B" prune --store "$STORE" --keep-days 30 --keep-recent-hours 0 --now "$(( $(date -u +%s) + 3600 ))" --apply)"
v="$(bash "$B" verify --store "$STORE" --deep)"; rc=$?
days="$(cut -c1-8 "$STORE/CHAIN" | sort -u | wc -l)"   # one snapshot kept per UTC day (the head is its day's last)
[ "$rc" = 0 ] && echo "$out" | grep -q "kept=${days} dropped=$((nsnap - days))"; ok C10-retention "$?" "$(echo "$out" | tail -1); ${v}"
out="$(bash "$B" restore --store "$STORE" --target "$W/after-prune")"; ok C10-head-restorable-after-prune $? "$out"

echo "TOTAL pass=${P} fail=${F}"
[ "$F" = 0 ]
