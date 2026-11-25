# shellcheck shell=bash disable=SC2034  # DMS_ARCHIVE_* results are read by the scripts that source this library
#
# R6-B6 isolated restore drill — the STRUCTURED backup format (review findings PR-5, PR-6, PR-8, PR-3).
#
# The drill accepts only a pg_dump 16 CUSTOM-format archive (-Fc). Its table of contents (`pg_restore -l`) is a
# structured list of typed entries with owners; its data section is emitted by pg_restore as framed COPY blocks with
# COPY text escaping (one line per row). Every fact the host needs is read from that structure, never from heuristics
# over plain SQL text:
#   - the TOC must use only entry types the application schema produces (fail closed), only allowed schemas, owners
#     from the signed manifest (D-Q6-a), plain identifiers for tables, no extension outside the allowlist (PR-8);
#   - the expected row count of EVERY table comes from the data section, counted by a strict state machine, and is
#     compared per table with what the restore produced (PR-5), through one canonical digest.
# pg_restore parses the untrusted archive only inside a hardened, network-less unit (dms_archive_inspect).

if [ -n "${DMS_RESTORE_ARCHIVE_LOADED:-}" ]; then
  return 0
fi
DMS_RESTORE_ARCHIVE_LOADED=1

# Entry types the application schema produces (2026-11-25, 238 migrations). Anything else is refused; widening this
# list is a reviewed code change, never configuration.
# Multi-word types come first: they are matched as prefixes of the listing line.
DMS_ARCHIVE_ALLOWED_TYPES=("SEQUENCE OWNED BY" "SEQUENCE SET" "FK CONSTRAINT" "TABLE DATA" ACL COMMENT CONSTRAINT DEFAULT FUNCTION INDEX SEQUENCE TABLE TRIGGER EXTENSION)
# P4P-7: pg_dump types that START with an allowed word are refused by their full name, never read as the shorter type.
DMS_ARCHIVE_REFUSED_COMPOUND_TYPES=("TABLE ATTACH" "INDEX ATTACH" "DEFAULT ACL")
DMS_ARCHIVE_ALLOWED_SCHEMAS="public"
DMS_ARCHIVE_MAX_TOC_LINES=50000
DMS_ARCHIVE_MAX_TABLES=2000
# P4P-2: the inspection unit refuses (never truncates) a TOC or a count list above these sizes.
DMS_ARCHIVE_TOC_MAX_BYTES=8388608
DMS_ARCHIVE_COUNTS_MAX_BYTES=4194304
declare -ga DMS_ARCHIVE_DATA_TABLES=()
declare -gA DMS_ARCHIVE_OWNERS=()
# P4P-1: the catalog shape the archive creates, "functions:tables:indexes:constraints:fk_constraints:triggers:sequences";
# the guest reports the same shape from the restored catalog, and GO needs them equal.
DMS_ARCHIVE_SHAPE=""
# The extensions the archive creates (space list, sorted), each already checked against the allowlist.
DMS_ARCHIVE_EXTENSIONS=""
_dms_archive_fail() { echo "archive: $*"; return 1; }

