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
DMS_ARCHIVE_ALLOWED_SCHEMAS="public"
DMS_ARCHIVE_MAX_TOC_LINES=50000
DMS_ARCHIVE_MAX_TABLES=2000
declare -ga DMS_ARCHIVE_DATA_TABLES=()
declare -gA DMS_ARCHIVE_OWNERS=()
_dms_archive_fail() { echo "archive: $*"; return 1; }

# dms_archive_toc_validate <pg_restore -l output> <allowed owner roles (space-separated)> <extension allowlist (space-separated, may be empty)>
# Fills DMS_ARCHIVE_DATA_TABLES (schema.table, TOC order) and DMS_ARCHIVE_OWNERS. Returns 1 with the reason.
dms_archive_toc_validate() {
  local toc="${1:?toc}" owners=" ${2-} " exts=" ${3-} " line n=0 rest type schema tagowner tag owner t
  local -A tables=() datas=()
  DMS_ARCHIVE_DATA_TABLES=()
  DMS_ARCHIVE_OWNERS=()
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
    elif [ "$owner" != pg_database_owner ]; then
      [[ "$owners" == *" ${owner} "* ]] || { _dms_archive_fail "the archive makes '${owner}' an owner and the manifest does not list it (D-Q6-a)"; return 1; }
      DMS_ARCHIVE_OWNERS[$owner]=1
    fi
    case "$type" in
      TABLE | "TABLE DATA" | SEQUENCE | "SEQUENCE SET" | "SEQUENCE OWNED BY")
        [[ "$tag" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || { _dms_archive_fail "TOC line ${n}: ${type} name '${tag}' is not a plain lowercase identifier"; return 1; }
        [ "$schema" != "-" ] || { _dms_archive_fail "TOC line ${n}: ${type} without a schema"; return 1; } ;;
      EXTENSION)
        [[ "$tag" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] && [[ "$exts" == *" ${tag} "* ]] || { _dms_archive_fail "the archive creates extension '${tag}', which is not in the allowlist (PR-8)"; return 1; } ;;
    esac
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
  return 0
}

# dms_archive_count_program
# The awk program that validates and counts `pg_restore --section=data -f -` output (run inside the inspection unit).
# Outside COPY blocks only pg_restore's own framing is accepted; inside a block every line up to `\.` is a row and must
# have exactly as many tab-separated fields as the COPY column list. Prints "schema.table<TAB>rows" per block, or
# "ERROR <reason>" and exits 1.
dms_archive_count_program() {
  cat << 'AWK'
BEGIN { inblk = 0; started = 0; ended = 0; n = 0; failed = 0 }
function fail(why) { print "ERROR " why; failed = 1; exit 1 }
inblk {
  if ($0 == "\\.") { print tbl "\t" rows; inblk = 0; next }
  if (split($0, f, "\t") != cols) fail("row field count differs from the COPY column list in " tbl)
  rows++; next
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
  if (match($0, /^COPY [a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]* \([^()]*\) FROM stdin;$/)) {
    tbl = $2; c = $0; sub(/^COPY [^ ]+ \(/, "", c); sub(/\) FROM stdin;$/, "", c)
    cols = split(c, cc, ", "); rows = 0; inblk = 1; next
  }
  fail("unexpected line in the data section")
}
END {
  if (failed) exit 1
  if (inblk) fail("unterminated COPY block in " tbl)
  if (!ended) fail("data stream does not close with \\unrestrict")
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

# dms_archive_inspect <archive> <out dir> <pg bindir>
# Runs pg_restore over the untrusted archive inside a hardened transient unit of the caller's per-user manager: no
# network, read-only system, the archive bound read-only, every writable mount a capped noexec tmpfs, nothing but the
# PostgreSQL binaries, bash and awk executable, 512 MiB, 300 s. Writes <out>/toc.txt and <out>/counts.txt (bounded).
dms_archive_inspect() {
  local archive="${1:?archive}" out="${2:?out}" bindir="${3:?bindir}" unit
  unit="dmsinspect-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
  dms_archive_count_program > "${out}/count.awk"
  local script='set -o pipefail
"$B/pg_restore" -l /opt/dmsinspect/archive.dump > /srv/toc.txt 2> /dev/null || { echo "INSPECT pg_restore_list_failed"; exit 1; }
"$B/pg_restore" --section=data --restrict-key=dmsinspect0 -f - /opt/dmsinspect/archive.dump 2> /dev/null | /usr/bin/awk -f /opt/dmsinspect/count.awk > /srv/counts.txt || { echo "INSPECT data_invalid"; exit 1; }
echo "INSPECT ok"
printf "@@TOC\n"; /usr/bin/head -c 8388608 /srv/toc.txt
printf "@@COUNTS\n"; /usr/bin/head -c 4194304 /srv/counts.txt'
  systemd-run --user --wait --pipe --collect --quiet --unit="$unit" \
    -p Type=oneshot -p TimeoutStartSec=300 -p MemoryMax=512M -p MemorySwapMax=0 -p TasksMax=16 -p LimitCORE=0 \
    -p NoNewPrivileges=yes -p ProtectSystem=strict -p ProtectHome=yes -p PrivateTmp=yes -p PrivateDevices=yes -p PrivateIPC=yes \
    -p PrivateNetwork=yes -p RestrictAddressFamilies=AF_UNIX -p "TemporaryFileSystem=/run:ro" -p "TemporaryFileSystem=/var:ro" \
    -p "TemporaryFileSystem=/opt:ro" -p "TemporaryFileSystem=/srv:size=64M,mode=1777,noexec,nosuid,nodev" \
    -p "ReadOnlyPaths=/tmp /var/tmp" -p "BindReadOnlyPaths=${archive}:/opt/dmsinspect/archive.dump ${out}/count.awk:/opt/dmsinspect/count.awk" \
    -p RestrictNamespaces=yes -p MemoryDenyWriteExecute=yes -p SystemCallArchitectures=native -p SystemCallFilter=@system-service \
    -p "SystemCallFilter=~@privileged @resources @mount memfd_create io_uring_setup io_uring_enter io_uring_register @keyring" \
    -p "NoExecPaths=/ /tmp /var/tmp" -p "ExecPaths=${bindir} ${bindir%/bin}/lib /usr/lib/x86_64-linux-gnu /usr/lib64 /usr/bin/bash /usr/bin/awk /usr/bin/mawk /usr/bin/gawk /usr/bin/head" \
    "--setenv=B=${bindir}" -- /usr/bin/bash -c "$script" < /dev/null 2> /dev/null | head -c 12582912 > "${out}/inspect.raw"
  local st=("${PIPESTATUS[@]}")
  rm -f -- "${out}/count.awk"
  [ "${st[0]}" = 0 ] && [ "$(head -n 1 "${out}/inspect.raw")" = "INSPECT ok" ] || { _dms_archive_fail "the sandboxed inspection failed ($(head -n 1 "${out}/inspect.raw" | tr -cd 'A-Za-z0-9 _' | head -c 60))"; return 1; }
  awk '/^@@TOC$/ { s = 1; next } /^@@COUNTS$/ { s = 2; next } s == 1' "${out}/inspect.raw" > "${out}/toc.txt"
  awk '/^@@TOC$/ { s = 1; next } /^@@COUNTS$/ { s = 2; next } s == 2' "${out}/inspect.raw" > "${out}/counts.txt"
  rm -f -- "${out}/inspect.raw"
}
