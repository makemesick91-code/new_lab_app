#!/usr/bin/env bash
# R6-B13 — backup of the application's clinical file trees (owner decision 2026-11-26 §8).
#
# Content-addressed, encrypted, incremental snapshots. Production activation needs separate owner approval; until then
# this runs only against synthetic files (ClinicalAssetBackupTest) and isolated rehearsals.
#
#   snapshot  --storage <storage/app> --roots <roots.conf> --state <private dir> --store <dir> --recipient <key id>
#   integrity --storage <storage/app> --roots <roots.conf> --state <private dir>
#   verify    --store <dir> [--anchor <CHAIN line>] [--deep]
#   restore   --store <dir> --target <new dir> [--snapshot <id>|latest] [--only <file>] [--by-sha <file>] [--storage <live storage/app>]
#   replicate --store <dir> --to <dir>
#   prune     --store <dir> --keep-days <n> [--keep-recent-hours <h>] [--now <epoch>] [--apply]
#
# Store layout (everything in it is ciphertext or a digest; no path or patient content is readable without the key):
#   objects/<2 hex>/<plain sha256>.gpg   one object per distinct file content (zstd, then gpg to the recipient key)
#   OBJECTSUMS                           "<cipher sha256> <plain sha256> <plain bytes>" per object, append-only
#   snapshots/<id>.manifest.gpg          the encrypted manifest ("<sha256> <bytes> <root>/<relpath>" per file)
#   snapshots/<id>.objects               the sorted plain sha256 list the snapshot references (for prune)
#   CHAIN                                "<id> <manifest plain sha256> <manifest cipher sha256> <objects sha256> <prev line sha256>"
#   PRUNED                               "<id>" per pruned snapshot, append-only
# The private state directory (on the source host, same trust as the files) keeps the plaintext last manifest and a
# stat cache, so incremental runs re-hash only files whose (device, inode, size, mtime) changed.
#
# Exit codes: 0 ok; 1 a finding (integrity or verify); 2 usage or refused; 3 tampering or diverged history.
set -euo pipefail
umask 077
export LC_ALL=C

die() { echo "clinical-asset-backup: $*" >&2; exit 2; }
tamper() { echo "TAMPER: $*" >&2; exit 3; }
sha() { sha256sum | cut -d' ' -f1; }
now_id() { date -u +%Y%m%dT%H%M%S%NZ | sed -E 's/([0-9]{6})[0-9]{3}Z$/\1Z/'; }

CMD="${1:-}"; shift || true
STORAGE="" ROOTS="" STATE="" STORE="" RCPT="" ANCHOR="" DEEP=0 TARGET="" SNAP=latest ONLY="" BYSHA="" TO="" KEEP_DAYS="" KEEP_HOURS=48 APPLY=0 NOW=""
while [ $# -gt 0 ]; do
  case "$1" in
    --storage) STORAGE="${2:?}"; shift 2 ;;
    --roots) ROOTS="${2:?}"; shift 2 ;;
    --state) STATE="${2:?}"; shift 2 ;;
    --store) STORE="${2:?}"; shift 2 ;;
    --recipient) RCPT="${2:?}"; shift 2 ;;
    --anchor) ANCHOR="${2:?}"; shift 2 ;;
    --deep) DEEP=1; shift ;;
    --target) TARGET="${2:?}"; shift 2 ;;
    --snapshot) SNAP="${2:?}"; shift 2 ;;
    --only) ONLY="${2:?}"; shift 2 ;;
    --by-sha) BYSHA="${2:?}"; shift 2 ;;
    --to) TO="${2:?}"; shift 2 ;;
    --keep-days) KEEP_DAYS="${2:?}"; shift 2 ;;
    --keep-recent-hours) KEEP_HOURS="${2:?}"; shift 2 ;;
    --apply) APPLY=1; shift ;;
    --now) NOW="${2:?}"; shift 2 ;;   # prune only: the reference time (epoch seconds), for rehearsals and tests
    *) die "unknown argument: $1" ;;
  esac
done

