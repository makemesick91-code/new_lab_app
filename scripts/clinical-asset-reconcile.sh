#!/usr/bin/env bash
# R6-B13 — reconcile the database's clinical file references with a file tree (owner decision 2026-11-26 §8).
#
# Read-only on both sides. For every reference in the asset map (deploy/clinical-assets/asset-map.tsv) it checks that
# the file exists and, where the database stores a sha256, that the content matches; files no row references are
# orphans. Used after a restore (a restored database and a restored tree, both isolated) and as a periodic check.
#
#   clinical-asset-reconcile.sh --map <asset-map.tsv> --out <new private dir> (--tree <restored dir> | --storage <storage/app> --roots <roots.conf>) [--branch <id>]
#
# The database is reached through the libpq environment (PGHOST, PGPORT, PGUSER, PGDATABASE, …) in a read-only
# transaction. Evidence files name no path and no patient content: a path appears only as its sha256. Two operational
# files carry relative paths and stay in the private output directory: missing-by-sha.txt (input to a restore by
# content) and branch-<id>.list (input to a branch-scoped restore).
# Verdict: CONSISTENT when no durable reference is missing, no content differs, and no rows disagree on a digest.
# Exit codes: 0 CONSISTENT; 1 INCONSISTENT; 2 usage or refused.
set -euo pipefail
umask 077
export LC_ALL=C

die() { echo "clinical-asset-reconcile: $*" >&2; exit 2; }
sha() { sha256sum | cut -d' ' -f1; }
MAP="" OUT="" TREE="" STORAGE="" ROOTS="" BRANCH=""
while [ $# -gt 0 ]; do
  case "$1" in
    --map) MAP="${2:?}"; shift 2 ;;
    --out) OUT="${2:?}"; shift 2 ;;
    --tree) TREE="${2:?}"; shift 2 ;;
    --storage) STORAGE="${2:?}"; shift 2 ;;
    --roots) ROOTS="${2:?}"; shift 2 ;;
    --branch) BRANCH="${2:?}"; shift 2 ;;
    *) die "unknown argument: $1" ;;
  esac
done
[ -f "$MAP" ] && [ -n "$OUT" ] || die "usage: --map <file> --out <dir> (--tree <dir> | --storage <dir> --roots <file>) [--branch <id>]"
[ -z "$BRANCH" ] || [[ "$BRANCH" =~ ^[0-9]{1,12}$ ]] || die "--branch must be a numeric id"
[ ! -e "$OUT" ] || die "the output directory must not exist yet"
mkdir -p -m 0700 -- "$OUT"

# disk name (as rows and config/filesystems.php name it) -> root name (roots.conf, and the restored tree's top level)
declare -A DISK_ROOT=([clinical_evidence]=clinical-evidence [legacy_rme_private]=legacy-rme [legacy_odontogram_private]=legacy-odontogram
  [legacy_mass_upload_private]=legacy-mass-upload [rme_refund_settlement_evidence]=refund-evidence [local]=local-private [public]=public-legacy-clinical)
declare -A ROOT_DIR=()
if [ -n "$TREE" ]; then
  [ -d "$TREE" ] || die "tree not found"
  for r in "${DISK_ROOT[@]}" evidence-migration; do ROOT_DIR[$r]="$TREE/$r"; done
else
  [ -d "$STORAGE" ] && [ -f "$ROOTS" ] || die "--storage and --roots are needed without --tree"
  while read -r name rel _; do case "$name" in '' | '#'*) continue ;; esac; ROOT_DIR[$name]="$STORAGE/$rel"; done < "$ROOTS"
fi

declare -A REF=() FILESHA=() SEEN_SHA=() SEEN_N=() STATUS_N=()
inconsistent=0
: > "$OUT/files.txt"; : > "$OUT/missing-by-sha.txt"; [ -z "$BRANCH" ] || : > "$OUT/branch-${BRANCH}.list"
filesha() { # <absolute path> -> sha256, computed once per file
  [ -n "${FILESHA[$1]:-}" ] || FILESHA[$1]="$(sha < "$1")"
  printf '%s' "${FILESHA[$1]}"
}
count() { STATUS_N["$1 $2"]=$(( ${STATUS_N["$1 $2"]:-0} + 1 )); }

