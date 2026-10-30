---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# G7-B — Sumber Tool Gate Keamanan (2026-10-30)

Sumber lengkap tool yang dijalankan untuk [G7B-SECURITY-GATE-CLOSURE](G7B-SECURITY-GATE-CLOSURE.md) dan
[G7B-ENV-COPY-INVENTORY](G7B-ENV-COPY-INVENTORY.md), agar hasilnya bisa diulang dan diaudit. Kembali ke
[00-INDEX](../../00-INDEX.md). Tool escrow ada di
[G7B-ESCROW-TOOLS](../release-cicd-phase2-2026-10-29/G7B-ESCROW-TOOLS.md).

Tool ini **tidak** dipasang di host. Semuanya dikirim lewat stdin SSH dan dijalankan sebagai root:
`ssh <host> 'python3 -I -' < tool.py` atau `ssh <host> 'bash -s' < tool.sh`. Tidak satu pun mencetak key,
identitas key, password, path salinan, atau nama file.

| Tool | sha256 | Menulis apa |
|---|---|---|
| `g7b-confidential-inventory.py` | `a56c27794a9bdccb61b9fc7b275c51b5bbe28c9e67ff044f2bdac1f59a7d8639` | Satu manifest root-only (0600) di direktori 0700 |
| `g7b-manifest-summary.py` | `0526067351263e217d6a57fc52852bf640c96278f809130714d9f73fee327d01` | Tidak menulis apa pun |
| `g7b-digest.py` | `5b22fb69c859cd8ff298918160af175d74a69695608c95ab51ac612bbbbeb87e` | Tidak menulis apa pun |
| `g7b-isolated-rehearsal.sh` | `508892bdd7d3d95a8e0035791248862fe2aee85ea84155053b8d5ae1f308adfe` | Database sekali pakai, dihapus di setiap jalur keluar |

## `g7b-confidential-inventory.py`