# roots.conf: "<name> <path under storage/app> <immutable|mutable>"; comments and blank lines ignored.
declare -A ROOT_PATH=() ROOT_CLASS=()
ROOT_NAMES=()
load_roots() {
  [ -f "$ROOTS" ] || die "roots file not found"
  local name rel class extra
  while read -r name rel class extra; do
    case "$name" in '' | '#'*) continue ;; esac
    [[ "$name" =~ ^[a-z][a-z0-9-]{0,40}$ ]] && [[ "$rel" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,200}$ ]] && [[ "$rel" != *..* ]] \
      && [[ "$class" =~ ^(immutable|mutable)$ ]] && [ -z "$extra" ] || die "malformed roots line for '${name}'"
    [ -z "${ROOT_PATH[$name]:-}" ] || die "root '${name}' listed twice"
    ROOT_PATH[$name]="$rel"; ROOT_CLASS[$name]="$class"; ROOT_NAMES+=("$name")
  done < "$ROOTS"
  [ "${#ROOT_NAMES[@]}" -ge 1 ] || die "no roots"
}

lock() { # <dir>: one writer per store and per state directory
  exec {LFD}> "$1/LOCK" || die "cannot open the lock in $1"
  flock -n "$LFD" || die "another run holds $1/LOCK"
}

store_init() {
  mkdir -p -- "$STORE/objects" "$STORE/snapshots"
  touch -- "$STORE/OBJECTSUMS" "$STORE/CHAIN" "$STORE/PRUNED"
}

# A file's content as one consistent copy: hashed and encrypted from the same bytes even if the original changes.
encrypt_object() { # <plain copy> <plain sha256> <bytes>
  local d="$STORE/objects/${2:0:2}" o="$STORE/objects/${2:0:2}/${2}.gpg"
  [ -e "$o" ] && return 0
  mkdir -p -- "$d"
  zstd -q -c -- "$1" | gpg --batch --quiet --yes --trust-model always --compress-algo none -e -r "$RCPT" -o "${o}.tmp"
  sync -- "${o}.tmp"; mv -- "${o}.tmp" "$o"
  printf '%s %s %s\n' "$(sha < "$o")" "$2" "$3" >> "$STORE/OBJECTSUMS"
  NEW_OBJECTS=$((NEW_OBJECTS + 1)); NEW_BYTES=$((NEW_BYTES + $3))
}