# dms_archive_toc_validate <pg_restore -l output> <allowed owner roles (space-separated)> <extension allowlist (space-separated, may be empty)>
# Fills DMS_ARCHIVE_DATA_TABLES (schema.table, TOC order), DMS_ARCHIVE_OWNERS and DMS_ARCHIVE_EXTENSIONS. Returns 1
# with the reason.
dms_archive_toc_validate() {
  local toc="${1:?toc}" owners=" ${2-} " exts=" ${3-} " line n=0 rest type schema tagowner tag owner t
  local -A tables=() datas=() shape=() extset=()
  DMS_ARCHIVE_DATA_TABLES=()
  DMS_ARCHIVE_OWNERS=()
  DMS_ARCHIVE_SHAPE=""
  DMS_ARCHIVE_EXTENSIONS=""
  local fmt_ok=0 ver_ok=0 dumpver_ok=0
  while IFS= read -r line || [ -n "$line" ]; do
    n=$((n + 1))
    [ "$n" -le "$DMS_ARCHIVE_MAX_TOC_LINES" ] || { _dms_archive_fail "the TOC has more than ${DMS_ARCHIVE_MAX_TOC_LINES} lines"; return 1; }
    case "$line" in
      '') continue ;;
      ';     Format: CUSTOM') fmt_ok=1; continue ;;
      ';     Dumped from database version: 16.'*) ver_ok=1; continue ;;
      ';     Dumped by pg_dump version: 16.'*) dumpver_ok=1; continue ;;
      ';'*) [[ "$line" =~ ^[[:print:]]*$ ]] || { _dms_archive_fail "a TOC comment line is not printable"; return 1; }; continue ;;
    esac
    [[ "$line" =~ ^[0-9]{1,9}\;\ [0-9]{1,10}\ [0-9]{1,10}\ (.+)$ ]] || { _dms_archive_fail "TOC line ${n} is malformed (a name with a control character splits lines)"; return 1; }
    rest="${BASH_REMATCH[1]}"
    [[ "$rest" =~ ^[[:print:]]+$ ]] || { _dms_archive_fail "TOC line ${n} is not printable"; return 1; }
    for t in "${DMS_ARCHIVE_REFUSED_COMPOUND_TYPES[@]}"; do
      [ "${rest#"$t "}" = "$rest" ] || { _dms_archive_fail "TOC line ${n} has an entry type outside the allowlist: ${t}"; return 1; }
    done
    type=""
    for t in "${DMS_ARCHIVE_ALLOWED_TYPES[@]}"; do
      if [ "${rest#"$t "}" != "$rest" ]; then type="$t"; break; fi
    done
    [ -n "$type" ] || { _dms_archive_fail "TOC line ${n} has an entry type outside the allowlist: ${rest%% *}"; return 1; }
    rest="${rest#"$type "}"
    schema="${rest%% *}"
    tagowner="${rest#* }"
    [ "$tagowner" != "$rest" ] || { _dms_archive_fail "TOC line ${n} has no tag and owner"; return 1; }
    owner="${tagowner##* }"
    tag="${tagowner% *}"
    [ "$tag" != "$tagowner" ] || { _dms_archive_fail "TOC line ${n} has no owner"; return 1; }
    if [ "$schema" != "-" ] && [[ " ${DMS_ARCHIVE_ALLOWED_SCHEMAS} " != *" ${schema} "* ]]; then
      _dms_archive_fail "TOC line ${n} uses schema '${schema}' outside the allowlist"
      return 1
    fi
    # pg_restore lists an extension, and a comment on it, with an empty owner.
    if [ -z "$owner" ] && { [ "$type" = EXTENSION ] || { [ "$type" = COMMENT ] && [ "${tag#EXTENSION }" != "$tag" ]; }; }; then
      :
    elif ! [[ "$owner" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]; then
      _dms_archive_fail "TOC line ${n} has an owner that is not a plain role name"
      return 1
    elif [ "$owner" = pg_database_owner ]; then
      # P4P-3: pg_database_owner resolves to whoever owns the TARGET database, so it would bypass D-Q6-a and P5. It is
      # accepted only where PostgreSQL itself puts it: the ACL of, or a comment on, the public schema.
      { { [ "$type" = ACL ] || [ "$type" = COMMENT ]; } && [ "$schema" = "-" ] && [ "$tag" = "SCHEMA public" ]; } \
        || { _dms_archive_fail "TOC line ${n} makes pg_database_owner the owner of a ${type}; only the public schema's ACL or comment may name it (P4P-3)"; return 1; }
    else
      [[ "$owners" == *" ${owner} "* ]] || { _dms_archive_fail "the archive makes '${owner}' an owner and the manifest does not list it (D-Q6-a)"; return 1; }
      DMS_ARCHIVE_OWNERS[$owner]=1
    fi
    case "$type" in
      TABLE | "TABLE DATA" | SEQUENCE | "SEQUENCE SET" | "SEQUENCE OWNED BY")
        [[ "$tag" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || { _dms_archive_fail "TOC line ${n}: ${type} name '${tag}' is not a plain lowercase identifier"; return 1; }
        [ "$schema" != "-" ] || { _dms_archive_fail "TOC line ${n}: ${type} without a schema"; return 1; } ;;
      EXTENSION)
        [[ "$tag" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] && [[ "$exts" == *" ${tag} "* ]] || { _dms_archive_fail "the archive creates extension '${tag}', which is not in the allowlist (PR-8)"; return 1; }
        # Exactly as pg_dump lists an extension (no schema column, no owner): the guest drops only that form before the
        # restore, so any other form would run its SQL (R6-B8 review INFO-4).
        [ "$schema" = "-" ] && [ -z "$owner" ] || { _dms_archive_fail "TOC line ${n}: extension '${tag}' is not listed the way pg_dump lists one (schema -, no owner)"; return 1; }
        extset[$tag]=1 ;;
    esac
    shape[$type]=$(( ${shape[$type]:-0} + 1 ))
    case "$type" in
      TABLE) [ -z "${tables[$schema.$tag]:-}" ] || { _dms_archive_fail "table ${schema}.${tag} appears twice"; return 1; }; tables[$schema.$tag]=1 ;;
      "TABLE DATA")
        [ -z "${datas[$schema.$tag]:-}" ] || { _dms_archive_fail "data for ${schema}.${tag} appears twice"; return 1; }
        datas[$schema.$tag]=1
        DMS_ARCHIVE_DATA_TABLES+=("${schema}.${tag}") ;;
    esac
  done < "$toc"
  [ "$fmt_ok" = 1 ] || { _dms_archive_fail "not a custom-format archive"; return 1; }
  [ "$ver_ok" = 1 ] && [ "$dumpver_ok" = 1 ] || { _dms_archive_fail "not dumped by pg_dump 16 from PostgreSQL 16"; return 1; }
  [ "${#tables[@]}" -ge 1 ] && [ "${#tables[@]}" -le "$DMS_ARCHIVE_MAX_TABLES" ] || { _dms_archive_fail "the archive has ${#tables[@]} tables"; return 1; }
  for t in "${!tables[@]}"; do [ -n "${datas[$t]:-}" ] || { _dms_archive_fail "table ${t} has no data entry"; return 1; }; done
  for t in "${!datas[@]}"; do [ -n "${tables[$t]:-}" ] || { _dms_archive_fail "data entry ${t} has no table"; return 1; }; done
  DMS_ARCHIVE_EXTENSIONS="$(for t in "${!extset[@]}"; do printf '%s\n' "$t"; done | LC_ALL=C sort | paste -sd' ' -)"
  DMS_ARCHIVE_SHAPE="${shape[FUNCTION]:-0}:${shape[TABLE]:-0}:${shape[INDEX]:-0}:${shape[CONSTRAINT]:-0}:${shape["FK CONSTRAINT"]:-0}:${shape[TRIGGER]:-0}:${shape[SEQUENCE]:-0}"
  return 0
}

