---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# Lampiran G7-B — Sumber Tool Escrow dan Inventaris (2026-10-30)

Sumber lengkap tool yang dipakai di [G7B-FINAL-READINESS-REPORT](G7B-FINAL-READINESS-REPORT.md), dicatat
di sini agar dapat ditinjau dan dipakai ulang secara persis. Sebelum dipakai, salin isi blok ke file di
direktori khusus root, lalu cocokkan sha256-nya dengan nilai di tabel. Kembali ke [00-INDEX](../../00-INDEX.md).

Tool ini **belum** menjadi bagian dari aplikasi dan tidak dijalankan oleh CI. Bila owner menjadwalkan
seremoni escrow, tool sebaiknya dipindahkan ke `scripts/security/` bersama test-nya lewat pull request
tersendiri.

| Tool | sha256 | Kegunaan |
|---|---|---|
| `g7b-escrow-app-key.sh` | `c23037cbb4daa56487772fb956612e0e6ee64b54bc7f49006e0685b2e0dd68a2` | Escrow dual-custodian: seal, verify, recovery-check, rehearse. Dijalankan sebagai root di host produksi. |
| `g7b-env-copy-inventory.py` | `a4c41ce9693ab25cfd642c7c5b877204daff4604119e720283e36000d7817e30` | Inventaris salinan environment berupa file biasa. Baca-saja; hanya mencetak kelas, jumlah, mode, dan boolean. |
| `g7b-env-archive-scan.py` | `c7f4170327926a3338e7fc1293906f96c8cd6760635cac153819bb2fc954e203` | Inventaris file environment di dalam arsip tar/zip. Baca-saja; anggota arsip dibaca di memori. |
| `f4-activity-gate.php` | `9f4c58a2bd37dec762dc317496037e43761a36eb08fd328345149ba3cc9beacc` | Gate aktivitas klinis/kasir sebelum operasi berisiko. Sesi database dipaksa baca-saja; GATE PASS hanya bila semua sinyal aktif 0. |

Cara menjalankan (tanpa nilai rahasia):

- Inventaris: `python3 -I - < g7b-env-copy-inventory.py` dan `python3 -I - < g7b-env-archive-scan.py`, sebagai root di host.
- Gate aktivitas: `runuser -u daengtisiams -- php -d display_errors=stderr -- --json < f4-activity-gate.php` dari direktori aplikasi.
- Gladi escrow: `bash g7b-escrow-app-key.sh --mode rehearse --env <file environment aktif> --app-dir <direktori aplikasi> --with-recovery-check`.
- Seremoni nyata: `--mode seal --custodian <label> --out <path>` lewat `ssh -t` agar custodian mengetik passphrase di prompt GPG; lalu `--mode verify` dan `--mode recovery-check` per salinan.

## `g7b-escrow-app-key.sh`

Escrow dual-custodian: seal, verify, recovery-check, rehearse. Dijalankan sebagai root di host produksi.