cmd_snapshot() {
  [ -n "$STORAGE" ] && [ -n "$ROOTS" ] && [ -n "$STATE" ] && [ -n "$STORE" ] && [ -n "$RCPT" ] || die "snapshot needs --storage --roots --state --store --recipient"
  [ -d "$STORAGE" ] || die "storage directory not found"
  load_roots
  mkdir -p -- "$STATE/tmp" && chmod 0700 -- "$STATE"
  store_init; lock "$STATE"; local sfd="$LFD"; lock "$STORE"
  # an interrupted earlier run leaves only *.tmp files and no manifest: remove them; its snapshot never existed
  find "$STORE/objects" "$STORE/snapshots" -name '*.tmp' -type f -delete
  rm -f -- "$STATE/tmp/"*
  gpg --batch --list-keys -- "$RCPT" > /dev/null 2>&1 || die "recipient key not found"
  local t0 id cache newcache list name rel f st key cached s bytes files=0 total=0 skipped=0 links=0
  t0=$(date +%s.%N); id="$(now_id)"
  cache="$STATE/cache"; [ -f "$cache" ] || : > "$cache"
  newcache="$STATE/tmp/cache"; list="$STATE/tmp/manifest.body"; : > "$newcache"; : > "$list"
  declare -A C=()
  while read -r key s rest; do [ -n "$key" ] && C["$key ${rest}"]="$s"; done < "$cache"
  NEW_OBJECTS=0; NEW_BYTES=0
  for name in "${ROOT_NAMES[@]}"; do
    rel="${ROOT_PATH[$name]}"
    [ -d "$STORAGE/$rel" ] || continue
    [ ! -L "$STORAGE/$rel" ] || die "root ${name} is a symbolic link"
    # a symbolic link is never followed (it could point outside the tree); it is reported
    links=$((links + $(find "$STORAGE/$rel" -xdev -type l | wc -l)))
    while IFS= read -r -d '' f; do
      case "$f" in *$'\n'* | *$'\r'*) skipped=$((skipped + 1)); echo "SKIPPED: a file name with a line break under ${name}" >&2; continue ;; esac
      st="$(stat -c '%d:%i:%s:%.9Y:%.9Z' -- "$STORAGE/$rel/$f" 2> /dev/null)" || continue   # vanished since listing
      cached="${C["$st ${name}/${f}"]:-}"
      if [ -n "$cached" ] && [ -e "$STORE/objects/${cached:0:2}/${cached}.gpg" ]; then
        s="$cached"; bytes="${st#*:*:}"; bytes="${bytes%%:*}"
      else
        cp -- "$STORAGE/$rel/$f" "$STATE/tmp/f" 2> /dev/null || continue
        s="$(sha < "$STATE/tmp/f")"; bytes="$(stat -c %s -- "$STATE/tmp/f")"
        encrypt_object "$STATE/tmp/f" "$s" "$bytes"
        rm -f -- "$STATE/tmp/f"
      fi
      printf '%s %s %s\n' "$st" "$s" "${name}/${f}" >> "$newcache"
      printf '%s %s %s/%s\n' "$s" "$bytes" "$name" "$f" >> "$list"
      files=$((files + 1)); total=$((total + bytes))
    done < <(find "$STORAGE/$rel" -xdev -type f -printf '%P\0' | sort -z)
  done
  # the manifest: header, then the body sorted by path; the previous manifest's digest chains the snapshots
  local prev_line prev_plain man mplain mcipher objs osha
  prev_line="$(tail -n 1 -- "$STORE/CHAIN")"; prev_plain="$(printf '%s' "$prev_line" | awk '{print $2}')"
  man="$STATE/tmp/manifest"
  { echo "# dms-clinical-asset-manifest 1"; echo "# snapshot ${id}"; echo "# prev ${prev_plain:-none}"
    sort -t' ' -k3 -- "$list"; } > "$man"
  mplain="$(sha < "$man")"
  zstd -q -c -- "$man" | gpg --batch --quiet --yes --trust-model always --compress-algo none -e -r "$RCPT" -o "$STORE/snapshots/${id}.manifest.gpg.tmp"
  cut -d' ' -f1 -- "$list" | sort -u > "$STORE/snapshots/${id}.objects.tmp"
  sync -- "$STORE/snapshots/${id}.manifest.gpg.tmp" "$STORE/snapshots/${id}.objects.tmp"
  mcipher="$(sha < "$STORE/snapshots/${id}.manifest.gpg.tmp")"; osha="$(sha < "$STORE/snapshots/${id}.objects.tmp")"
  mv -- "$STORE/snapshots/${id}.objects.tmp" "$STORE/snapshots/${id}.objects"
  mv -- "$STORE/snapshots/${id}.manifest.gpg.tmp" "$STORE/snapshots/${id}.manifest.gpg"
  # the CHAIN line is the commit point: written last, after every object and the manifest are durable
  printf '%s %s %s %s %s\n' "$id" "$mplain" "$mcipher" "$osha" "$(printf '%s' "$prev_line" | sha)" >> "$STORE/CHAIN"
  sync -- "$STORE/CHAIN"
  mv -- "$man" "$STATE/last.manifest"; mv -- "$newcache" "$cache"; rm -f -- "$list"
  objs="$(wc -l < "$STORE/snapshots/${id}.objects")"
  printf 'SNAPSHOT %s files=%s bytes=%s distinct=%s new_objects=%s new_bytes=%s skipped=%s symlinks=%s seconds=%s\n' \
    "$id" "$files" "$total" "$objs" "$NEW_OBJECTS" "$NEW_BYTES" "$skipped" "$links" "$(printf '%.2f' "$(echo "$(date +%s.%N) - $t0" | bc)")"
  exec {sfd}>&-
}