# dms_archive_verify_copy <staged copy> <expected sha256>
# P4V-2: the drill's integrity check for the STRUCTURED format: the staged copy's digest equals the trusted one and the
# copy starts with the custom-format magic "PGDMP" (pg_restore -l validates the rest of the header in the sandbox).
# A plain-SQL dump, or anything else, is refused here, before any parser sees it.
dms_archive_verify_copy() {
  local staged="${1:-}" expected="${2:-}" actual magic
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || { _dms_archive_fail "the expected digest is not a SHA-256 hex digest"; return 1; }
  actual="$(sha256sum < "$staged" | cut -d' ' -f1)"
  [ "$actual" = "$expected" ] || { _dms_archive_fail "DIGEST MISMATCH: the backup is ${actual}, the trusted evidence says ${expected}; the backup changed after it was written"; return 1; }
  magic="$(head -c 5 -- "$staged")"
  [ "$magic" = PGDMP ] || { _dms_archive_fail "the backup matches its digest but is not a pg_dump custom-format archive (no PGDMP header); a custom-format archive is required (PR-6)"; return 1; }
  echo "RESTORE_INTEGRITY=VERIFIED sha256=${actual} format=custom"
}

# dms_archive_count_program
# The awk program that validates and counts `pg_restore --section=data -f -` output (run inside the inspection unit).
# Outside COPY blocks only pg_restore's own framing is accepted; inside a block every line up to `\.` is a row and must
# have exactly as many tab-separated fields as the COPY column list. Prints "schema.table<TAB>rows" per block, or
# "ERROR <reason>" and exits 1.
dms_archive_count_program() {
  cat << 'AWK'
BEGIN { inblk = 0; started = 0; ended = 0; n = 0; failed = 0; total = 0 }
function fail(why) { print "ERROR " why; failed = 1; exit 1 }
# P4P-4: count the columns of a COPY column list outside double quotes ("a, b", "total(idr)" and "x""y" are one each).
function ncols(s,    i, ch, q, k) {
  q = 0; k = 1
  for (i = 1; i <= length(s); i++) { ch = substr(s, i, 1); if (ch == "\"") q = !q; else if (ch == "," && !q) k++ }
  return q ? -1 : k
}
inblk {
  if ($0 == "\\.") { print tbl "\t" rows; inblk = 0; next }
  # A row of one empty text value is an empty line: one field, not zero (P4P-4).
  nf = ($0 == "") ? 1 : split($0, f, "\t")
  if (nf != cols) fail("row field count differs from the COPY column list in " tbl)
  rows++; total += length($0) + 1; next
}
{
  n++
  if (!started) {
    if ($0 == "" || $0 ~ /^--/) next
    if ($0 ~ /^\\restrict [A-Za-z0-9]+$/) { started = 1; next }
    fail("data stream does not open with \\restrict")
  }
  if (ended) { if ($0 == "") next; fail("content after \\unrestrict") }
  if ($0 ~ /^\\unrestrict [A-Za-z0-9]+$/) { ended = 1; next }
  if ($0 == "" || $0 ~ /^--/) next
  if ($0 ~ /^SET [a-z_]+ = [^;]*;$/) next
  if ($0 ~ /^SELECT pg_catalog\.set_config\('search_path', '', false\);$/) next
  if ($0 ~ /^SELECT pg_catalog\.setval\('[a-z0-9_.]+', [0-9]+, (true|false)\);$/) next
  if (match($0, /^COPY [a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]* \(.+\) FROM stdin;$/)) {
    tbl = $2; c = $0; sub(/^COPY [^ ]+ \(/, "", c); sub(/\) FROM stdin;$/, "", c)
    cols = ncols(c); if (cols < 1) fail("unbalanced quotes in the COPY column list of " tbl)
    rows = 0; inblk = 1; next
  }
  fail("unexpected line in the data section")
}
END {
  if (failed) exit 1
  if (inblk) fail("unterminated COPY block in " tbl)
  if (!ended) fail("data stream does not close with \\unrestrict")
  # Phase 3: the UNCOMPRESSED size of every row, for the working-space limit (written only when asked for).
  if (bytes_out != "") print total > bytes_out
}
AWK
}

