#!/usr/bin/env bash
#
# Regenerates the SYNTHETIC custom-format archives the R6-B6 restore-drill tests use (no real data). Run by hand
# against a throwaway PostgreSQL 16 cluster you started yourself; never against a shared or production cluster.
#
# Usage: make-archives.sh <socket dir> <port> <superuser>
# Writes, next to this script: clean, bomb, rls, ext, weirdname, otherschema, metabody, r6owner, checkgen, dangling,
# launder, execbomb, dbowner (.dump).
set -euo pipefail
SOCK="${1:?socket dir}"; PORT="${2:?port}"; SU="${3:?superuser}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
B=/usr/lib/postgresql/16/bin
q() { "$B/psql" -X -q -v ON_ERROR_STOP=1 -h "$SOCK" -p "$PORT" "$@"; }
q -U "$SU" -d postgres -c "DO \$\$ BEGIN CREATE ROLE app_runtime LOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$" \
  -c "DO \$\$ BEGIN CREATE ROLE dms_owner LOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$"
base() { # <db>: the clean synthetic schema, owned by the runtime role
  q -U "$SU" -d postgres -c "DROP DATABASE IF EXISTS $1" -c "CREATE DATABASE $1"
  q -U "$SU" -d "$1" -c "GRANT CREATE ON SCHEMA public TO app_runtime" -c "GRANT CREATE ON DATABASE $1 TO app_runtime"
  q -U app_runtime -d "$1" <<'SQL'
CREATE TABLE migrations (id serial PRIMARY KEY, migration text NOT NULL);
INSERT INTO migrations (migration) VALUES ('synthetic_one'), ('synthetic_two');
CREATE TABLE items (id int PRIMARY KEY, label text NOT NULL CHECK (length(label) < 200));
INSERT INTO items VALUES (1, 'COPY x FROM stdin;'), (2, E'line\nbreak'), (3, '\.'), (4, '-- Name: x; Type: TABLE');
CREATE FUNCTION synthetic_fn() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$;
SQL
}
dump() { "$B/pg_dump" -h "$SOCK" -p "$PORT" -U "${3:-app_runtime}" -d "$1" -Fc -f "$HERE/$2.dump"; }
base rd_clean; dump rd_clean clean
base rd_bomb
q -U app_runtime -d rd_bomb <<'SQL'
CREATE FUNCTION zz_norm(t text) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT lower(t) $$;
CREATE INDEX items_norm ON items USING btree (zz_norm(label));
SQL
dump rd_bomb bomb
base rd_rls
q -U app_runtime -d rd_rls <<'SQL'
CREATE FUNCTION p_hook() RETURNS boolean LANGUAGE plpgsql AS $$ BEGIN INSERT INTO migrations (migration) VALUES ('planted'); RETURN true; END $$;
ALTER TABLE items ENABLE ROW LEVEL SECURITY;
ALTER TABLE items FORCE ROW LEVEL SECURITY;
CREATE POLICY p ON items USING (p_hook());
SQL
dump rd_rls rls "$SU" # FORCE ROW LEVEL SECURITY: only a role that bypasses RLS can dump the rows
base rd_ext
q -U app_runtime -d rd_ext -c "CREATE EXTENSION pgcrypto"
dump rd_ext ext
base rd_weird
q -U app_runtime -d rd_weird -c "CREATE TABLE \"we
ird\" (id int)" -c "INSERT INTO \"we
ird\" VALUES (1)"
dump rd_weird weirdname
base rd_schema
q -U "$SU" -d rd_schema -c "CREATE SCHEMA other AUTHORIZATION app_runtime"
q -U app_runtime -d rd_schema -c "CREATE TABLE other.t (id int)" -c "INSERT INTO other.t VALUES (1)"
dump rd_schema otherschema
base rd_meta
q -U app_runtime -d rd_meta <<'SQL'
CREATE FUNCTION meta_body() RETURNS text LANGUAGE sql AS $$ SELECT '
\! touch /srv/pwned
' $$;
SQL
dump rd_meta metabody
base rd_r6
q -U "$SU" -d rd_r6 -c "GRANT CREATE ON SCHEMA public TO dms_owner"
q -U dms_owner -d rd_r6 -c "CREATE TABLE owned_by_owner (id int)"
dump rd_r6 r6owner "$SU" # objects of two owners: only a role that can read both dumps them
# Review round 3 (P4V-1): a CHECK constraint and a generated column call user code during the data load.
base rd_checkgen
q -U app_runtime -d rd_checkgen <<'SQL'
CREATE FUNCTION chk_fn(x int) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN x > 0; END $$;
CREATE FUNCTION gen_fn(x int) RETURNS int LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN x * 2; END $$;
CREATE TABLE guarded (x int CHECK (chk_fn(x)), y int GENERATED ALWAYS AS (gen_fn(x)) STORED);
INSERT INTO guarded (x) VALUES (1), (2);
SQL
dump rd_checkgen checkgen
# Review round 3 (P4P-1): CREATE TABLE folds an immutable generated-column call, so user code runs in pre-data, before
# the load guard; in the drill it drops the function it was called from, leaving a dangling reference.
base rd_dangling
q -U app_runtime -d rd_dangling <<'SQL'
CREATE FUNCTION g() RETURNS int LANGUAGE plpgsql VOLATILE AS $$ BEGIN IF SESSION_USER = 'drill_restore' THEN EXECUTE 'DROP FUNCTION public.f()'; END IF; RETURN 1; END $$;
CREATE FUNCTION f() RETURNS int LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN public.g(); END $$;
CREATE TABLE zz_gen (id int, x int GENERATED ALWAYS AS (public.f()) STORED);
SQL
dump rd_dangling dangling
# Review round 3 (P4P-1(b)): pre-data code adds its own function to an allowlisted extension, to pass as extension code.
base rd_launder
q -U app_runtime -d rd_launder <<'SQL'
CREATE EXTENSION pgcrypto;
CREATE FUNCTION f(t text) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT lower(t) $$;
CREATE FUNCTION g() RETURNS int LANGUAGE plpgsql VOLATILE AS $$ BEGIN IF SESSION_USER = 'drill_restore' THEN EXECUTE 'ALTER EXTENSION pgcrypto ADD FUNCTION public.f(text)'; END IF; RETURN 1; END $$;
CREATE FUNCTION h() RETURNS int LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN public.g(); END $$;
CREATE TABLE zz_gen (id int, x int GENERATED ALWAYS AS (public.h()) STORED);
CREATE INDEX items_f ON items (public.f(label));
SQL
dump rd_launder launder
# Review round 3 (P4P-1): a PL/pgSQL index expression runs once per row while the index is built (post-data).
base rd_execbomb
q -U app_runtime -d rd_execbomb <<'SQL'
CREATE FUNCTION zz_norm_pl(t text) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN lower(t); END $$;
CREATE INDEX items_norm_pl ON items USING btree (zz_norm_pl(label));
SQL
dump rd_execbomb execbomb
# Review round 3 (P4P-3): objects owned by pg_database_owner, which a real restore hands to the target database owner.
base rd_dbowner
q -U "$SU" -d rd_dbowner -c "ALTER TABLE items OWNER TO pg_database_owner"
dump rd_dbowner dbowner "$SU"
for d in rd_clean rd_bomb rd_rls rd_ext rd_weird rd_schema rd_meta rd_r6 rd_checkgen rd_dangling rd_launder rd_execbomb rd_dbowner; do q -U "$SU" -d postgres -c "DROP DATABASE $d"; done
# Text forms for the parser tests (no PostgreSQL binary needed to run them): the TOC listing without its timestamped
# header, and the data section as pg_restore emits it.
for f in "$HERE"/*.dump; do
  "$B/pg_restore" -l "$f" | grep -v '^; Archive created at ' | grep -v '^;     dbname: ' > "${f%.dump}.toc"
  "$B/pg_restore" --section=data --restrict-key=fixture0 -f - "$f" > "${f%.dump}.data"
done
sha256sum "$HERE"/*.dump | sed "s#$HERE/##" > "$HERE/SHA256SUMS"