# integrity: re-hash every file (no cache) against the last snapshot. A file whose stat is unchanged but whose content
# differs changed underneath the filesystem's view (corruption or tampering): always an alert. In an immutable root a
# modified or deleted file is an alert too; in a mutable root it is reported.
cmd_integrity() {
  [ -n "$STORAGE" ] && [ -n "$ROOTS" ] && [ -n "$STATE" ] || die "integrity needs --storage --roots --state"
  [ -f "$STATE/last.manifest" ] || die "no snapshot yet"
  load_roots; lock "$STATE"
  declare -A M=() K=() SEEN=()
  local s b p st key rest name rel f cur ok=0 changed=0 modified=0 deleted=0 new=0 alert=0
  while read -r s b p; do case "$s" in '#'*) continue ;; esac; M["$p"]="$s"; done < "$STATE/last.manifest"
  while read -r key s rest; do K["$rest"]="$key"; done < "$STATE/cache"
  for name in "${ROOT_NAMES[@]}"; do
    rel="${ROOT_PATH[$name]}"; [ -d "$STORAGE/$rel" ] || continue
    while IFS= read -r -d '' f; do
      p="${name}/${f}"; SEEN["$p"]=1
      st="$(stat -c '%d:%i:%s:%.9Y:%.9Z' -- "$STORAGE/$rel/$f" 2> /dev/null)" || continue
      cur="$(sha < "$STORAGE/$rel/$f")"
      if [ -z "${M[$p]:-}" ]; then new=$((new + 1))
      elif [ "$cur" = "${M[$p]}" ]; then ok=$((ok + 1))
      elif [ "${K[$p]:-}" = "$st" ]; then changed=$((changed + 1)); alert=1; echo "CHANGED_IN_PLACE ${name} $(printf '%s' "$p" | sha)"
      else modified=$((modified + 1)); [ "${ROOT_CLASS[$name]}" = immutable ] && { alert=1; echo "MODIFIED ${name} $(printf '%s' "$p" | sha)"; }
      fi
    done < <(find "$STORAGE/$rel" -xdev -type f -printf '%P\0')
  done
  for p in "${!M[@]}"; do
    [ -n "${SEEN[$p]:-}" ] && continue
    deleted=$((deleted + 1)); name="${p%%/*}"
    [ "${ROOT_CLASS[$name]:-mutable}" = immutable ] && { alert=1; echo "DELETED ${name} $(printf '%s' "$p" | sha)"; }
  done
  echo "INTEGRITY ok=${ok} changed_in_place=${changed} modified=${modified} deleted=${deleted} new=${new} alert=${alert}"
  [ "$alert" = 0 ]
}