# dms_archive_counts_check <counts file>
# Host side: the counts must list exactly DMS_ARCHIVE_DATA_TABLES, in TOC order, with plain integer counts.
dms_archive_counts_check() {
  local file="${1:?counts}" i=0 name count
  while IFS=$'\t' read -r name count; do
    [ "$i" -lt "${#DMS_ARCHIVE_DATA_TABLES[@]}" ] || { _dms_archive_fail "more data blocks than TABLE DATA entries"; return 1; }
    [ "$name" = "${DMS_ARCHIVE_DATA_TABLES[$i]}" ] || { _dms_archive_fail "data block ${i} is ${name}, the TOC says ${DMS_ARCHIVE_DATA_TABLES[$i]}"; return 1; }
    [[ "$count" =~ ^[0-9]{1,12}$ ]] || { _dms_archive_fail "data block ${name} has no valid row count"; return 1; }
    i=$((i + 1))
  done < "$file"
  [ "$i" = "${#DMS_ARCHIVE_DATA_TABLES[@]}" ] || { _dms_archive_fail "${#DMS_ARCHIVE_DATA_TABLES[@]} TABLE DATA entries but ${i} data blocks"; return 1; }
}

# dms_archive_digest <counts file>
# The canonical per-table digest: sha256 of "schema<TAB>table<TAB>rows\n" lines in C byte order. The sandbox computes
# the same digest from the restored tables (scripts/restore-drill-sandbox-inner.sh), so ONE value compares every table.
dms_archive_digest() {
  awk -F'\t' '{ split($1, p, "."); printf "%s\t%s\t%s\n", p[1], p[2], $2 }' "${1:?counts}" | LC_ALL=C sort | sha256sum | cut -d' ' -f1
}

