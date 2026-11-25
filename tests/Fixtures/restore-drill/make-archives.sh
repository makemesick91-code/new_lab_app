#!/usr/bin/env bash
#
# Regenerates the SYNTHETIC custom-format archives the R6-B6 restore-drill tests use (no real data). Run by hand
# against a throwaway PostgreSQL 16 cluster you started yourself; never against a shared or production cluster.
#
# Usage: make-archives.sh <socket dir> <port> <superuser>
# Writes, next to this script: clean, bomb, rls, ext, weirdname, otherschema, metabody, r6owner, checkgen, dangling,
# launder, execbomb, dbowner, vanish, rolebomb, timebomb, xmlbomb, floatbomb, regbomb, regconst, idxbomb, agebomb, aclbomb, arrnull, pgstat (.dump).
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
# Review round 5 (P5-1): pre-data code that runs, then erases every trace before the guard (renames and recreates its
# functions, and drops the generated column and the originals from a deferred trigger at commit).
base rd_vanish
q -U app_runtime -d rd_vanish <<'SQL'
CREATE FUNCTION public.helper() RETURNS int LANGUAGE plpgsql VOLATILE AS $$
BEGIN
  IF session_user <> 'drill_restore' THEN RETURN 1; END IF;
  EXECUTE 'CREATE VIEW public.p5_proof AS SELECT ' || quote_literal(session_user || '@' || current_database()) || '::text AS ran_as';
  ALTER FUNCTION public.a() RENAME TO a_old;
  ALTER FUNCTION public.helper() RENAME TO helper_old;
  CREATE FUNCTION public.a() RETURNS int LANGUAGE sql IMMUTABLE AS 'SELECT 1';
  CREATE FUNCTION public.helper() RETURNS int LANGUAGE sql VOLATILE AS 'SELECT 1';
  CREATE TEMP TABLE q (x int);
  CREATE FUNCTION pg_temp.fin() RETURNS trigger LANGUAGE plpgsql AS $f$ BEGIN
    ALTER TABLE public.zz_vanish ALTER COLUMN g DROP EXPRESSION; DROP FUNCTION public.a_old(); DROP FUNCTION public.helper_old(); RETURN NULL; END $f$;
  CREATE CONSTRAINT TRIGGER fin AFTER INSERT ON pg_temp.q DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pg_temp.fin();
  INSERT INTO pg_temp.q VALUES (1);
  RETURN 1;
END $$;
CREATE FUNCTION public.a() RETURNS int LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN public.helper(); END $$;
CREATE TABLE public.zz_vanish (id int, g int GENERATED ALWAYS AS (public.a()) STORED);
SQL
dump rd_vanish vanish
# Review round 6 (R6B-1): CHECK constraints that depend on the session or the clock carry no function id; they hold
# where the archive was made and fail in another restore.
base rd_rolebomb
q -U app_runtime -d rd_rolebomb -c "ALTER TABLE items ADD CONSTRAINT items_role CHECK (current_user = ANY (ARRAY['app_runtime'::name, 'drill_restore'::name]))"
dump rd_rolebomb rolebomb
base rd_timebomb
q -U app_runtime -d rd_timebomb -c "ALTER TABLE items ADD CONSTRAINT items_time CHECK (CURRENT_TIMESTAMP < (('2999-01-01 00:00:00'::text || '+00'::text))::timestamptz)"
dump rd_timebomb timebomb
# Fix verification of round 6 (R6F-P1, R6F-P2): IMMUTABLE by label, yet session- or cluster-dependent.
base rd_xmlbomb
q -U app_runtime -d rd_xmlbomb -c "CREATE TABLE stamps (ts timestamptz, CONSTRAINT stamps_tz CHECK ((xmlserialize(content xmlelement(name t, ts) AS text)) LIKE '%+00:00</t>'))" \
  -c "SET TimeZone = 'UTC'" -c "INSERT INTO stamps VALUES ('2026-11-25 10:00:00+08')"