# verify: no private key needed. The CHAIN is hash-linked; every snapshot and object matches its recorded digest.
# --anchor: a CHAIN line recorded elsewhere earlier (off-host, evidence pack) must still be present: a rewritten or
# truncated history is tampering. --deep (private key present): decrypt every live manifest and every object it names.
cmd_verify() {
  [ -n "$STORE" ] && [ -d "$STORE" ] || die "verify needs --store"
  local prev="" id mplain mcipher osha link n=0 bad=0 objs=0 unlisted=0 cs ps bytes o
  declare -A PRUNED=() LISTED=()
  while read -r id; do [ -n "$id" ] && PRUNED[$id]=1; done < "$STORE/PRUNED"
  while read -r id mplain mcipher osha link; do
    n=$((n + 1))
    [ "$link" = "$(printf '%s' "$prev" | sha)" ] || tamper "CHAIN line ${n} does not link to the line before it"
    prev="${id} ${mplain} ${mcipher} ${osha} ${link}"
    if [ -n "${PRUNED[$id]:-}" ]; then continue; fi
    [ -f "$STORE/snapshots/${id}.manifest.gpg" ] || tamper "snapshot ${id} is missing and was never pruned"
    [ "$(sha < "$STORE/snapshots/${id}.manifest.gpg")" = "$mcipher" ] || tamper "snapshot ${id}: the manifest's digest differs"
    [ "$(sha < "$STORE/snapshots/${id}.objects")" = "$osha" ] || tamper "snapshot ${id}: the object list's digest differs"
    if [ "$DEEP" = 1 ]; then
      [ "$(gpg --batch --quiet --decrypt -- "$STORE/snapshots/${id}.manifest.gpg" 2> /dev/null | zstd -q -d -c | sha)" = "$mplain" ] || tamper "snapshot ${id}: the decrypted manifest's digest differs"
    fi
  done < "$STORE/CHAIN"
  if [ -n "$ANCHOR" ]; then grep -qxF -- "$ANCHOR" "$STORE/CHAIN" || tamper "the anchor line is not in CHAIN (history rewritten or truncated)"; fi
  while read -r cs ps bytes; do
    o="$STORE/objects/${ps:0:2}/${ps}.gpg"; LISTED[$ps]=1
    if [ ! -f "$o" ]; then
      # an object may be gone only if no live snapshot needs it (prune)
      grep -qxF -- "$ps" <(cat "$STORE"/snapshots/*.objects 2> /dev/null) && tamper "object ${ps:0:12}… is missing and a live snapshot needs it"
      continue
    fi
    objs=$((objs + 1))
    [ "$(sha < "$o")" = "$cs" ] || tamper "object ${ps:0:12}… does not match its recorded digest"
    if [ "$DEEP" = 1 ]; then
      [ "$(gpg --batch --quiet --decrypt -- "$o" 2> /dev/null | zstd -q -d -c | sha)" = "$ps" ] || tamper "object ${ps:0:12}… decrypts to other content"
    fi
  done < "$STORE/OBJECTSUMS"
  while IFS= read -r o; do o="${o##*/}"; [ -n "${LISTED[${o%.gpg}]:-}" ] || unlisted=$((unlisted + 1)); done < <(find "$STORE/objects" -name '*.gpg' -type f)
  # every object a live snapshot references exists (a deleted object is caught above only if it was listed)
  for o in "$STORE"/snapshots/*.objects; do
    [ -e "$o" ] || continue
    while read -r ps; do [ -f "$STORE/objects/${ps:0:2}/${ps}.gpg" ] || tamper "snapshot $(basename "$o" .objects) needs object ${ps:0:12}…, which is missing"; done < "$o"
  done
  echo "VERIFIED snapshots=${n} pruned=${#PRUNED[@]} objects=${objs} unlisted_objects=${unlisted} head=$(tail -n 1 "$STORE/CHAIN" | cut -d' ' -f1) deep=${DEEP}"
}

# restore into a NEW directory outside the live storage. --only: a list of "<root>/<relpath>" to restore (a branch-
# scoped restore built by clinical-asset-reconcile.sh). --by-sha: "<root>/<relpath> <sha256>" lines restored from the
# object store by content even when the chosen snapshot does not have them (a file deleted after the database's
# recovery point, found in an older snapshot by the digest the database stores).
cmd_restore() {
  [ -n "$STORE" ] && [ -n "$TARGET" ] || die "restore needs --store --target"
  if [ -e "$TARGET" ]; then [ -d "$TARGET" ] && [ -z "$(ls -A -- "$TARGET")" ] || die "the target must be a new or empty directory"; fi
  mkdir -p -m 0700 -- "$TARGET"
  local tgt; tgt="$(cd "$TARGET" && pwd -P)"
  if [ -n "$STORAGE" ]; then
    local live; live="$(cd "$STORAGE" && pwd -P)"
    case "$tgt/" in "$live/"*) die "the target is inside the live storage: restores go to an isolated directory" ;; esac
  fi
  local line id mplain
  if [ "$SNAP" = latest ]; then line="$(tail -n 1 -- "$STORE/CHAIN")"; else line="$(awk -v s="$SNAP" '($1 "") == s' "$STORE/CHAIN" | tail -n 1)"; fi
  [ -n "$line" ] || die "snapshot not found"
  id="${line%% *}"; mplain="$(printf '%s' "$line" | cut -d' ' -f2)"
  [ -f "$STORE/snapshots/${id}.manifest.gpg" ] || die "snapshot ${id} was pruned"
  local man="$tgt/.manifest.$$"
  gpg --batch --quiet --decrypt -- "$STORE/snapshots/${id}.manifest.gpg" 2> /dev/null | zstd -q -d -c > "$man"
  [ "$(sha < "$man")" = "$mplain" ] || { rm -f -- "$man"; tamper "the decrypted manifest of ${id} does not match CHAIN"; }
  declare -A WANT=()
  if [ -n "$ONLY" ]; then while IFS= read -r p; do [ -n "$p" ] && WANT["$p"]=1; done < "$ONLY"; fi
  local s b p n=0 bytes=0 t0; t0=$(date +%s.%N)
  put() { # <sha256> <root/relpath>
    local o="$STORE/objects/${1:0:2}/${1}.gpg" dst="$tgt/$2"
    [[ "/$2/" != */../* ]] && [[ "/$2/" != */./* ]] && [[ "$2" != /* ]] || tamper "a manifest path escapes the target"
    [ -f "$o" ] || { echo "MISSING_OBJECT $(printf '%s' "$2" | sha)" >&2; return 1; }
    mkdir -p -m 0700 -- "$(dirname -- "$dst")"
    gpg --batch --quiet --decrypt -- "$o" 2> /dev/null | zstd -q -d -c > "${dst}.part"
    [ "$(sha < "${dst}.part")" = "$1" ] || { rm -f -- "${dst}.part"; tamper "object ${1:0:12}… decrypts to other content"; }
    chmod 0600 -- "${dst}.part"; mv -- "${dst}.part" "$dst"
  }
  local failed=0 skip_manifest=0
  # --by-sha alone restores only those files; with --only, both lists
  [ -n "$BYSHA" ] && [ -z "$ONLY" ] && skip_manifest=1
  while read -r s b p; do
    case "$s" in '#'*) continue ;; esac
    [ "$skip_manifest" = 1 ] && continue
    [ -n "$ONLY" ] && [ -z "${WANT[$p]:-}" ] && continue
    put "$s" "$p" || failed=$((failed + 1)); n=$((n + 1)); bytes=$((bytes + b))
  done < "$man"
  rm -f -- "$man"
  local bysha=0
  if [ -n "$BYSHA" ]; then
    while read -r p s; do
      [ -n "$p" ] || continue
      [ -e "$tgt/$p" ] && continue
      [[ "$s" =~ ^[0-9a-f]{64}$ ]] || die "a --by-sha line has no sha256"
      put "$s" "$p" && bysha=$((bysha + 1)) || failed=$((failed + 1))
    done < "$BYSHA"
  fi
  echo "RESTORED snapshot=${id} files=${n} bytes=${bytes} by_sha=${bysha} failed=${failed} seconds=$(printf '%.2f' "$(echo "$(date +%s.%N) - $t0" | bc)")"
  [ "$failed" = 0 ]
}

# replicate: append-only. The destination's history must be a prefix of the source's; an existing object or snapshot
# is never overwritten; anything that differs is refused as tampering. In production the off-host side runs this as a
# PULL, so a compromised source cannot delete or rewrite what was already copied.
cmd_replicate() {
  [ -n "$STORE" ] && [ -n "$TO" ] || die "replicate needs --store --to"
  local src="$STORE"; STORE="$TO"; store_init; lock "$TO"; STORE="$src"
  local f n
  for f in CHAIN OBJECTSUMS PRUNED; do
    n="$(stat -c %s -- "$TO/$f")"
    [ "$n" -le "$(stat -c %s -- "$STORE/$f")" ] && cmp -s -n "$n" -- "$TO/$f" "$STORE/$f" || tamper "${f} at the destination is not a prefix of the source's (diverged history)"
  done
  local copied=0 rel
  while IFS= read -r -d '' f; do
    rel="${f#"$STORE"/}"
    if [ -e "$TO/$rel" ]; then cmp -s -- "$f" "$TO/$rel" || tamper "${rel} differs at the destination"; continue; fi
    mkdir -p -- "$(dirname -- "$TO/$rel")"; cp -- "$f" "$TO/${rel}.tmp"; sync -- "$TO/${rel}.tmp"; mv -- "$TO/${rel}.tmp" "$TO/$rel"; copied=$((copied + 1))
  done < <(cd "$STORE" && find objects snapshots -type f ! -name '*.tmp' -print0 | sed -z "s|^|$STORE/|")
  for f in OBJECTSUMS PRUNED CHAIN; do cp -- "$STORE/$f" "$TO/${f}.tmp"; sync -- "$TO/${f}.tmp"; mv -- "$TO/${f}.tmp" "$TO/$f"; done
  echo "REPLICATED files=${copied} head=$(tail -n 1 "$TO/CHAIN" | cut -d' ' -f1)"
  STORE="$TO" DEEP=0 ANCHOR="" cmd_verify > /dev/null
}

# prune: keeps every snapshot of the last --keep-recent-hours, plus the last snapshot of each day for --keep-days days.
# Backup retention only: the clinical records themselves (the live files) are never touched. Dry run unless --apply.
cmd_prune() {
  [ -n "$STORE" ] && [[ "$KEEP_DAYS" =~ ^[0-9]{1,5}$ ]] && [[ "$KEEP_HOURS" =~ ^[0-9]{1,6}$ ]] || die "prune needs --store --keep-days"
  lock "$STORE"
  local nowe ids id day e keep head drop=0 kept=0 objs=0
  nowe="${NOW:-$(date -u +%s)}"; [[ "$nowe" =~ ^[0-9]{9,11}$ ]] || die "--now must be epoch seconds"; head="$(tail -n 1 "$STORE/CHAIN" | cut -d' ' -f1)"
  declare -A PR=() LASTOFDAY=() KEEP=()
  while read -r id; do [ -n "$id" ] && PR[$id]=1; done < "$STORE/PRUNED"
  mapfile -t ids < <(cut -d' ' -f1 "$STORE/CHAIN")
  for id in "${ids[@]}"; do day="${id:0:8}"; LASTOFDAY[$day]="$id"; done
  for id in "${ids[@]}"; do
    [ -n "${PR[$id]:-}" ] && continue
    e=$(date -u -d "${id:0:4}-${id:4:2}-${id:6:2} ${id:9:2}:${id:11:2}:${id:13:2}" +%s)
    keep=0
    [ "$id" = "$head" ] && keep=1
    [ $((nowe - e)) -le $((KEEP_HOURS * 3600)) ] && keep=1
    [ "${LASTOFDAY[${id:0:8}]}" = "$id" ] && [ $((nowe - e)) -le $((KEEP_DAYS * 86400)) ] && keep=1
    if [ "$keep" = 1 ]; then KEEP[$id]=1; kept=$((kept + 1)); else drop=$((drop + 1)); echo "PRUNE ${id}"; fi
  done
  if [ "$APPLY" = 1 ]; then
    for id in "${ids[@]}"; do
      [ -n "${PR[$id]:-}" ] || [ -n "${KEEP[$id]:-}" ] && continue
      echo "$id" >> "$STORE/PRUNED"; sync -- "$STORE/PRUNED"
      rm -f -- "$STORE/snapshots/${id}.manifest.gpg" "$STORE/snapshots/${id}.objects"
    done
    declare -A LIVE=()
    for e in "$STORE"/snapshots/*.objects; do [ -e "$e" ] && while read -r id; do LIVE[$id]=1; done < "$e"; done
    while IFS= read -r -d '' e; do id="$(basename "$e" .gpg)"; [ -n "${LIVE[$id]:-}" ] || { rm -f -- "$e"; objs=$((objs + 1)); }; done < <(find "$STORE/objects" -name '*.gpg' -type f -print0)
  fi
  echo "PRUNE kept=${kept} dropped=${drop} objects_removed=${objs} applied=${APPLY}"
}

case "$CMD" in
  snapshot) cmd_snapshot ;;
  integrity) cmd_integrity ;;
  verify) cmd_verify ;;
  restore) cmd_restore ;;
  replicate) cmd_replicate ;;
  prune) cmd_prune ;;
  *) die "usage: clinical-asset-backup.sh snapshot|integrity|verify|restore|replicate|prune …" ;;
esac