```python
#!/usr/bin/env python3
"""G7-B confidential per-copy inventory — READ-ONLY on application data. Run as root on the VPS:

    python3 -I - < g7b-confidential-inventory.py

Extends g7b-env-copy-inventory.py / g7b-env-archive-scan.py with:
  * one row per copy under an OPAQUE id (C.. plain file, A.. archive member);
  * "is it active?" — referenced by any runtime config, or held open by any process;
  * "does encrypted data depend on it?" — every database dump on the host is scanned for
    Laravel ciphertext and each payload's MAC is checked, in memory, against the live key and
    every distinct non-live key found in the copies;
  * a retention PROPOSAL per copy (approval status is always PENDING — only the owner approves).

Writes exactly ONE file: a root-only manifest binding opaque ids to paths, at
/root/security-g7b-inventory-<UTC date>/manifest.json (dir 0700, file 0600). Prints opaque ids,
classes, counts, booleans and digests only — never a key, a password, a filename or a path.
"""
import base64
import datetime
import gzip
import hashlib
import hmac
import io
import json
import os
import re
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
DUMP_SUFFIXES = ('.sql', '.sql.gz')
MAX_ARCHIVE = 1024 * 1024 * 1024
MAX_MEMBER = 256 * 1024
MAX_DUMP = 4 * 1024 * 1024 * 1024
CONFIG_ROOTS = ['/etc/systemd/system', '/lib/systemd/system', '/etc/nginx', '/etc/php', '/etc/cron.d',
                '/etc/cron.daily', '/etc/cron.hourly', '/etc/cron.weekly', '/etc/cron.monthly',
                '/etc/crontab', '/var/spool/cron/crontabs', '/etc/supervisor']
PAYLOAD = re.compile(rb'eyJpdiI6[A-Za-z0-9+/]+=*')


def parse_text(text):
    out = {}
    for line in text.splitlines():
        if '=' not in line or line.lstrip().startswith('#'):
            continue
        k, v = line.split('=', 1)
        k, v = k.strip(), v.strip()
        if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
            v = v[1:-1]
        if k in ('APP_KEY', 'APP_PREVIOUS_KEYS', 'DB_PASSWORD') and k not in out:
            out[k] = v
    return out


def key_bytes(v):
    if not v:
        return None
    if v.startswith('base64:'):
        try:
            return base64.b64decode(v[7:], validate=True)
        except Exception:
            return None
    return v.encode()


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
            if stat.S_ISREG(st.st_mode):
                yield p, st


with open(LIVE, 'r', errors='replace') as fh:
    live = parse_text(fh.read())
live_key = live.get('APP_KEY', '')
live_real = os.path.realpath(LIVE)

# ---- runtime references: every config text that names a path, and every open fd ----------
config_blob = []
for c in CONFIG_ROOTS:
    if os.path.isfile(c):
        files = [c]
    elif os.path.isdir(c):
        files = [os.path.join(d, f) for d, _, fs in os.walk(c) for f in fs]
    else:
        files = []
    for f in files:
        try:
            if os.path.getsize(f) <= 1024 * 1024:
                with open(f, 'r', errors='replace') as fh:
                    config_blob.append(fh.read())
        except OSError:
            pass
config_text = '\n'.join(config_blob)
open_targets = set()
for pid in os.listdir('/proc'):
    if not pid.isdigit():
        continue
    fd_dir = os.path.join('/proc', pid, 'fd')
    try:
        for fd in os.listdir(fd_dir):
            try:
                open_targets.add(os.readlink(os.path.join(fd_dir, fd)))
            except OSError:
                pass
    except OSError:
        pass

# ---- collect copies -------------------------------------------------------------------------
keys = {}            # key value -> label (LIVE / OTHER-n), values stay in memory only
def key_label(v):
    if not v:
        return 'EMPTY'
    if v == live_key:
        return 'LIVE'
    if v not in keys:
        keys[v] = 'OTHER-%d' % (len(keys) + 1)
    return keys[v]


plain, members, archives_scanned, archive_errors = [], [], 0, 0
seen = set()
for label, root, depth in ROOTS:
    for p, st in walk(root, depth):
        rp = os.path.realpath(p)
        if rp in seen:
            continue
        name = os.path.basename(p)
        if st.st_size <= 262144 and rp != live_real and not name.endswith(ARCHIVE_SUFFIXES):
            try:
                with open(p, 'r', errors='replace') as fh:
                    vals = parse_text(fh.read())
            except OSError:
                vals = None
            if vals and 'APP_KEY' in vals:
                seen.add(rp)
                plain.append({'path': rp, 'class': label, 'st': st, 'key': key_label(vals.get('APP_KEY')),
                              'live_db_password': bool(vals.get('DB_PASSWORD')) and vals.get('DB_PASSWORD') == live.get('DB_PASSWORD'),
                              'template_like': name.endswith('.example')})
            continue
        if name.endswith(ARCHIVE_SUFFIXES) and st.st_size <= MAX_ARCHIVE:
            seen.add(rp)
            archives_scanned += 1
            try:
                found = []
                if name.endswith('.zip'):
                    with zipfile.ZipFile(p) as z:
                        for info in z.infolist():
                            b = os.path.basename(info.filename.rstrip('/'))
                            if b.startswith('.env') and info.file_size <= MAX_MEMBER:
                                found.append((info.filename, z.read(info).decode('utf-8', 'replace')))
                else:
                    with tarfile.open(p) as t:
                        for m in t:
                            b = os.path.basename(m.name.rstrip('/'))
                            if m.isreg() and b.startswith('.env') and m.size <= MAX_MEMBER:
                                f = t.extractfile(m)
                                found.append((m.name, f.read().decode('utf-8', 'replace') if f else ''))
                for mname, text in found:
                    vals = parse_text(text)
                    if 'APP_KEY' in vals:
                        members.append({'path': rp, 'member': mname, 'class': label, 'st': st,
                                        'key': key_label(vals.get('APP_KEY')),
                                        'live_db_password': bool(vals.get('DB_PASSWORD')) and vals.get('DB_PASSWORD') == live.get('DB_PASSWORD'),
                                        'template_like': mname.endswith('.example')})
            except Exception:
                archive_errors += 1

# ---- database dumps: which key does each ciphertext need? -------------------------------------
cand = {'LIVE': key_bytes(live_key)}
for v, lab in keys.items():
    cand[lab] = key_bytes(v)
cand = {k: v for k, v in cand.items() if v}


def which_key(token):
    try:
        obj = json.loads(base64.b64decode(token))
        iv, value, mac = obj['iv'], obj['value'], obj['mac']
    except Exception:
        return 'UNPARSEABLE'
    msg = (iv + value).encode()
    for lab, kb in cand.items():
        if hmac.compare_digest(hmac.new(kb, msg, hashlib.sha256).hexdigest(), mac):
            return lab
    return 'NO_KNOWN_KEY'


dumps = []
for label, root, depth in ROOTS:
    for p, st in walk(root, max(depth, 3)):
        name = os.path.basename(p)
        if not name.endswith(DUMP_SUFFIXES) or st.st_size > MAX_DUMP:
            continue
        counts, err = {}, None
        try:
            opener = gzip.open if name.endswith('.gz') else open
            with opener(p, 'rb') as fh:
                for line in fh:
                    for tok in PAYLOAD.findall(line):
                        k = which_key(tok)
                        counts[k] = counts.get(k, 0) + 1
        except Exception as e:
            err = type(e).__name__
        dumps.append({'class': label, 'month': datetime.datetime.fromtimestamp(st.st_mtime, datetime.timezone.utc).strftime('%Y-%m'),
                      'counts': counts, 'error': err, 'path': os.path.realpath(p)})
other_dumps = sum(1 for d in dumps if any(k.startswith('OTHER') for k in d['counts']))
dump_keys_needed = sorted({k for d in dumps for k in d['counts']})


# ---- rows -------------------------------------------------------------------------------------
def proposal(key):
    if key == 'LIVE':
        return ('not needed after both escrow copies pass recovery-check',
                'DISPOSE in the rotation window, after escrow verified (per-path, root-only manifest)')
    if key.startswith('OTHER'):
        needed = any(key in d['counts'] for d in dumps)
        return (('REQUIRED to read %s ciphertext in an on-host database dump' % key) if needed else
                'no on-host database dump and no live row is encrypted under this key',
                'RETAIN under sealed custody' if needed else 'DISPOSE or seal — owner decision (not urgent)')
    return ('no key material', 'DISPOSE or keep as template — owner decision (no secret)')


def row(i, r, kind):
    st = r['st']
    rel, prop = proposal(r['key'])
    return {
        'id': i, 'kind': kind, 'location_class': r['class'],
        'owner_uid': st.st_uid, 'mode': oct(st.st_mode & 0o777)[2:],
        'parent_mode': oct(os.stat(os.path.dirname(r['path'])).st_mode & 0o777)[2:],
        'month': datetime.datetime.fromtimestamp(st.st_mtime, datetime.timezone.utc).strftime('%Y-%m'),
        'key_state': r['key'], 'holds_live_db_password': r['live_db_password'],
        'template_like': r['template_like'],
        'reachable_by_other_accounts': bool(st.st_mode & 0o004) and traversable(r['path']),
        'active_runtime_reference': (r['path'] in config_text) or (r['path'] in open_targets),
        'live_data_depends_on_it': False,  # live rows: verified separately by the reencrypt dry run
        'recovery_relevance': rel, 'proposed_retention': prop, 'approval_status': 'PENDING_OWNER',
    }


plain.sort(key=lambda r: (r['class'], r['path']))
members.sort(key=lambda r: (r['class'], r['path'], r['member']))
rows = [row('C%02d' % (n + 1), r, 'plain_file') for n, r in enumerate(plain)]
rows += [row('A%02d' % (n + 1), r, 'archive_member') for n, r in enumerate(members)]
mapping = {('C%02d' % (n + 1)): r['path'] for n, r in enumerate(plain)}
mapping.update({('A%02d' % (n + 1)): r['path'] + '::' + r['member'] for n, r in enumerate(members)})

now = datetime.datetime.now(datetime.timezone.utc)
outdir = '/root/security-g7b-inventory-' + now.strftime('%Y%m%d')
os.makedirs(outdir, mode=0o700, exist_ok=True)
os.chmod(outdir, 0o700)
manifest = {'generated_at_utc': now.strftime('%Y-%m-%dT%H:%M:%SZ'),
            'live_key_identity': hashlib.sha256(b'dms-g7b-key-identity|' + live_key.encode()).hexdigest()[:16],
            'rows': rows, 'id_to_path': mapping,
            'dumps': [{k: d[k] for k in ('class', 'month', 'counts', 'error', 'path')} for d in dumps]}
mpath = os.path.join(outdir, 'manifest.json')
fd = os.open(mpath, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w') as fh:
    json.dump(manifest, fh, indent=2, sort_keys=True)
os.chmod(mpath, 0o600)
with open(mpath, 'rb') as fh:
    msha = hashlib.sha256(fh.read()).hexdigest()

print(json.dumps({
    'generated_at_utc': manifest['generated_at_utc'],
    'live_has_app_key': bool(live_key), 'live_previous_keys_configured': bool(live.get('APP_PREVIOUS_KEYS')),
    'distinct_non_live_keys': len(keys),
    'plain_files_with_key_line': len(plain), 'archive_members_with_key_line': len(members),
    'archives_scanned': archives_scanned, 'archive_errors': archive_errors,
    'key_state_totals': {s: sum(1 for r in rows if r['key_state'] == s) for s in sorted({r['key_state'] for r in rows})},
    'reachable_by_other_accounts': sum(r['reachable_by_other_accounts'] for r in rows),
    'active_runtime_reference': sum(r['active_runtime_reference'] for r in rows),
    'holds_live_db_password': sum(r['holds_live_db_password'] for r in rows),
    'plain_set_digest': hashlib.sha256('\n'.join(sorted(r['path'] for r in plain)).encode()).hexdigest()[:16],
    'dumps_scanned': len(dumps), 'dump_errors': sum(1 for d in dumps if d['error']),
    'dumps_with_ciphertext': sum(1 for d in dumps if d['counts']),
    'dumps_needing_a_non_live_key': other_dumps, 'dump_key_labels_seen': dump_keys_needed,
    'dump_classes': sorted({(d['class'], d['month'], json.dumps(d['counts'], sort_keys=True)) for d in dumps}),
    'manifest_sha256_16': msha[:16], 'manifest_mode': oct(os.stat(mpath).st_mode & 0o777)[2:],
    'rows': [{k: v for k, v in r.items()} for r in rows],
}, indent=1, default=list))
```