# The archive limit for the inspection (the D6 working space admits at most ~96 MiB of compressed archive).
DMS_ARCHIVE_INSPECT_MAX_BYTES=$((128 * 1024 * 1024))

# dms_archive_inspect_script — the fixed script the inspection unit runs. The archive is bound read-only at
# /opt/dmsinspect/archive.dump (development user unit), or arrives on stdin and is copied, bounded, onto the unit's
# own tmpfs (the supervisor's system unit). LC_ALL=C: awk counts BYTES, not characters (R6A-8).
dms_archive_inspect_script() {
  cat << 'SCRIPT'
set -o pipefail
export LC_ALL=C
A=/opt/dmsinspect/archive.dump
if [ ! -f "$A" ]; then
  A=/srv/archive.dump
  /usr/bin/head -c $((ARCHIVE_MAX + 1)) > "$A" || { echo "INSPECT archive_read_failed"; exit 1; }
  [ "$(/usr/bin/wc -c < "$A")" -le "$ARCHIVE_MAX" ] || { echo "INSPECT archive_too_large"; exit 1; }
fi
"$B/pg_restore" -l "$A" > /srv/toc.txt 2> /dev/null || { echo "INSPECT pg_restore_list_failed"; exit 1; }
"$B/pg_restore" --section=data --restrict-key=dmsinspect0 -f - "$A" 2> /dev/null | "$AWK" -v bytes_out=/srv/bytes.txt -f /opt/dmsinspect/count.awk > /srv/counts.txt || { echo "INSPECT data_invalid"; exit 1; }
t=$(/usr/bin/wc -c < /srv/toc.txt) && c=$(/usr/bin/wc -c < /srv/counts.txt) || { echo "INSPECT size_unknown"; exit 1; }
[ "$t" -le "$TOC_MAX" ] || { echo "INSPECT toc_too_large"; exit 1; }
[ "$c" -le "$COUNTS_MAX" ] || { echo "INSPECT counts_too_large"; exit 1; }
echo "INSPECT ok"
printf "@@TOC\n"; /usr/bin/head -c "$TOC_MAX" /srv/toc.txt
printf "@@COUNTS\n"; /usr/bin/head -c "$COUNTS_MAX" /srv/counts.txt
printf "@@BYTES\n"; /usr/bin/head -c 32 /srv/bytes.txt
printf "@@END\n"
SCRIPT
}