```bash
#!/usr/bin/env bash
# G7-B — dual-custodian escrow of the CURRENT application key, before rotation (DaengtisiaMS).
#
# Why: once the key rotates, backups taken before the rotation can only be read with the old key.
# The old key must therefore survive — but only in escrow, never on the host.
#
# Modes:
#   seal            encrypt the current key for ONE custodian. The custodian types the passphrase at
#                   the terminal (gpg pinentry). Writes <out> (ciphertext) and <out>.manifest
#                   (custodian label, time, ciphertext sha256, salted key identity — never the key).
#   verify          decrypt <in> in memory; print MATCH/MISMATCH against its manifest and, with --env,
#                   against the live key. Never prints the key.
#   recovery-check  decrypt <in> in memory; restore the newest pre-deploy backup into a DISPOSABLE
#                   database; prove the escrowed key, configured as the previous key beside a fresh
#                   random key, decrypts every encrypted value (0 undecryptable). Drops the copy.
#                   Production database, configuration and key are never written. Needs root + the
#                   release carrying `security:app-key-reencrypt`.
#   rehearse        full chain with GENERATED passphrases, inside a 0700 temp dir created and destroyed
#                   in the same run: seal x2 (two custodians), verify x2, negative controls (wrong
#                   passphrase, tampered ciphertext, foreign key), optional recovery-check. Leaves nothing.
#
# Secrets: the key travels only through pipes and the process environment, never argv, never a
# plaintext file, never stdout. gpg runs in a throwaway GNUPGHOME with passphrase caching disabled.
set -euo pipefail
set +x
umask 077

MODE=""; ENV_FILE=""; OUT=""; IN=""; CUSTODIAN=""; APP_DIR=""; RUNTIME_USER="daengtisiams"; WITH_RECOVERY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --mode) MODE="$2"; shift 2 ;;
    --env) ENV_FILE="$2"; shift 2 ;;
    --out) OUT="$2"; shift 2 ;;
    --in) IN="$2"; shift 2 ;;
    --custodian) CUSTODIAN="$2"; shift 2 ;;
    --app-dir) APP_DIR="$2"; shift 2 ;;
    --runtime-user) RUNTIME_USER="$2"; shift 2 ;;
    --with-recovery-check) WITH_RECOVERY=1; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
die() { echo "G7B-ESCROW: FAIL — $*" >&2; exit 1; }
say() { echo "G7B-ESCROW: $*"; }

GHOME=$(mktemp -d); WORK=""
# Everything a run can leave behind is a GLOBAL owned by this one cleanup. A trap set inside a
# function must not be used: it fires after the function's locals are gone, `set -u` aborts it, and
# the artefacts survive (that happened in the first production rehearsal — corrected).
SCRATCH=""; ESC=""; NEW=""
cleanup() {
  set +e +u
  if [ -n "$SCRATCH" ]; then runuser -u postgres -- dropdb --if-exists "$SCRATCH" >/dev/null 2>&1; fi
  ESC=""; NEW=""; unset ESC NEW
  gpgconf --homedir "$GHOME" --kill gpg-agent >/dev/null 2>&1
  rm -rf -- "$GHOME"
  if [ -n "$WORK" ] && [ -d "$WORK" ]; then rm -rf -- "$WORK"; fi
}
trap cleanup EXIT
export GNUPGHOME="$GHOME"
# The ceremony runs over SSH (`ssh -t`); pinentry-curses needs the terminal named.
if [ -t 0 ]; then GPG_TTY=$(tty); export GPG_TTY; fi
GPG_SYM=(gpg --batch --yes --quiet --no-symkey-cache --symmetric --cipher-algo AES256
         --s2k-mode 3 --s2k-digest-algo SHA512 --s2k-count 65011712 --compress-algo none)
GPG_DEC=(gpg --batch --quiet --no-symkey-cache --decrypt)

# Print the value of APP_KEY from an environment file to STDOUT for a PIPE (exactly-once rule).
key_from_env() {
  python3 -I - "$1" <<'PY'
import sys
vals = [l.split('=', 1)[1].rstrip('\n') for l in open(sys.argv[1]) if l.startswith('APP_KEY=')]
if len(vals) != 1:
    sys.exit(3)
v = vals[0].strip()
if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
    v = v[1:-1]
sys.stdout.write(v)
PY
}

# Read a key on STDIN; print "valid=<0|1> identity=<16 hex>". The identity is a salted SHA-256 of a
# 256-bit random key: it lets two copies be compared, and reveals nothing about the key.
identify() {
  python3 -I -c '
import sys, hashlib, base64
v = sys.stdin.read()
ok = v.startswith("base64:")
try:
    ok = ok and len(base64.b64decode(v[7:], validate=True)) == 32
except Exception:
    ok = False
print("valid=%d identity=%s" % (1 if ok else 0, hashlib.sha256(b"dms-g7b-key-identity|" + v.encode()).hexdigest()[:16]))'
}

manifest_get() { python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$1" "$2"; }

seal() { # $1 out  $2 custodian  [$3 passphrase fd, rehearsal only]
  local out=$1 who=$2 pfd=${3:-}
  [ ! -e "$out" ] || die "$out exists — refusing to overwrite an escrow copy"
  [ -n "$ENV_FILE" ] && [ -r "$ENV_FILE" ] || die "--env missing or unreadable"
  local id; id=$(key_from_env "$ENV_FILE" | identify) || die "APP_KEY not found exactly once"
  [ "${id%% *}" = "valid=1" ] || die "the current key is not a valid base64 32-byte key"
  if [ -n "$pfd" ]; then
    key_from_env "$ENV_FILE" | "${GPG_SYM[@]}" --pinentry-mode loopback --passphrase-fd "$pfd" --output "$out"
  else
    say "custodian '$who': type a NEW passphrase twice at the prompt (it is never shown or stored)"
    key_from_env "$ENV_FILE" | gpg --quiet --no-symkey-cache --symmetric --cipher-algo AES256 \
      --s2k-mode 3 --s2k-digest-algo SHA512 --s2k-count 65011712 --compress-algo none --output "$out"
  fi
  chmod 600 "$out"
  python3 -I - "$out" "$who" "${id#* identity=}" <<'PY'
import json, sys, hashlib, datetime, socket
out, who, ident = sys.argv[1:4]
json.dump({
    "custodian": who,
    "sealed_at_utc": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "host": socket.gethostname(),
    "ciphertext_sha256": hashlib.sha256(open(out, "rb").read()).hexdigest(),
    "key_identity": ident,
    "cipher": "gpg symmetric AES256, S2K SHA512 x65011712",
}, open(out + ".manifest", "w"), indent=2)
PY
  chmod 600 "$out.manifest"
  say "SEALED custodian=$who identity=${id#* identity=} ciphertext_sha256=$(sha256sum "$out" | cut -c1-16)…"
}

# Decrypt $1 and print "valid=… identity=…" (key never leaves the pipe). [$2 passphrase fd]
decrypt_identity() {
  local in=$1 pfd=${2:-}
  if [ -n "$pfd" ]; then "${GPG_DEC[@]}" --pinentry-mode loopback --passphrase-fd "$pfd" "$in" 2>/dev/null | identify
  else gpg --quiet --no-symkey-cache --decrypt "$in" 2>/dev/null | identify; fi
}

verify() { # $1 in  [$2 passphrase fd]
  local in=$1 pfd=${2:-}
  [ -f "$in" ] && [ -f "$in.manifest" ] || die "ciphertext or manifest missing"
  [ "$(sha256sum "$in" | cut -d' ' -f1)" = "$(manifest_get "$in.manifest" ciphertext_sha256)" ] \
    || die "ciphertext does not match its manifest (tampered or wrong file)"
  local got; got=$(decrypt_identity "$in" "$pfd") || true
  [ "${got%% *}" = "valid=1" ] || die "decryption failed (wrong passphrase or damaged copy)"
  local want; want=$(manifest_get "$in.manifest" key_identity)
  [ "${got#* identity=}" = "$want" ] || die "decrypted key identity does not match the manifest"
  if [ -n "$ENV_FILE" ]; then
    local live; live=$(key_from_env "$ENV_FILE" | identify)
    if [ "${live#* identity=}" = "$want" ]; then say "VERIFY MATCH custodian=$(manifest_get "$in.manifest" custodian) — escrowed key == live key"
    else say "VERIFY MATCH custodian=$(manifest_get "$in.manifest" custodian) — escrowed key is NOT the live key (expected only after rotation)"; fi
  else
    say "VERIFY MATCH custodian=$(manifest_get "$in.manifest" custodian) identity=$want"
  fi
}

recovery_check() { # $1 in  [$2 passphrase fd]
  local in=$1 pfd=${2:-}
  [ "$(id -u)" = 0 ] || die "recovery-check needs root (it restores a disposable database)"
  [ -n "$APP_DIR" ] || APP_DIR=$(dirname "$ENV_FILE")
  cd "$APP_DIR"
  runuser -u "$RUNTIME_USER" -- php artisan list --raw | grep -q '^security:app-key-reencrypt ' \
    || die "security:app-key-reencrypt is not registered on the deployed release"
  local dump; dump=$(ls -1t storage/app/backups/deploy/pre_auto_deploy_*.sql | head -1)
  val() { sed -n "s/^$1=//p" "$ENV_FILE" | head -1 | sed -e "s/^[\"']//" -e "s/[\"']\s*$//" -e "s/\s*$//"; }
  local prod role; prod=$(val DB_DATABASE); role=$(val DB_USERNAME)
  local scratch="dms_g7b_escrow_check_$(date -u +%Y%m%d%H%M%S)"
  [ -n "$prod" ] && [ -n "$role" ] && [ "$scratch" != "$prod" ] || die "database identity unresolved"
  if [ -n "$pfd" ]; then ESC=$("${GPG_DEC[@]}" --pinentry-mode loopback --passphrase-fd "$pfd" "$in" 2>/dev/null) || die "decryption failed"
  else ESC=$(gpg --quiet --no-symkey-cache --decrypt "$in" 2>/dev/null) || die "decryption failed"; fi
  NEW="base64:$(head -c 32 /dev/urandom | base64 -w0)"
  [ -n "$ESC" ] && [ "$ESC" != "$NEW" ] || die "key material unresolved"
  SCRATCH="$scratch"   # registered with the global cleanup BEFORE the database exists
  runuser -u postgres -- createdb -O "$role" "$scratch"
  runuser -u postgres -- psql -q -v ON_ERROR_STOP=1 --single-transaction -d "$scratch" < "$dump" >/dev/null
  say "source backup $(basename "$dump") restored into a disposable database"
  art() { # $1 current key  $2 previous key  then artisan args — keys via environment only
    local cur=$1 prev=$2; shift 2
    # A shell assignment prefix, NOT `env K=V cmd`: with `env` the key would be an ARGUMENT of
    # /usr/bin/env, readable by any local account in /proc/<pid>/cmdline until it execs.
    APP_CONFIG_CACHE=/nonexistent/g7b-escrow-config.php DB_DATABASE="$scratch" APP_KEY="$cur" APP_PREVIOUS_KEYS="$prev" HOME=/tmp \
      runuser --preserve-environment -u "$RUNTIME_USER" -- php artisan "$@"
  }
  local connected; connected=$(art "$NEW" "$ESC" db:show 2>/dev/null | grep -E '^\s*Database\s' | awk '{print $NF}' || true)
  [ "$connected" = "$scratch" ] || die "guard: process is not connected to the disposable copy"
  counts() { python3 -I -c 'import json,sys; r=json.load(sys.stdin); t=r["totals"]; print(t["total"], t["previous"], t["undecryptable"], r["previous_keys_configured"])'; }
  local a0 a; a0=$(art "$NEW" "" security:app-key-reencrypt --json 2>/dev/null | counts || true)
  a=$(art "$NEW" "$ESC" security:app-key-reencrypt --json 2>/dev/null | counts)
  read -r t0 p0 u0 k0 <<<"$a0"; read -r t1 p1 u1 k1 <<<"$a"
  say "A0 fresh key only:                 total=$t0 undecryptable=$u0 (control: the backup is unreadable without the escrowed key)"
  say "A  fresh key + escrowed as previous: total=$t1 previous=$p1 undecryptable=$u1 previous_keys=$k1"
  # t1 > 0: with no encrypted values at all, previous == total would pass while proving nothing.
  [ "${t1:-0}" -gt 0 ] && [ "$k1" = 1 ] && [ "$u1" = 0 ] && [ "$p1" = "$t1" ] && [ "$u0" = "$t0" ] \
    || die "RECOVERY CHECK FAILED — the escrowed copy does not recover the pre-rotation backup"
  say "RECOVERY CHECK PASS — the escrowed key reads every encrypted value of the pre-rotation backup"
}

case "$MODE" in
  seal)  [ -n "$OUT" ] && [ -n "$CUSTODIAN" ] || die "seal needs --env --out --custodian"; seal "$OUT" "$CUSTODIAN" ;;
  verify) [ -n "$IN" ] || die "verify needs --in"; verify "$IN" ;;
  recovery-check) [ -n "$IN" ] && [ -n "$ENV_FILE" ] || die "recovery-check needs --in --env"; recovery_check "$IN" ;;
  rehearse)
    [ -n "$ENV_FILE" ] || die "rehearse needs --env"
    WORK=$(mktemp -d); chmod 700 "$WORK"
    P1=$(head -c 24 /dev/urandom | base64 -w0); P2=$(head -c 24 /dev/urandom | base64 -w0)
    seal "$WORK/primary.gpg" rehearsal-primary 3 3<<<"$P1"
    seal "$WORK/secondary.gpg" rehearsal-secondary 3 3<<<"$P2"
    [ "$(manifest_get "$WORK/primary.gpg.manifest" ciphertext_sha256)" != "$(manifest_get "$WORK/secondary.gpg.manifest" ciphertext_sha256)" ] \
      || die "the two copies are byte-identical (no independent encryption)"
    verify "$WORK/primary.gpg" 3 3<<<"$P1"
    verify "$WORK/secondary.gpg" 3 3<<<"$P2"
    # Negative controls — each must FAIL.
    if ( verify "$WORK/primary.gpg" 3 3<<<"$P2" ) >/dev/null 2>&1; then die "control: the wrong passphrase was accepted"; fi
    say "control PASS: a copy does not open with the other custodian's passphrase"
    cp "$WORK/secondary.gpg" "$WORK/tampered.gpg"; cp "$WORK/secondary.gpg.manifest" "$WORK/tampered.gpg.manifest"
    printf '\x00' | dd of="$WORK/tampered.gpg" bs=1 seek=40 conv=notrunc status=none
    if ( verify "$WORK/tampered.gpg" 3 3<<<"$P2" ) >/dev/null 2>&1; then die "control: a tampered copy was accepted"; fi
    say "control PASS: a tampered copy is refused by its manifest"
    rehash() { python3 -I -c 'import json,sys,hashlib; p=sys.argv[1]; m=json.load(open(p+".manifest")); m["ciphertext_sha256"]=hashlib.sha256(open(p,"rb").read()).hexdigest(); json.dump(m,open(p+".manifest","w"))' "$1"; }
    rehash "$WORK/tampered.gpg"
    if ( verify "$WORK/tampered.gpg" 3 3<<<"$P2" ) >/dev/null 2>&1; then die "control: gpg accepted a modified ciphertext"; fi
    say "control PASS: a tampered copy is refused by gpg's integrity check even with a forged manifest"
    printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 -w0)" | "${GPG_SYM[@]}" --pinentry-mode loopback --passphrase-fd 3 --output "$WORK/foreign.gpg" 3<<<"$P1"
    cp "$WORK/primary.gpg.manifest" "$WORK/foreign.gpg.manifest"
    rehash "$WORK/foreign.gpg"
    if ( verify "$WORK/foreign.gpg" 3 3<<<"$P1" ) >/dev/null 2>&1; then die "control: a different key passed as the escrowed key"; fi
    say "control PASS: a copy holding a different key is refused"
    if [ "$WITH_RECOVERY" = 1 ]; then recovery_check "$WORK/secondary.gpg" 3 3<<<"$P2"; fi
    unset P1 P2
    say "REHEARSAL PASS — artefacts destroyed with the temp directory"
    ;;
  *) echo "usage: --mode seal|verify|recovery-check|rehearse (see header)" >&2; exit 2 ;;
esac
```