dump rd_xmlbomb xmlbomb
base rd_floatbomb
q -U app_runtime -d rd_floatbomb -c "CREATE TABLE floats (f float8, CONSTRAINT floats_text CHECK ((f)::text = '0.30000000000000004'))" \
  -c "INSERT INTO floats VALUES (0.1::float8 + 0.2::float8)"
dump rd_floatbomb floatbomb
base rd_regbomb
q -U app_runtime -d rd_regbomb -c "CREATE TABLE reg (r regclass)" -c "INSERT INTO reg VALUES ('public.items')"
dump rd_regbomb regbomb
base rd_regconst
q -U app_runtime -d rd_regconst -c "ALTER TABLE items ADD CONSTRAINT items_oid CHECK (id < ('public.items'::regclass)::integer)"
dump rd_regconst regconst
base rd_idxbomb
q -U app_runtime -d rd_idxbomb -c "CREATE INDEX items_ftext ON items (((id::double precision / 3)::text))"
dump rd_idxbomb idxbomb
# Second fix verification (R6F-P5, R6F-P6): age() reads TimeZone; ACL items hold cluster-assigned role ids.
base rd_agebomb
q -U app_runtime -d rd_agebomb -c "CREATE TABLE subs (starts_at timestamptz, ends_at timestamptz, CONSTRAINT subs_month CHECK (age(ends_at, starts_at) < interval '1 mon'))" \
  -c "SET TimeZone = 'UTC'" -c "INSERT INTO subs VALUES ('2026-01-31 17:00+00', '2026-02-28 17:00+00')"
dump rd_agebomb agebomb
base rd_aclbomb
q -U app_runtime -d rd_aclbomb -c "CREATE TABLE grants (acl aclitem)" -c "INSERT INTO grants VALUES ('app_runtime=r/app_runtime')"
dump rd_aclbomb aclbomb
# F3 (Restore Environment Compatibility Contract): an honest array with a NULL element. It loads only where the
# restore session has array_nulls=on: a contract pinning production's array_nulls=off makes the drill FAIL exactly as
# the real restore would.
base rd_arrnull
q -U app_runtime -d rd_arrnull -c "CREATE TABLE tags (id int PRIMARY KEY, codes int[])" -c "INSERT INTO tags VALUES (1, ARRAY[1, NULL, 3]), (2, ARRAY[4])"
dump rd_arrnull arrnull
# R6-B8: production's only extension, created by the superuser as in production (it is not a trusted extension). The
# production-compatible drill image allowlists exactly this extension; every other one stays refused (ext.dump).
base rd_pgstat
q -U "$SU" -d rd_pgstat -c "CREATE EXTENSION pg_stat_statements"
dump rd_pgstat pgstat
for d in rd_clean rd_bomb rd_rls rd_ext rd_weird rd_schema rd_meta rd_r6 rd_checkgen rd_dangling rd_launder rd_execbomb rd_dbowner rd_vanish rd_rolebomb rd_timebomb rd_xmlbomb rd_floatbomb rd_regbomb rd_regconst rd_idxbomb rd_agebomb rd_aclbomb rd_arrnull rd_pgstat; do q -U "$SU" -d postgres -c "DROP DATABASE $d"; done
# Text forms for the parser tests (no PostgreSQL binary needed to run them): the TOC listing without its timestamped
# header, and the data section as pg_restore emits it.
for f in "$HERE"/*.dump; do
  "$B/pg_restore" -l "$f" | grep -v '^; Archive created at ' | grep -v '^;     dbname: ' > "${f%.dump}.toc"
  "$B/pg_restore" --section=data --restrict-key=fixture0 -f - "$f" > "${f%.dump}.data"
done
sha256sum "$HERE"/*.dump | sed "s#$HERE/##" > "$HERE/SHA256SUMS"