## `g7b-manifest-summary.py`

```python
import json, hashlib, collections
p='/root/security-g7b-inventory-20261030/manifest.json'
raw=open(p,'rb').read(); m=json.loads(raw)
rows=m['rows']
out={'generated_at_utc':m['generated_at_utc'],'manifest_sha256_16':hashlib.sha256(raw).hexdigest()[:16],
 'rows_total':len(rows),
 'by_kind_keystate':collections.Counter((r['kind'],r['key_state']) for r in rows).most_common(),
 'by_class_keystate':sorted(collections.Counter((r['location_class'],r['kind'],r['key_state']) for r in rows).items()),
 'reachable':sum(r['reachable_by_other_accounts'] for r in rows),
 'active_ref':sum(r['active_runtime_reference'] for r in rows),
 'live_db_pw':sum(r['holds_live_db_password'] for r in rows),
 'dumps_total':len(m['dumps']),'dump_errors':sum(1 for d in m['dumps'] if d['error']),
 'dump_summary':sorted(collections.Counter((d['class'],d['month'],json.dumps(d['counts'],sort_keys=True),d['error']) for d in m['dumps']).items()),
 'rows':[{k:r[k] for k in ('id','kind','location_class','owner_uid','mode','parent_mode','month','key_state','reachable_by_other_accounts','active_runtime_reference','holds_live_db_password','template_like','proposed_retention','recovery_relevance')} for r in rows]}
print(json.dumps(out,indent=0))
```