## `g7b-env-copy-inventory.py`

Inventaris salinan environment berupa file biasa. Baca-saja; hanya mencetak kelas, jumlah, mode, dan boolean.

```python
#!/usr/bin/env python3
"""G7-B environment-copy inventory — READ-ONLY. Run as root on the VPS:

    python3 -I - < g7b-env-copy-inventory.py

Finds every regular file (<= 256 KiB) under the known locations that carries an
`APP_KEY=` line, and classifies it against the LIVE environment file by comparing
values inside this process. Prints location classes, counts, modes, date ranges
and booleans only — never a value, never a filename (some contain staff names).
"""
import datetime
import hashlib
import json
import os
import stat

APP = '/var/www/asia-dental-lab-v2'
LIVE = os.path.join(APP, '.env')
ROOTS = [  # (class label, directory, max depth)
    ('app_root', APP, 1),
    ('storage_backups', os.path.join(APP, 'storage/backups'), 2),
    ('storage_app_backups', os.path.join(APP, 'storage/app/backups'), 3),
    ('root_home', '/root', 3),
    ('old_app_dir', '/var/www/asia-dental-lab', 1),
]
SECRET_KEYS = ('APP_KEY', 'APP_PREVIOUS_KEYS', 'DB_PASSWORD', 'MAIL_PASSWORD', 'REDIS_PASSWORD',
               'OBJECT_STORAGE_SECRET', 'AWS_SECRET_ACCESS_KEY')


def parse(path):
    out = {}
    try:
        with open(path, 'r', errors='replace') as fh:
            for line in fh:
                if '=' not in line or line.lstrip().startswith('#'):
                    continue
                k, v = line.split('=', 1)
                k = k.strip()
                v = v.strip()
                if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
                    v = v[1:-1]
                if k in SECRET_KEYS and k not in out:
                    out[k] = v
    except OSError:
        return None
    return out


def walk(root, depth):
    if not os.path.isdir(root):
        return
    base = root.rstrip('/').count('/')
    for d, dirs, files in os.walk(root):
        if d.count('/') - base >= depth:
            dirs[:] = []
        for name in files:
            p = os.path.join(d, name)
            try:
                st = os.lstat(p)
            except OSError:
                continue
            if stat.S_ISREG(st.st_mode) and st.st_size <= 262144:
                yield p, st


def all_ancestors_traversable(path):
    d = os.path.dirname(os.path.realpath(path))
    while True:
        try:
            if not os.stat(d).st_mode & 0o001:
                return False
        except OSError:
            return False
        if d == '/':
            return True
        d = os.path.dirname(d)


live = parse(LIVE) or {}
live_real = os.path.realpath(LIVE)
result = {'generated_at_utc': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
          'live_has_app_key': bool(live.get('APP_KEY')), 'classes': {}, 'totals': {}}
seen = set()
for label, root, depth in ROOTS:
    rows = []
    for p, st in walk(root, depth):
        rp = os.path.realpath(p)
        if rp == live_real or rp in seen:
            continue
        vals = parse(p)
        if not vals or 'APP_KEY' not in vals:
            continue
        seen.add(rp)
        rows.append({
            'mode': oct(st.st_mode & 0o777)[2:],
            'owner': st.st_uid,
            'other_readable': bool(st.st_mode & 0o004),
            'mtime': datetime.datetime.fromtimestamp(st.st_mtime, datetime.timezone.utc).strftime('%Y-%m-%d'),
            'live_app_key': bool(vals.get('APP_KEY')) and vals.get('APP_KEY') == live.get('APP_KEY'),
            'live_db_password': bool(vals.get('DB_PASSWORD')) and vals.get('DB_PASSWORD') == live.get('DB_PASSWORD'),
            'other_live_secret': any(vals.get(k) and vals.get(k) == live.get(k) and vals.get(k).lower() != 'null'
                                     for k in SECRET_KEYS if k not in ('APP_KEY', 'DB_PASSWORD')),
            'parent_mode': oct(os.stat(os.path.dirname(p)).st_mode & 0o777)[2:],
            'key_state': ('empty' if not vals.get('APP_KEY') else
                          'live' if vals.get('APP_KEY') == live.get('APP_KEY') else 'other'),
            'template_like': os.path.basename(p).endswith('.example'),
            'reachable_by_others': bool(st.st_mode & 0o004) and all_ancestors_traversable(p),
        })
    if rows:
        result['classes'][label] = {
            'count': len(rows),
            'modes': sorted({r['mode'] for r in rows}),
            'parent_modes': sorted({r['parent_mode'] for r in rows}),
            'date_range': [min(r['mtime'] for r in rows), max(r['mtime'] for r in rows)],
            'holding_live_app_key': sum(r['live_app_key'] for r in rows),
            'holding_live_db_password': sum(r['live_db_password'] for r in rows),
            'holding_other_live_secret': sum(r['other_live_secret'] for r in rows),
            'other_readable': sum(r['other_readable'] for r in rows),
            'key_state': {k: sum(1 for r in rows if r['key_state'] == k) for k in ('live', 'other', 'empty')},
            'template_like': sum(r['template_like'] for r in rows),
            'reachable_by_others': sum(r['reachable_by_others'] for r in rows),
            'reachable_by_others_key_state': sorted({r['key_state'] for r in rows if r['reachable_by_others']}),
        }
all_rows = result['classes'].values()
result['totals'] = {
    'copies': sum(c['count'] for c in all_rows),
    'holding_live_app_key': sum(c['holding_live_app_key'] for c in all_rows),
    'holding_live_db_password': sum(c['holding_live_db_password'] for c in all_rows),
    'holding_other_live_secret': sum(c['holding_other_live_secret'] for c in all_rows),
    'other_readable': sum(c['other_readable'] for c in all_rows),
    'reachable_by_others': sum(c['reachable_by_others'] for c in all_rows),
    'key_state': {k: sum(c['key_state'][k] for c in all_rows) for k in ('live', 'other', 'empty')},
}
# A digest of the (sorted) set of copy paths lets two inventories be compared for drift
# without disclosing any filename.
result['set_digest'] = hashlib.sha256('\n'.join(sorted(seen)).encode()).hexdigest()[:16]
print(json.dumps(result, indent=2))
```