{ printf 'RECONCILE map=%s at=%s\n' "$(sha < "$MAP")" "$(date -u +%FT%TZ)"; } > "$OUT/report.txt"
assets=()
while IFS=$'\t' read -r asset class unique fallback sql; do
  case "$asset" in '' | '#'*) continue ;; esac
  [[ "$asset" =~ ^[a-z_]{1,48}$ ]] && [[ "$class" =~ ^(durable|transient)$ ]] && [[ "$unique" =~ ^[01]$ ]] || die "malformed map line for ${asset}"
  [[ "$sql" =~ ^SELECT\  ]] || die "map line ${asset}: not a SELECT"
  assets+=("$asset")
  rows="$(PGOPTIONS='-c default_transaction_read_only=on' psql -X -q -v ON_ERROR_STOP=1 -At -F $'\x1f' -c "$sql")" || die "the query for ${asset} failed"
  declare -A KEYS=()
  while IFS=$'\x1f' read -r disk path dsha branch; do
    [ -n "$path" ] || continue
    count "$asset" rows
    root="${DISK_ROOT[$disk]:-}"
    if [ -z "$root" ]; then count "$asset" unknown_disk; inconsistent=1; printf '%s %s UNKNOWN_DISK %s\n' "$asset" "$(printf '%s' "$path" | sha)" "${branch:--}" >> "$OUT/files.txt"; continue; fi
    if [[ "/$path/" == */../* ]] || [[ "/$path/" == */./* ]] || [[ "$path" == /* ]] || [[ "$path" == *$'\n'* ]]; then
      count "$asset" bad_path; inconsistent=1; printf '%s %s BAD_PATH %s\n' "$asset" "$(printf '%s' "$path" | sha)" "${branch:--}" >> "$OUT/files.txt"; continue
    fi
    key="${root}/${path}"
    [ -z "$dsha" ] || [[ "$dsha" =~ ^[0-9a-f]{64}$ ]] || { count "$asset" bad_digest; inconsistent=1; dsha=""; }
    if [ -n "${KEYS[$key]:-}" ]; then
      # rows sharing a file: allowed where the map says so, never with different digests
      [ "$unique" = 1 ] && { count "$asset" duplicate; inconsistent=1; printf '%s %s DUPLICATE %s\n' "$asset" "$(printf '%s' "$key" | sha)" "${branch:--}" >> "$OUT/files.txt"; }
      if [ -n "$dsha" ] && [ -n "${SEEN_SHA[$asset $key]:-}" ] && [ "$dsha" != "${SEEN_SHA[$asset $key]}" ]; then
        count "$asset" conflict; inconsistent=1; printf '%s %s CONFLICT %s\n' "$asset" "$(printf '%s' "$key" | sha)" "${branch:--}" >> "$OUT/files.txt"
      fi
      [ -z "$dsha" ] || SEEN_SHA["$asset $key"]="$dsha"
      [ -z "$BRANCH" ] || [ "${branch:-}" != "$BRANCH" ] || printf '%s\n' "$key" >> "$OUT/branch-${BRANCH}.list"
      continue
    fi
    KEYS[$key]=1; [ -z "$dsha" ] || SEEN_SHA["$asset $key"]="$dsha"
    file="${ROOT_DIR[$root]:-/nonexistent}/${path}"; where="$key"; status=""
    if [ ! -f "$file" ] || [ -L "$file" ]; then
      for fd in ${fallback//,/ }; do
        [ "$fd" = - ] && continue
        fr="${DISK_ROOT[$fd]:-}"; [ -n "$fr" ] || continue
        if [ -f "${ROOT_DIR[$fr]:-/nonexistent}/${path}" ] && [ ! -L "${ROOT_DIR[$fr]}/${path}" ]; then file="${ROOT_DIR[$fr]}/${path}"; where="${fr}/${path}"; status=FALLBACK; break; fi
      done
    fi
    if [ ! -f "$file" ] || [ -L "$file" ]; then
      if [ "$class" = transient ]; then status=ABSENT_TRANSIENT
      else
        status=MISSING; inconsistent=1
        [ -z "$dsha" ] || printf '%s %s\n' "$key" "$dsha" >> "$OUT/missing-by-sha.txt"
      fi
    else
      REF[$where]=1
      if [ -n "$dsha" ]; then
        if [ "$(filesha "$file")" = "$dsha" ]; then status="${status:+OK_FALLBACK}"; status="${status:-OK}"; else status=MISMATCH; inconsistent=1; fi
      else
        status="${status:+OK_FALLBACK_UNHASHED}"; status="${status:-OK_UNHASHED}"
      fi
    fi
    count "$asset" "$status"
    printf '%s %s %s %s\n' "$asset" "$(printf '%s' "$key" | sha)" "$status" "${branch:--}" >> "$OUT/files.txt"
    if [ -n "$BRANCH" ] && [ "${branch:-}" = "$BRANCH" ] && [[ "$status" == OK* ]]; then printf '%s\n' "$where" >> "$OUT/branch-${BRANCH}.list"; fi
    [ -n "${branch:-}" ] || count "$asset" unscoped
  done <<< "$rows"
  unset KEYS
done < "$MAP"

# orphans: files no reference points at (a replaced page's old file, an upload whose transaction did not commit, a
# file written after the database's recovery point). Reported, never deleted, and not by themselves inconsistent.
: > "$OUT/orphans.txt"
declare -A ORPH=() ROOTFILES=()
for root in $(printf '%s\n' "${!ROOT_DIR[@]}" | sort); do
  d="${ROOT_DIR[$root]}"; [ -d "$d" ] || continue
  while IFS= read -r -d '' f; do
    ROOTFILES[$root]=$(( ${ROOTFILES[$root]:-0} + 1 ))
    [ -n "${REF["${root}/${f}"]:-}" ] && continue
    ORPH[$root]=$(( ${ORPH[$root]:-0} + 1 ))
    printf '%s %s %s\n' "$root" "$(printf '%s' "${root}/${f}" | sha)" "$(sha < "$d/$f")" >> "$OUT/orphans.txt"
  done < <(find "$d" -xdev -type f -printf '%P\0' | sort -z)
done

{
  for a in "${assets[@]}"; do
    line="ASSET ${a}"
    for s in rows OK OK_UNHASHED OK_FALLBACK OK_FALLBACK_UNHASHED MISSING ABSENT_TRANSIENT MISMATCH duplicate conflict unknown_disk bad_path bad_digest unscoped; do
      v="${STATUS_N["$a $s"]:-0}"; [ "$v" = 0 ] && [ "$s" != rows ] && continue
      line="${line} ${s,,}=${v}"
    done
    echo "$line"
  done
  for root in $(printf '%s\n' "${!ROOTFILES[@]}" | sort); do echo "ROOT ${root} files=${ROOTFILES[$root]} orphans=${ORPH[$root]:-0}"; done
  echo "MISSING_RECOVERABLE_BY_SHA $(wc -l < "$OUT/missing-by-sha.txt")"
  [ -z "$BRANCH" ] || echo "BRANCH ${BRANCH} files=$(sort -u "$OUT/branch-${BRANCH}.list" | wc -l)"
  if [ "$inconsistent" = 0 ]; then echo "VERDICT CONSISTENT"; else echo "VERDICT INCONSISTENT"; fi
} >> "$OUT/report.txt"
[ -z "$BRANCH" ] || { sort -u -o "$OUT/branch-${BRANCH}.list" "$OUT/branch-${BRANCH}.list"; }
cat "$OUT/report.txt"
[ "$inconsistent" = 0 ]