# dms_archive_inspect_properties <user|system> <pg bindir> <awk binary> <count program file> [<archive file>]
# The hardened unit (P4V-3): no network, read-only system, /etc, /mnt, /media and the cgroup tree hidden, a private
# /dev/shm, every writable mount a capped noexec tmpfs, nothing but the PostgreSQL binaries, bash, awk, head and wc
# executable, 512 MiB, 300 s. user: the caller's per-user manager (development installs), PrivateUsers explicit
# (R6A I3), the archive bound read-only. system: the root supervisor's DynamicUser unit (drill hosts): no user
# namespace is needed (R6A-3), the archive comes on stdin.
dms_archive_inspect_properties() {
  local mode="${1:?mode}" bindir="${2:?bindir}" awk_bin="${3:?awk}" count="${4:?count program}" archive="${5:-}" binds
  binds="${count}:/opt/dmsinspect/count.awk"
  case "$mode" in
    user) [ -n "$archive" ] || return 1; binds="${archive}:/opt/dmsinspect/archive.dump ${binds}"; printf '%s\n' -p PrivateUsers=yes ;;
    system) printf '%s\n' -p DynamicUser=yes ;;
    *) return 1 ;;
  esac
  printf '%s\n' -p Type=oneshot -p TimeoutStartSec=300 -p MemoryMax=512M -p MemorySwapMax=0 -p TasksMax=16 -p LimitCORE=0 -p CoredumpFilter=0 \
    -p NoNewPrivileges=yes -p ProtectSystem=strict -p ProtectHome=yes -p PrivateTmp=yes -p PrivateDevices=yes -p PrivateIPC=yes \
    -p PrivateNetwork=yes -p RestrictAddressFamilies=AF_UNIX -p ProtectControlGroups=yes -p "InaccessiblePaths=/sys/fs/cgroup" \
    -p ProtectKernelTunables=yes -p ProtectKernelModules=yes -p ProtectKernelLogs=yes -p ProtectClock=yes -p ProtectHostname=yes \
    -p LockPersonality=yes -p RestrictRealtime=yes -p RestrictSUIDSGID=yes -p "CapabilityBoundingSet=" \
    -p "TemporaryFileSystem=/run:ro" -p "TemporaryFileSystem=/var:ro" -p "TemporaryFileSystem=/opt:ro" -p "TemporaryFileSystem=/etc:ro" \
    -p "TemporaryFileSystem=/mnt:ro" -p "TemporaryFileSystem=/media:ro" -p "TemporaryFileSystem=/dev/shm:size=1M,mode=1777,noexec,nosuid,nodev" \
    -p "TemporaryFileSystem=/srv:size=192M,mode=1777,noexec,nosuid,nodev" \
    -p "ReadOnlyPaths=/tmp /var/tmp" -p "BindReadOnlyPaths=${binds}" \
    -p RestrictNamespaces=yes -p MemoryDenyWriteExecute=yes -p SystemCallArchitectures=native -p SystemCallFilter=@system-service \
    -p "SystemCallFilter=~@privileged @resources @mount memfd_create io_uring_setup io_uring_enter io_uring_register @keyring" \
    -p "NoExecPaths=/ /tmp /var/tmp" -p "ExecPaths=${bindir} -${bindir%/bin}/lib -/usr/lib/x86_64-linux-gnu -/usr/lib64 /usr/bin/bash ${awk_bin} /usr/bin/head /usr/bin/wc" \
    "--setenv=B=${bindir}" "--setenv=AWK=${awk_bin}" "--setenv=TOC_MAX=${DMS_ARCHIVE_TOC_MAX_BYTES}" "--setenv=COUNTS_MAX=${DMS_ARCHIVE_COUNTS_MAX_BYTES}" \
    "--setenv=ARCHIVE_MAX=${DMS_ARCHIVE_INSPECT_MAX_BYTES}"
}

# dms_archive_awk_binary — /etc is hidden inside the unit, so the alternatives symlink behind /usr/bin/awk is
# resolved here (P4V-9).
dms_archive_awk_binary() {
  local awk_bin
  awk_bin="$(readlink -f /usr/bin/awk)"
  [[ "$awk_bin" =~ ^/usr/bin/[a-z]+$ ]] && [ -x "$awk_bin" ] || return 1
  printf '%s\n' "$awk_bin"
}