## `g7b-env-archive-scan.py`

Inventaris file environment di dalam arsip tar/zip. Baca-saja; anggota arsip dibaca di memori.

```python
#!/usr/bin/env python3
"""G7-B — environment copies INSIDE archives. READ-ONLY. Run as root on the VPS:

    python3 -I - < g7b-env-archive-scan.py

The plain-file inventory (g7b-env-copy-inventory.py) cannot see an environment file packed inside a
tar/zip backup. This scans archives under the same roots, reads members named like an environment
file in memory, and compares their key against the LIVE environment file inside this process.
Prints classes, counts, modes and booleans only — never a value, an archive name or a member name.
"""
import datetime
import hashlib
import io
import json
import os
import stat
import tarfile
import zipfile

APP = '/var/www/asia-dental-lab-v2'
LIVE = os.path.join(APP, '.env')
ROOTS = [
    ('app_root', APP, 1),
    ('storage_backups', os.path.join(APP, 'storage/backups'), 2),
    ('storage_app_backups', os.path.join(APP, 'storage/app/backups'), 3),
    ('storage_app_deploy_backups', os.path.join(APP, 'storage/app/deploy-backups'), 3),
    ('root_home', '/root', 3),
    ('old_app_dir', '/var/www/asia-dental-lab', 2),
]
ARCHIVE_SUFFIXES = ('.tar', '.tar.gz', '.tgz', '.tar.bz2', '.tar.xz', '.zip')
MAX_ARCHIVE = 1024 * 1024 * 1024
MAX_MEMBER = 256 * 1024


def parse_text(text):
    out = {}
    for line in text.splitlines():
        if '=' not in line or line.lstrip().startswith('#'):
            continue
        k, v = line.split('=', 1)
        k, v = k.strip(), v.strip()
        if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
            v = v[1:-1]
        if k in ('APP_KEY', 'DB_PASSWORD') and k not in out:
            out[k] = v
    return out


def traversable(path):
    d = os.path.dirname(os.path.realpath(path))
    while True:
        try:
            if not os.stat(d).st_mode & 0o001:
                return False
        except OSError:
            return False
        if d == '/':
            return True
        d = os.path.dirname(d)


def env_like(member_name):
    base = os.path.basename(member_name.rstrip('/'))
    return base.startswith('.env') and not base.endswith('.example')


def members(path):
    """Yield the bytes of every environment-like member; raise on an unreadable archive."""
    if path.endswith('.zip'):
        with zipfile.ZipFile(path) as z:
            for info in z.infolist():
                if not info.is_dir() and env_like(info.filename) and info.file_size <= MAX_MEMBER:
                    yield z.read(info)
    else:
        with tarfile.open(path, 'r:*') as t:
            for m in t:
                if m.isfile() and env_like(m.name) and m.size <= MAX_MEMBER:
                    f = t.extractfile(m)
                    if f is not None:
                        yield f.read()


live = {}
try:
    live = parse_text(open(LIVE, errors='replace').read())
except OSError:
    pass

result = {'generated_at_utc': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
          'live_has_app_key': bool(live.get('APP_KEY')), 'classes': {}, 'unreadable_archives': 0}
seen = set()
for label, root, depth in ROOTS:
    if not os.path.isdir(root):
        continue
    base = root.rstrip('/').count('/')
    c = {'archives_scanned': 0, 'archives_with_env_member': 0, 'env_members': 0,
         'members_live_app_key': 0, 'members_other_app_key': 0, 'members_empty_app_key': 0,
         'members_live_db_password': 0, 'archive_modes': set(), 'archive_dates': [],
         'archives_holding_live_key_reachable_by_others': 0}
    for d, dirs, files in os.walk(root):
        if d.count('/') - base >= depth:
            dirs[:] = []
        for name in files:
            p = os.path.join(d, name)
            if not name.endswith(ARCHIVE_SUFFIXES):
                continue
            try:
                st = os.lstat(p)
            except OSError:
                continue
            rp = os.path.realpath(p)
            if not stat.S_ISREG(st.st_mode) or st.st_size > MAX_ARCHIVE or rp in seen:
                continue
            seen.add(rp)
            c['archives_scanned'] += 1
            found = 0
            holds_live = False
            try:
                for raw in members(p):
                    vals = parse_text(raw.decode('utf-8', errors='replace'))
                    if 'APP_KEY' not in vals:
                        continue
                    found += 1
                    key = vals.get('APP_KEY')
                    if not key:
                        c['members_empty_app_key'] += 1
                    elif key == live.get('APP_KEY'):
                        c['members_live_app_key'] += 1
                        holds_live = True
                    else:
                        c['members_other_app_key'] += 1
                    if vals.get('DB_PASSWORD') and vals.get('DB_PASSWORD') == live.get('DB_PASSWORD'):
                        c['members_live_db_password'] += 1
            except Exception:
                result['unreadable_archives'] += 1
                continue
            if found:
                c['archives_with_env_member'] += 1
                c['env_members'] += found
                c['archive_modes'].add(oct(st.st_mode & 0o777)[2:])
                c['archive_dates'].append(
                    datetime.datetime.fromtimestamp(st.st_mtime, datetime.timezone.utc).strftime('%Y-%m-%d'))
                if holds_live and (st.st_mode & 0o004) and traversable(p):
                    c['archives_holding_live_key_reachable_by_others'] += 1
    if c['archives_scanned']:
        c['archive_modes'] = sorted(c['archive_modes'])
        dates = c.pop('archive_dates')
        c['archive_date_range'] = [min(dates), max(dates)] if dates else None
        result['classes'][label] = c
cls = result['classes'].values()
result['totals'] = {k: sum(x[k] for x in cls) for k in (
    'archives_scanned', 'archives_with_env_member', 'env_members', 'members_live_app_key',
    'members_other_app_key', 'members_empty_app_key', 'members_live_db_password',
    'archives_holding_live_key_reachable_by_others')}
result['set_digest'] = hashlib.sha256('\n'.join(sorted(seen)).encode()).hexdigest()[:16]
print(json.dumps(result, indent=2))
```