## `g7b-digest.py`

```python
import json,hashlib
m=json.load(open('/root/security-g7b-inventory-20261030/manifest.json'))
plain=sorted(p for i,p in m['id_to_path'].items() if i.startswith('C'))
arch=sorted({p.split('::')[0] for i,p in m['id_to_path'].items() if i.startswith('A')})
print('plain_set_digest', hashlib.sha256('\n'.join(plain).encode()).hexdigest()[:16], len(plain))
print('archive_member_count', sum(1 for i in m['id_to_path'] if i.startswith('A')), 'archives_with_env_members', len(arch))
```

## `g7b-isolated-rehearsal.sh`

```bash
#!/usr/bin/env bash
# G7-B isolated rotation rehearsal on production-representative data.
# Run on the VPS as root, AFTER the release carrying security:app-key-reencrypt is deployed:
#   bash -s < g7b-isolated-rehearsal.sh
#
# What it does, all against a DISPOSABLE copy of the database restored from the newest pre-deploy
# backup; the production database, its configuration and its key are never written:
#   A0  new key only, no previous key   -> every encrypted value is undecryptable (a pre-rotation backup
#                                          cannot be read once the old key is gone, unless it is escrowed)
#   A   new key current, old key previous -> values classified "previous", none undecryptable
#   B   --apply on the copy              -> every previous value rewritten under the new key
#   C   new key only                     -> every value reads with the new key alone (old key removable)
#   D   old key only                     -> every value now undecryptable (the rewrite really happened)
#   R   rollback: old current, new previous, --apply -> values move back; then old key alone reads all
#
# Secrets: the live key is read from the environment file into a shell variable, a fresh key is
# generated in memory, and both reach artisan ONLY through the process environment (never argv, which
# other local accounts can read from /proc). Nothing prints a key, a ciphertext or a plaintext value.
# The copy is dropped on every exit path.
set -euo pipefail
set +x
APP_DIR=/var/www/asia-dental-lab-v2
cd "$APP_DIR"

val() { sed -n "s/^$1=//p" .env | head -1 | sed -e "s/^[\"']//" -e "s/[\"']\s*$//" -e "s/\s*$//"; }

runuser -u daengtisiams -- php artisan list --raw | grep -q '^security:app-key-reencrypt ' \
  || { echo "ABORT: security:app-key-reencrypt is not registered on the deployed release"; exit 2; }

DUMP=$(ls -1t storage/app/backups/deploy/pre_auto_deploy_*.sql | head -1)
echo "source backup: $(basename "$DUMP") ($(stat -c %s "$DUMP") bytes, $(stat -c %y "$DUMP" | cut -c1-19) UTC)"
runuser -u daengtisiams -- php artisan foundation:backup-verify --path="$DUMP" | grep -iE "decision" | head -1

PROD_DB=$(val DB_DATABASE); ROLE=$(val DB_USERNAME)
SCRATCH="dms_g7b_rehearsal_$(date -u +%Y%m%d%H%M%S)"
[ -n "$PROD_DB" ] && [ -n "$ROLE" ] && [ "$SCRATCH" != "$PROD_DB" ] || { echo "ABORT: database identity unresolved"; exit 2; }

cleanup() {
  unset OLD_KEY NEW_KEY APP_KEY APP_PREVIOUS_KEYS DB_DATABASE APP_CONFIG_CACHE || true
  runuser -u postgres -- dropdb --if-exists "$SCRATCH" >/dev/null 2>&1 || true
  echo "cleanup: disposable database dropped ($(runuser -u postgres -- psql -tAc "select count(*) from pg_database where datname like 'dms_g7b_rehearsal_%'") rehearsal databases remain)"
}
trap cleanup EXIT

runuser -u postgres -- createdb -O "$ROLE" "$SCRATCH"
T0=$(date +%s)
runuser -u postgres -- psql -q -v ON_ERROR_STOP=1 --single-transaction -d "$SCRATCH" < "$DUMP" >/dev/null
echo "restored into disposable database in $(( $(date +%s) - T0 )) s: $(runuser -u postgres -- psql -tAc "select count(*) from information_schema.tables where table_schema='public'" -d "$SCRATCH") tables"

OLD_KEY=$(val APP_KEY)
NEW_KEY="base64:$(head -c 32 /dev/urandom | base64 -w0)"
[ -n "$OLD_KEY" ] && [ "$OLD_KEY" != "$NEW_KEY" ] || { echo "ABORT: key material unresolved"; exit 2; }

# Run artisan against the copy. APP_CONFIG_CACHE points at a file that does not exist, so the process
# builds its configuration from the environment instead of the production cache; the exported
# variables win over the environment file (immutable dotenv repository).
art() { # $1 current key, $2 previous key ('' for none), rest: artisan arguments
  local cur="$1" prev="$2"; shift 2
  APP_CONFIG_CACHE=/nonexistent/g7b-rehearsal-config.php DB_DATABASE="$SCRATCH" APP_KEY="$cur" APP_PREVIOUS_KEYS="$prev" HOME=/tmp \
    runuser -p -u daengtisiams -- php artisan "$@"
}
report() { # summarise a --json report, counts only
  python3 -I -c 'import json,sys
d=json.load(sys.stdin); t=d.get("totals") or {}
a=["decision","mode","previous_keys_configured","rewritten","previous_keys_removable"]
b=["total","current","previous","undecryptable"]
print(" ".join(f"{x}={d.get(x)}" for x in a), " ".join(f"{x}={t.get(x)}" for x in b))'
}

# Guard 1: the process must be connected to the disposable copy (plain-text line only; the JSON form
# of db:show carries the connection config, which can include the password).
CONNECTED=$(art "$NEW_KEY" "$OLD_KEY" db:show 2>/dev/null | grep -E '^\s*Database\s' | awk '{print $NF}' || true)
[ "$CONNECTED" = "$SCRATCH" ] || { echo "ABORT: process is not connected to the disposable copy"; exit 3; }
echo "guard 1: process connected to the disposable copy"

echo "A0 new key only:            $(art "$NEW_KEY" "" security:app-key-reencrypt --json 2>/dev/null | report || true)"
A=$(art "$NEW_KEY" "$OLD_KEY" security:app-key-reencrypt --json 2>/dev/null | report)
echo "A  new current, old previous: $A"
# Guard 2: the overrides took effect (one previous key configured and nothing still on the "current"
# side). If the production cache had been used instead, previous_keys would be 0.
echo "$A" | grep -qE 'previous_keys_configured=1( |$)' && echo "$A" | grep -qE 'undecryptable=0( |$)' \
  || { echo "ABORT: overrides not in effect or values undecryptable; no write performed"; exit 3; }
echo "guard 2: overrides in effect"

echo "B  apply on the copy:        $(art "$NEW_KEY" "$OLD_KEY" security:app-key-reencrypt --apply --json 2>/dev/null | report)"
echo "C  new key only:            $(art "$NEW_KEY" "" security:app-key-reencrypt --json 2>/dev/null | report || true)"
echo "D  old key only:            $(art "$OLD_KEY" "" security:app-key-reencrypt --json 2>/dev/null | report || true)"
echo "R1 rollback apply:          $(art "$OLD_KEY" "$NEW_KEY" security:app-key-reencrypt --apply --json 2>/dev/null | report)"
echo "R2 old key only after rollback: $(art "$OLD_KEY" "" security:app-key-reencrypt --json 2>/dev/null | report || true)"
echo "audit rows written to the copy: $(runuser -u postgres -- psql -tAc "select count(*) from sys_audit_logs where action='APP_KEY_VALUES_REENCRYPTED'" -d "$SCRATCH")"
echo "production database untouched: rehearsal ran only against $SCRATCH"
```