# dms_archive_inspect <archive> <out dir> <pg bindir> <unit name dmsinspect-<12 hex>> [<supervisor command>]
# Runs pg_restore over the untrusted archive inside the hardened unit and writes <out>/toc.txt, counts.txt and
# bytes.txt (the uncompressed size of all rows). With a supervisor command (a root-owned install on a drill host),
# the archive is streamed through `sudo -n <supervisor> inspect <unit>` into a system unit under DynamicUser; without
# one (a development install), a unit of the caller's per-user manager binds the archive read-only. The caller names
# the unit so that it can stop it on an interrupt (P4V-10). A TOC or a count list above its limit is REFUSED, never
# truncated (P4P-2), and the stream must end with its end marker.
dms_archive_inspect() {
  local archive="${1:?archive}" out="${2:?out}" bindir="${3:?bindir}" unit="${4:-}" sup="${5:-}" awk_bin raw_size
  [[ "$unit" =~ ^dmsinspect-[0-9a-f]{12}$ ]] || { _dms_archive_fail "the inspection unit name is invalid"; return 1; }
  local cap=$((DMS_ARCHIVE_TOC_MAX_BYTES + DMS_ARCHIVE_COUNTS_MAX_BYTES + 4096))
  local -a st
  if [ -n "$sup" ]; then
    sudo -n -- "$sup" inspect "$unit" < "$archive" 2> /dev/null | head -c "$((cap + 1))" > "${out}/inspect.raw"
    st=("${PIPESTATUS[@]}")
  else
    awk_bin="$(dms_archive_awk_binary)" || { _dms_archive_fail "no awk binary under /usr/bin"; return 1; }
    dms_archive_count_program > "${out}/count.awk"
    local -a props
    mapfile -t props < <(dms_archive_inspect_properties user "$bindir" "$awk_bin" "${out}/count.awk" "$archive")
    systemd-run --user --wait --pipe --collect --quiet --unit="$unit" "${props[@]}" \
      -- /usr/bin/bash -c "$(dms_archive_inspect_script)" < /dev/null 2> /dev/null | head -c "$((cap + 1))" > "${out}/inspect.raw"
    st=("${PIPESTATUS[@]}")
    rm -f -- "${out}/count.awk"
  fi
  if [ ! -s "${out}/inspect.raw" ]; then
    rm -f -- "${out}/inspect.raw"
    _dms_archive_fail "the sandboxed inspection produced no output: the inspection unit did not start (exit ${st[0]})"
    return 1
  fi
  [ "${st[0]}" = 0 ] && [ "$(head -n 1 "${out}/inspect.raw")" = "INSPECT ok" ] || { _dms_archive_fail "the sandboxed inspection failed ($(head -n 1 "${out}/inspect.raw" | tr -cd 'A-Za-z0-9 _' | head -c 60))"; rm -f -- "${out}/inspect.raw"; return 1; }
  raw_size="$(stat -c %s -- "${out}/inspect.raw")"
  # P4P-2: never act on a cut stream: it must fit and end with the end marker.
  [ "$raw_size" -le "$cap" ] && [ "$(tail -n 1 "${out}/inspect.raw")" = "@@END" ] || { _dms_archive_fail "the inspection output is incomplete or too large; nothing is decided from a truncated TOC"; rm -f -- "${out}/inspect.raw"; return 1; }
  local split='/^@@TOC$/ { s = 1; next } /^@@COUNTS$/ { s = 2; next } /^@@BYTES$/ { s = 3; next } /^@@END$/ { s = 4; next }'
  awk "${split} s == 1" "${out}/inspect.raw" > "${out}/toc.txt"
  awk "${split} s == 2" "${out}/inspect.raw" > "${out}/counts.txt"
  awk "${split} s == 3" "${out}/inspect.raw" > "${out}/bytes.txt"
  rm -f -- "${out}/inspect.raw"
  [ "$(stat -c %s -- "${out}/toc.txt")" -le "$DMS_ARCHIVE_TOC_MAX_BYTES" ] && [ "$(stat -c %s -- "${out}/counts.txt")" -le "$DMS_ARCHIVE_COUNTS_MAX_BYTES" ] \
    || { _dms_archive_fail "the inspection output exceeds its limits"; return 1; }
}

# dms_archive_inspect_stop <unit name> — stops a named inspection unit (the orchestrator's interrupt path, P4V-10).
dms_archive_inspect_stop() {
  local unit="${1:-}"
  [[ "$unit" =~ ^dmsinspect-[0-9a-f]{12}$ ]] || return 0
  systemctl --user stop "${unit}.service" > /dev/null 2>&1
  systemctl --user reset-failed "${unit}.service" > /dev/null 2>&1
  return 0
}