## `f4-activity-gate.php`

Gate aktivitas klinis/kasir sebelum operasi berisiko. Sesi database dipaksa baca-saja; GATE PASS hanya bila semua sinyal aktif 0.

```php
<?php
/*
 * Pre-deploy activity gate — READ-ONLY. Answers: is anyone doing clinical or
 * cashier work right now? Prints counts only (no names, no patient data).
 *
 *   runuser -u daengtisiams -- php -d display_errors=stderr -- --json < f4-activity-gate.php
 *
 * Forces a read-only database session first and refuses to continue without it.
 * GATE = PASS only when every "active" signal is zero. Backlog rows that have not
 * been touched recently (an old cashier_pending visit) are reported, not counted
 * as active work.
 */

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$asJson = in_array('--json', $argv ?? [], true);
$r = ['probe' => 'f4-activity-gate', 'ok' => false, 'errors' => [], 'active' => [], 'info' => []];

try {
    $root = getcwd();
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $driver = DB::connection()->getDriverName();
    if ($driver === 'pgsql') {
        DB::statement('SET SESSION default_transaction_read_only = on');
        $ro = DB::selectOne('SHOW default_transaction_read_only')->default_transaction_read_only ?? null;
    } else {
        DB::statement('PRAGMA query_only = ON');
        $ro = (string) (DB::selectOne('PRAGMA query_only')->query_only ?? '');
    }
    if (! in_array($ro, ['on', '1'], true)) {
        throw new RuntimeException('could not force a read-only session; refusing to continue');
    }

    $now = Carbon::now('UTC');
    $clinicToday = $app->make(App\Support\Clinical\ClinicalClock::class)->todayString();
    $r['utc_now'] = $now->format('Y-m-d\TH:i:s\Z');
    $r['clinic_today'] = $clinicToday;

    // 1. Authenticated web activity in the last 10 minutes.
    $cut10 = $now->copy()->subMinutes(10)->getTimestamp();
    $r['active']['sessions_active_10m'] = DB::table('sessions')->where('last_activity', '>=', $cut10)->count();
    $r['active']['sessions_authenticated_active_10m'] = DB::table('sessions')->where('last_activity', '>=', $cut10)->whereNotNull('user_id')->count();
    $r['info']['sessions_total'] = DB::table('sessions')->count();

    // 2. Examinations in progress, and visit activity today.
    $r['active']['visits_in_progress'] = DB::table('trx_clinic_visits')->where('status', 'in_progress')->whereNull('deleted_at')->count();
    $r['info']['visits_today_by_status'] = DB::table('trx_clinic_visits')->whereDate('visit_date', $clinicToday)->whereNull('deleted_at')
        ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all();
    $r['info']['cashier_pending_backlog'] = DB::table('trx_clinic_visits')->where('status', 'cashier_pending')->whereNull('deleted_at')->count();

    // 3. Anything created or changed in the last 30 minutes in the clinical/cashier tables.
    $cut30 = $now->copy()->subMinutes(30);
    $tables = ['trx_clinic_visits', 'trx_medical_records', 'trx_odontograms', 'trx_rme_visit_consents', 'trx_rme_invoices',
        'trx_rme_payments', 'trx_rme_payment_submissions', 'trx_inventory_movements', 'trx_lab_orders'];
    foreach ($tables as $t) {
        if (! Schema::hasTable($t)) {
            $r['info']["recent30_{$t}"] = 'absent';

            continue;
        }
        $q = DB::table($t);
        $hasCreated = Schema::hasColumn($t, 'created_at');
        $hasUpdated = Schema::hasColumn($t, 'updated_at');
        $q->where(function ($w) use ($cut30, $hasCreated, $hasUpdated) {
            if ($hasCreated) {
                $w->orWhere('created_at', '>=', $cut30);
            }
            if ($hasUpdated) {
                $w->orWhere('updated_at', '>=', $cut30);
            }
        });
        $r['active']["recent30_{$t}"] = ($hasCreated || $hasUpdated) ? $q->count() : 'no-timestamps';
    }

    // 4. Doctor presence and single-session leases (live sessions only).
    if (Schema::hasTable('trx_user_online_contexts')) {
        $r['info']['online_contexts_status_online'] = DB::table('trx_user_online_contexts')->where('status', 'online')->count();
        if (Schema::hasColumn('trx_user_online_contexts', 'last_seen_at')) {
            $r['active']['online_contexts_seen_10m'] = DB::table('trx_user_online_contexts')->where('status', 'online')
                ->where('last_seen_at', '>=', $now->copy()->subMinutes(10))->count();
        }
    }
    if (Schema::hasTable('trx_doctor_session_leases')) {
        $r['info']['leases_unreleased'] = DB::table('trx_doctor_session_leases')->whereNull('released_at')->count();
    }

    // 5. Queue.
    $r['active']['jobs_pending'] = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 'absent';
    $r['info']['failed_jobs'] = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 'absent';

    $blocking = array_filter($r['active'], fn ($v) => is_int($v) && $v > 0);
    $r['blocking'] = array_keys($blocking);
    $r['gate'] = $blocking === [] ? 'PASS' : 'FAIL';
    $r['ok'] = true;
} catch (Throwable $e) {
    $r['errors'][] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);
    $r['gate'] = 'UNVERIFIED';
}

echo $asJson ? json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL : print_r($r, true);
exit($r['ok'] && $r['gate'] === 'PASS' ? 0 : 1);
```
