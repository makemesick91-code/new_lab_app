<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B3 (rule 245 #6; owner decision D-Q3-b; Design Lock
| §12.5 and §13.4 N-9): foundation:db-privilege-apply (per-deploy runtime grants,
| dry run by default, --print-sql, --apply on pgsql_migrate only) and the
| read-only foundation:db-privilege-check.
|
| Group A runs everywhere: the SQL the plan prints, its determinism, its exact
| rollback, every refusal, and (on PostgreSQL) the dry run and --print-sql.
|
| Group B needs the private PostgreSQL 16 role-test cluster (R6_ROLE_TEST_*,
| plus R6_ROLE_TEST_SUPERUSER for the drift injections). No CI job provides it
| until PB-7, so in CI group B SKIPS and is never reported as a pass.
|
| R6-B3c (pack 63c §1): the check also compares the runtime's exact privilege
| surface with the expected set (the pinned PostgreSQL 16 baseline fixture plus
| the registry and the layout). Group A pins the fixture and the comparison;
| group B regenerates the fixture byte for byte from a fresh database and shows
| that privilege kinds no R6-B3 rule enumerates are extra items.
*/

use App\Support\Database\DatabasePrivilegeCheck;
use App\Support\Database\RuntimeGrantPlan;
use App\Support\Database\RuntimePrivilegeClass;
use App\Support\Database\RuntimePrivilegeRegistry;
use App\Support\Database\RuntimePrivilegeSurface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function fgdpRoles(?string $runtime = 'fgdp_runtime', ?string $owner = 'fgdp_owner'): void
{
    config(['runtime_privilege_classes.roles' => ['runtime' => $runtime, 'owner' => $owner]]);
}

/** @return array{0: int, 1: string} exit code and output */
function fgdpRun(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

function fgdpEntries(): array
{
    return [
        'zz_full' => ['class' => RuntimePrivilegeClass::FULL_DML, 'columns' => []],
        'aa_ledger' => ['class' => RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, 'columns' => ['updated_at', 'batch_id']],
        'mm_append' => ['class' => RuntimePrivilegeClass::APPEND_ONLY, 'columns' => []],
        'migrations' => ['class' => RuntimePrivilegeClass::READ_ONLY_RUNTIME, 'columns' => []],
    ];
}

// ---------------------------------------------------------------- Group A

it('prints exactly the class grants, sorted, quoted and table by table', function () {
    expect(RuntimeGrantPlan::forwardStatements(fgdpEntries(), ['zz_full_id_seq', 'aa_ledger_id_seq'], 'fgdp_runtime'))->toBe([
        'REVOKE ALL ON TABLE public."aa_ledger" FROM "fgdp_runtime"',
        'GRANT SELECT, INSERT ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'GRANT UPDATE ("batch_id", "updated_at") ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."migrations" FROM "fgdp_runtime"',
        'GRANT SELECT ON TABLE public."migrations" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."mm_append" FROM "fgdp_runtime"',
        'GRANT SELECT, INSERT ON TABLE public."mm_append" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."zz_full" FROM "fgdp_runtime"',
        'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public."zz_full" TO "fgdp_runtime"',
        'REVOKE ALL ON SEQUENCE public."aa_ledger_id_seq" FROM "fgdp_runtime"',
        'GRANT USAGE, SELECT ON SEQUENCE public."aa_ledger_id_seq" TO "fgdp_runtime"',
        'REVOKE ALL ON SEQUENCE public."zz_full_id_seq" FROM "fgdp_runtime"',
        'GRANT USAGE, SELECT ON SEQUENCE public."zz_full_id_seq" TO "fgdp_runtime"',
    ]);
});

it('never grants a sequence UPDATE, a table TRUNCATE, REFERENCES or TRIGGER, or DELETE outside FULL_DML', function () {
    $sql = implode("\n", RuntimeGrantPlan::forwardStatements(fgdpEntries(), ['zz_full_id_seq'], 'fgdp_runtime'));

    expect($sql)->not->toContain('TRUNCATE')
        ->and($sql)->not->toContain('REFERENCES')
        ->and($sql)->not->toContain('TRIGGER')
        ->and($sql)->not->toMatch('/GRANT [^\n]*UPDATE[^\n]*SEQUENCE/')
        ->and(substr_count($sql, 'DELETE'))->toBe(1);
});

it('prints the same SQL whatever order its inputs come in', function () {
    $entries = fgdpEntries();
    $reversed = array_reverse($entries, true);

    expect(RuntimeGrantPlan::forwardStatements($reversed, ['b_seq', 'a_seq', 'a_seq'], 'fgdp_runtime'))
        ->toBe(RuntimeGrantPlan::forwardStatements($entries, ['a_seq', 'b_seq'], 'fgdp_runtime'));
});

it('refuses any name that is not a plain lower-case identifier', function (string $name) {
    expect(fn () => RuntimeGrantPlan::forwardStatements(fgdpEntries(), [], $name))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuntimeGrantPlan::forwardStatements([$name => ['class' => 'FULL_DML', 'columns' => []]], [], 'fgdp_runtime'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuntimeGrantPlan::script([], $name, 'forward'))->toThrow(InvalidArgumentException::class);
})->with(['Runtime', 'a-b', 'x"; drop table users; --', '', str_repeat('a', 64), '1abc', 'a b', "a\nb"]);

it('wraps the SQL in one transaction behind an owner-role guard, with no timestamp', function () {
    $script = RuntimeGrantPlan::script(['GRANT SELECT ON TABLE public."t" TO "r"'], 'fgdp_owner', 'forward');

    expect($script)->toStartWith('-- foundation:db-privilege-apply forward SQL')
        ->and($script)->toContain("BEGIN;\nSET LOCAL lock_timeout = '5s';\nSET LOCAL statement_timeout = '120s';")
        ->and($script)->toContain("IF current_user <> 'fgdp_owner' THEN RAISE EXCEPTION")
        ->and($script)->toEndWith("GRANT SELECT ON TABLE public.\"t\" TO \"r\";\nCOMMIT;\n")
        ->and($script)->not->toMatch('/20\d\d-\d\d-\d\d/')
        ->and(hash('sha256', $script))->toBe(hash('sha256', RuntimeGrantPlan::script(['GRANT SELECT ON TABLE public."t" TO "r"'], 'fgdp_owner', 'forward')));
});

it('builds the exact rollback of the runtime grants, grant option and columns included', function () {
    $current = [
        'zz_full' => ['table' => ['SELECT' => false, 'DELETE' => false, 'UPDATE' => true], 'columns' => []],
        'aa_ledger' => ['table' => ['SELECT' => false], 'columns' => ['updated_at' => ['UPDATE' => false], 'batch_id' => ['UPDATE' => false, 'REFERENCES' => true]]],
        'zz_full_id_seq' => ['table' => ['USAGE' => false, 'SELECT' => false], 'columns' => []],
    ];

    expect(RuntimeGrantPlan::rollbackStatements(fgdpEntries(), ['zz_full_id_seq'], $current, 'fgdp_runtime'))->toBe([
        'REVOKE ALL ON TABLE public."aa_ledger" FROM "fgdp_runtime"',
        'GRANT SELECT ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'GRANT UPDATE ("batch_id") ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'GRANT REFERENCES ("batch_id") ON TABLE public."aa_ledger" TO "fgdp_runtime" WITH GRANT OPTION',
        'GRANT UPDATE ("updated_at") ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."migrations" FROM "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."mm_append" FROM "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."zz_full" FROM "fgdp_runtime"',
        'GRANT SELECT, DELETE ON TABLE public."zz_full" TO "fgdp_runtime"',
        'GRANT UPDATE ON TABLE public."zz_full" TO "fgdp_runtime" WITH GRANT OPTION',
        'REVOKE ALL ON SEQUENCE public."zz_full_id_seq" FROM "fgdp_runtime"',
        'GRANT SELECT, USAGE ON SEQUENCE public."zz_full_id_seq" TO "fgdp_runtime"',
    ]);
});

it('refuses a rollback it could not make exact', function () {
    $current = ['zz_full' => ['table' => ['SOMETHING_NEW' => false], 'columns' => []]];

    expect(fn () => RuntimeGrantPlan::rollbackStatements(fgdpEntries(), [], $current, 'fgdp_runtime'))
        ->toThrow(RuntimeException::class, 'cannot restore exactly');
});

it('changes only the objects whose runtime grants differ from their class', function () {
    $entries = fgdpEntries();
    $expected = RuntimeGrantPlan::expectedGrants($entries, ['zz_full_id_seq']);
    $current = $expected;
    $current['mm_append']['table']['UPDATE'] = false;      // one privilege too many
    unset($current['zz_full_id_seq']);                     // nothing granted yet
    $current['aa_ledger']['columns']['batch_id'] = [];     // a listed column without its grant

    $changed = RuntimeGrantPlan::changedObjects($expected, $current);
    expect($changed)->toBe(['aa_ledger', 'mm_append', 'zz_full_id_seq']);

    $statements = RuntimeGrantPlan::statementsFor(RuntimeGrantPlan::forwardStatements($entries, ['zz_full_id_seq'], 'fgdp_runtime'), $changed);
    expect($statements)->toHaveCount(7)
        ->and(implode("\n", $statements))->not->toContain('"zz_full"')
        ->and(implode("\n", $statements))->not->toContain('"migrations"')
        ->and(RuntimeGrantPlan::changedObjects($expected, $expected))->toBe([]);
});

it('refuses conflicting or unsafe options before it reads anything', function (array $options, string $reason) {
    fgdpRoles();
    [$code, $output] = fgdpRun('foundation:db-privilege-apply', $options);

    expect($code)->toBe(1)->and($output)->toContain($reason);
})->with([
    'apply with print-sql' => [['--apply' => true, '--print-sql' => true], '--apply and --print-sql exclude each other'],
    'rollback alone' => [['--rollback' => true], '--rollback only prints'],
    'apply elsewhere' => [['--apply' => true, '--database' => 'pgsql'], '--apply runs only on pgsql_migrate'],
]);

it('refuses unconfigured, malformed or identical roles without echoing them', function (?string $runtime, ?string $owner, string $reason) {
    fgdpRoles($runtime, $owner);
    [$code, $output] = fgdpRun('foundation:db-privilege-apply');

    expect($code)->toBe(1)->and($output)->toContain($reason);
    foreach (array_filter([$runtime, $owner]) as $value) {
        expect($output)->not->toContain($value);
    }
})->with([
    'no runtime' => [null, 'fgdp_owner', 'the runtime role is not configured'],
    'no owner' => ['fgdp_runtime', null, 'the owner role is not configured'],
    'malformed runtime' => ['Fgdp-Runtime', 'fgdp_owner', 'the runtime role is not configured'],
    'malformed owner' => ['fgdp_runtime', 'x"; drop', 'the owner role is not configured'],
    'identical' => ['fgdp_same', 'fgdp_same', 'must differ'],
    'PUBLIC as the runtime (review round 1, finding 12)' => ['public', 'fgdp_owner', 'the runtime role is not configured'],
    'the bootstrap superuser as the runtime' => ['postgres', 'fgdp_owner', 'the runtime role is not configured'],
    'a predefined role as the runtime' => ['pg_monitor', 'fgdp_owner', 'the runtime role is not configured'],
    'a reserved owner' => ['fgdp_runtime', 'none', 'the owner role is not configured'],
]);

it('cannot verify without plain owner and runtime roles or a resolvable connection, and never echoes a role', function (?string $runtime, ?string $owner, array $options, string $reason) {
    fgdpRoles($runtime, $owner);
    [$code, $output] = fgdpRun('foundation:db-privilege-check', $options);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('DB PRIVILEGE CHECK: CANNOT VERIFY')
        ->and($output)->toContain($reason);
    foreach (array_filter([$runtime, $owner]) as $value) {
        expect($output)->not->toContain($value);
    }
})->with([
    'no owner' => ['fgdp_runtime', null, [], 'the owner role is not configured'],
    'a reserved owner' => ['fgdp_runtime', 'public', [], 'the owner role is not configured'],
    'no runtime (review round 1, finding 11)' => [null, 'fgdp_owner', [], 'the runtime role is not configured'],
    'an empty runtime (finding 11)' => ['', 'fgdp_owner', [], 'the runtime role is not configured'],
    'a predefined runtime' => ['pg_monitor', 'fgdp_owner', [], 'the runtime role is not configured'],
    'an unknown connection (finding 10)' => ['fgdp_runtime', 'fgdp_owner', ['--database' => 'fgdp_no_such_connection'], 'the catalog could not be read'],
]);

it('cannot verify a database that is not PostgreSQL', function () {
    config(['database.connections.fgdp_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    fgdpRoles('fgdp_runtime', 'fgdp_owner');
    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_sqlite']);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)->and($output)->toContain('the database is not PostgreSQL');
});

it('flags owner-rights and trigger-switch SQL in a migration as a first line (review round 1, findings 1-3)', function (string $line) {
    $flagged = array_filter(RuntimePrivilegeRegistry::FORBIDDEN_MIGRATION_SQL, fn (string $pattern): bool => preg_match($pattern, $line) === 1);

    expect($flagged)->not->toBe([]);
})->with([
    'SECURITY DEFINER' => ["DB::statement('CREATE FUNCTION f() RETURNS int LANGUAGE sql SECURITY DEFINER AS \$\$ select 1 \$\$');"],
    'a rewrite rule' => ["DB::statement('CREATE OR REPLACE RULE r AS ON INSERT TO cache DO ALSO NOTHING');"],
    'a view' => ["DB::statement('CREATE VIEW v AS SELECT 1');"],
    'a materialized view' => ["DB::statement('create materialized view mv as select 1');"],
    'a replaced view' => ["DB::statement('CREATE OR REPLACE VIEW v AS SELECT 1');"],
    'a disabled trigger' => ["DB::statement('ALTER TABLE t DISABLE TRIGGER g');"],
    'a replica-only trigger' => ["DB::statement('ALTER TABLE t ENABLE REPLICA TRIGGER g');"],
    'session_replication_role' => ["DB::statement('SET session_replication_role = replica');"],
]);

it('dry-runs on PostgreSQL: executes nothing, reports the plan and that apply would refuse before provisioning', function () {
    fgdpRoles('fgdp_runtime', 'fgdp_no_such_owner_role');
    $before = DB::selectOne("select count(*) as n from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relacl is not null")->n;

    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--json' => true]);
    $report = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($report['mode'])->toBe('dry-run')
        ->and($report['tables'])->toBeGreaterThanOrEqual(186)
        ->and($report['classes']['APPEND_ONLY_WITH_COLUMNS'] ?? 0)->toBe(3)
        ->and($report['apply_would_refuse'])->toBeTrue()
        ->and($report['objects_to_change'])->toBe($report['tables'] + $report['sequences'])
        ->and(DB::selectOne("select count(*) as n from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relacl is not null")->n)->toBe($before);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL as the test database');

it('prints a stable forward SQL, and the dry run reports only the role-free template SHA-256 (review round 1, finding 7)', function () {
    fgdpRoles('fgdp_runtime', 'fgdp_owner');

    [$c1, $first] = fgdpRun('foundation:db-privilege-apply', ['--print-sql' => true]);
    [$c2, $second] = fgdpRun('foundation:db-privilege-apply', ['--print-sql' => true]);
    [, $dry] = fgdpRun('foundation:db-privilege-apply', ['--json' => true]);
    $report = json_decode($dry, true);

    expect($c1)->toBe(0)->and($c2)->toBe(0)
        ->and(hash('sha256', $first))->toBe(hash('sha256', $second))
        ->and($report)->not->toHaveKey('forward_sha256')
        ->and($report['template_sha256'])->not->toBe(hash('sha256', $first))
        ->and($dry)->not->toContain('fgdp_runtime')
        ->and($dry)->not->toContain('fgdp_owner')
        ->and($first)->toContain('GRANT UPDATE ("inventory_batch_id", "updated_at") ON TABLE public."trx_inventory_movements" TO "fgdp_runtime";')
        ->and($first)->toContain('GRANT SELECT ON TABLE public."migrations" TO "fgdp_runtime";');

    // With the template's placeholder roles, the printed SQL hashes to exactly the template SHA-256.
    fgdpRoles(RuntimeGrantPlan::TEMPLATE_RUNTIME, RuntimeGrantPlan::TEMPLATE_OWNER);
    [, $template] = fgdpRun('foundation:db-privilege-apply', ['--print-sql' => true]);
    expect(hash('sha256', $template))->toBe($report['template_sha256']);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL as the test database');

it('lists exactly the guard triggers of the migrated schema (review round 1, finding 6)', function () {
    $live = array_map(fn (object $row): string => (string) $row->def, DB::select(
        'select pg_get_triggerdef(t.oid) as def from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and not t.tgisinternal'
    ));
    $expected = (array) config('runtime_privilege_classes.guard_triggers');
    sort($live);
    sort($expected);

    expect($expected)->toHaveCount(22)->and($live)->toBe($expected);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL as the test database');

it('pins exactly the guard function bodies of the migrated schema (review round 2, N4)', function () {
    $live = [];
    foreach (DB::select(
        "select p.proname, encode(sha256(convert_to(p.prosrc, 'UTF8')), 'hex') as body from pg_proc p where p.oid in (select t.tgfoid from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and not t.tgisinternal)"
    ) as $row) {
        $live[(string) $row->proname] = (string) $row->body;
    }
    ksort($live);
    $pinned = (array) config('runtime_privilege_classes.guard_functions');
    ksort($pinned);

    expect($pinned)->toHaveCount(5)->and($live)->toBe($pinned);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL as the test database');

it('is declared a critical-gate suite (CG-19)', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))->toContain('tests/Feature/Foundation/FoundationGovernanceDbPrivilegeTest.php');
});

// R6-B3c (pack 63c, C2): the repo-pinned PostgreSQL 16 baseline. Re-pinning it is a reviewed change of this
// literal, of config/runtime_privilege_classes.php and of the fixture, in one PR.
const FGDP_BASELINE_SHA256 = '178dad6499b5f8fc1028860364408049e5927db567b83ad4b778415e95497bce';

it('pins the PostgreSQL 16 baseline fixture by SHA-256 and keeps it canonical (R6-B3c, C2)', function () {
    $relative = 'tests/Fixtures/r6/pg16-runtime-baseline.txt';
    $text = (string) file_get_contents(base_path($relative));
    $lines = RuntimePrivilegeSurface::documentLines($text);
    $canonical = array_values(array_unique($lines));
    sort($canonical, SORT_STRING);

    expect(hash('sha256', $text))->toBe(FGDP_BASELINE_SHA256)
        ->and(config('runtime_privilege_classes.baseline'))->toBe(['path' => $relative, 'sha256' => FGDP_BASELINE_SHA256])
        ->and(RuntimePrivilegeSurface::baseline())->toBe(['lines' => $lines, 'sha256' => FGDP_BASELINE_SHA256])
        ->and($text)->toMatch('/^# Server: PostgreSQL 16$/m')
        ->and($text)->toContain("\n# Items: ".count($lines)."\n")
        ->and($lines)->toBe($canonical)
        ->and(count($lines))->toBeGreaterThan(3000);
    foreach ($lines as $line) {
        // Exactly five fields, no control byte, no OID-bearing name, PUBLIC or a predefined role as the grantee.
        expect(preg_match('/\A[^|\x00-\x1F]+(\|[^|\x00-\x1F]+){3}\|(YES|NO)\z/', $line))->toBe(1, $line);
        [$kind, $identity, , $via] = explode('|', $line);
        expect(in_array($kind, RuntimePrivilegeSurface::LAYOUT_KINDS, true))->toBeFalse($line)
            ->and($via === 'PUBLIC' || str_starts_with($via, 'pg_'))->toBeTrue($line)
            ->and(preg_match('/pg_(toast|temp)_\d/', $identity))->toBe(0, $line);
    }
});

it('escapes every field of a surface line, so no object name can forge a separator or a line (R6-B3c)', function () {
    $line = RuntimePrivilegeSurface::line('table', "public.a|SELECT|RUNTIME|NO\ntable|public.b\\c", 'SELECT', 'RUNTIME', false);

    expect($line)->toBe('table|public.a\x7CSELECT\x7CRUNTIME\x7CNO\x0Atable\x7Cpublic.b\x5Cc|SELECT|RUNTIME|NO')
        ->and(substr_count($line, '|'))->toBe(4)
        ->and($line)->not->toContain("\n")
        ->and(RuntimePrivilegeSurface::line('schema', "caf\u{E9}", 'USAGE', 'PUBLIC', true))->toBe('schema|caf\xC3\xA9|USAGE|PUBLIC|YES');
});

it('withholds role names in one pass, longest first, as written, escaped or quoted, in any case (R6-B3c, review round 2, N4/N5)', function () {
    $withhold = fn (string $text, array $roles): string => DatabasePrivilegeCheck::withholdRoleNames($text, $roles);

    // A name inside a longer one, and a name inside another's escaped form, no longer split it.
    expect($withhold('schema|zz_owner_ops|USAGE', ['zz_owner', 'zz_owner_ops']))->toBe('schema|<name withheld>|USAGE')
        ->and($withhold('schema|"r\xC3\xB4le_x"|USAGE', ['xC3', "r\u{F4}le_x"]))->toBe('schema|"<name withheld>"|USAGE')
        // Upper- and lower-case non-ASCII letters mixed differently from the role name.
        ->and($withhold('schema|"\xC3\xA9t\xC3\x89_role"|USAGE', ["\u{C9}t\u{C9}_role"]))->toBe('schema|"<name withheld>"|USAGE')
        ->and($withhold("\u{E9}T\u{C9}_ROLE owns it", ["\u{C9}t\u{C9}_role"]))->toBe('<name withheld> owns it')
        ->and($withhold('schema|"ops\x7Cadmin"|USAGE, also ops|admin', ['ops|admin']))->toBe('schema|"<name withheld>"|USAGE, also <name withheld>')
        ->and($withhold('schema|"qu""ote"|USAGE', ['qu"ote']))->toBe('schema|"<name withheld>"|USAGE')
        ->and($withhold('schema|"back\x5Cslash"|USAGE', ['back\\slash']))->toBe('schema|"<name withheld>"|USAGE')
        // R6-B3 behaviour kept: caseless, inside longer names.
        ->and($withhold('the runtime can CREATE in schema ZZ_RUNTIME_data', ['zz_runtime']))->toBe('the runtime can CREATE in schema <name withheld>_data')
        // A name that is not valid UTF-8 falls back to a byte-wise replacement instead of failing.
        ->and($withhold("schema|\xFF|USAGE and schema|\\xFF|USAGE", ["\xFF"]))->toBe('schema|<name withheld>|USAGE and schema|<name withheld>|USAGE');
});

it('compares exactly: an extra or a missing item is DRIFT, a permitted item is neither (R6-B3c, C4)', function () {
    $required = ['table|public.t|SELECT|RUNTIME|NO', 'type|public.t|USAGE|PUBLIC|NO'];
    $permitted = ['schema|public|USAGE|PUBLIC|NO'];

    expect(RuntimePrivilegeSurface::compare($required, $required, $permitted))->toBe(['extra' => [], 'missing' => []])
        ->and(RuntimePrivilegeSurface::compare([...$required, ...$permitted], $required, $permitted))->toBe(['extra' => [], 'missing' => []])
        ->and(RuntimePrivilegeSurface::compare(['table|public.t|SELECT|RUNTIME|NO', 'table|public.t|TRIGGER|RUNTIME|NO'], $required, $permitted))
        ->toBe(['extra' => ['table|public.t|TRIGGER|RUNTIME|NO'], 'missing' => ['type|public.t|USAGE|PUBLIC|NO']])
        // A grant option is a different item, never the same one.
        ->and(RuntimePrivilegeSurface::compare(['table|public.t|SELECT|RUNTIME|YES', 'type|public.t|USAGE|PUBLIC|NO'], $required, $permitted))
        ->toBe(['extra' => ['table|public.t|SELECT|RUNTIME|YES'], 'missing' => ['table|public.t|SELECT|RUNTIME|NO']]);
});

it('permits only the closed, reviewed set of optional items (R6-B3c)', function () {
    expect(RuntimePrivilegeSurface::permitted())->toBe([
        'database|CURRENT_DATABASE|CONNECT|PUBLIC|NO',
        'database|template0|CONNECT|PUBLIC|NO',
        'default privilege|OWNER in every schema: sequences|SELECT|RUNTIME|NO',
        'default privilege|OWNER in every schema: sequences|USAGE|RUNTIME|NO',
        'default privilege|OWNER in every schema: tables|SELECT|RUNTIME|NO',
        'default privilege|OWNER in schema public: sequences|SELECT|RUNTIME|NO',
        'default privilege|OWNER in schema public: sequences|USAGE|RUNTIME|NO',
        'default privilege|OWNER in schema public: tables|SELECT|RUNTIME|NO',
        'membership|pg_read_all_settings|SET|RUNTIME|NO',
        'membership|pg_read_all_stats|SET|RUNTIME|NO',
        'role setting|ANY_DATABASE|SET lo_compat_privileges=off|PUBLIC|NO',
        'role setting|ANY_DATABASE|SET lo_compat_privileges=off|RUNTIME|NO',
        'role setting|ANY_DATABASE|SET session_replication_role=origin|PUBLIC|NO',
        'role setting|ANY_DATABASE|SET session_replication_role=origin|RUNTIME|NO',
        'role setting|ANY_DATABASE|SET standard_conforming_strings=on|PUBLIC|NO',
        'role setting|ANY_DATABASE|SET standard_conforming_strings=on|RUNTIME|NO',
        'role setting|CURRENT_DATABASE|SET lo_compat_privileges=off|PUBLIC|NO',
        'role setting|CURRENT_DATABASE|SET lo_compat_privileges=off|RUNTIME|NO',
        'role setting|CURRENT_DATABASE|SET session_replication_role=origin|PUBLIC|NO',
        'role setting|CURRENT_DATABASE|SET session_replication_role=origin|RUNTIME|NO',
        'role setting|CURRENT_DATABASE|SET standard_conforming_strings=on|PUBLIC|NO',
        'role setting|CURRENT_DATABASE|SET standard_conforming_strings=on|RUNTIME|NO',
        'schema|extension:pg_stat_statements|USAGE|RUNTIME|NO',
        'schema|public|USAGE|PUBLIC|NO',
    ]);
});

it('refuses a baseline fixture that is missing or does not match its pin (R6-B3c, C2)', function () {
    config(['runtime_privilege_classes.baseline.sha256' => str_repeat('0', 64)]);
    expect(fn () => RuntimePrivilegeSurface::baseline())->toThrow(RuntimeException::class, 'does not match its pinned SHA-256');

    config(['runtime_privilege_classes.baseline' => ['path' => 'tests/Fixtures/r6/fgdp-no-such-baseline.txt', 'sha256' => FGDP_BASELINE_SHA256]]);
    expect(fn () => RuntimePrivilegeSurface::baseline())->toThrow(RuntimeException::class, 'is missing');
});

it('cannot verify, and generates no baseline, on a server that is not PostgreSQL 16 (R6-B3c, review round 1, #5d)', function () {
    fgdpRoles('fgdp_runtime', 'fgdp_owner');
    // A fake PostgreSQL 17 session: every session assertion holds, only the major version differs.
    $fake = Mockery::mock(PostgresConnection::class)->shouldIgnoreMissing();
    $fake->shouldReceive('setReadWriteType')->andReturnSelf();
    $fake->shouldReceive('getDriverName')->andReturn('pgsql');
    $fake->shouldReceive('transactionLevel')->andReturn(1);
    $fake->shouldReceive('selectOne')->andReturnUsing(fn (string $sql): object => match (true) {
        str_contains($sql, 'transaction_read_only') => (object) ['ro' => 'on'],
        str_contains($sql, 'session_user as ses') => (object) ['ses' => 'fgdp_runtime', 'cur' => 'fgdp_runtime', 'sch' => 'public'],
        str_contains($sql, 'from pg_catalog.pg_roles where rolname = ?') => (object) ['n' => 1],
        str_contains($sql, 'server_version_num') => (object) ['v' => '170002'],
        default => (object) ['v' => 'origin'],
    });
    config(['database.connections.fgdp_fake' => ['driver' => 'fgdp_fake']]);
    DB::extend('fgdp_fake', fn () => $fake);
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fake']);
        [$generated, $document] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fake', '--print-baseline' => true]);
    } finally {
        DB::purge('fgdp_fake');
    }

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('ERROR the server is not PostgreSQL 16, the major version the privilege baseline is pinned for.')
        ->and($generated)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($document)->toContain('ERROR the server is not PostgreSQL 16.');
    // Neither reached the enumeration: the gate comes before any catalog read beyond the session's own.
    $fake->shouldNotHaveReceived('select');
});

// ---------------------------------------------------------------- Group B

function fgdpEnv(string $key): ?string
{
    $value = getenv('R6_ROLE_TEST_'.$key);

    return $value === false || $value === '' ? null : $value;
}

function fgdpClusterConfigured(): bool
{
    foreach (['SOCKET_DIR', 'PORT', 'DATABASE', 'MIGRATOR', 'OWNER', 'RUNTIME', 'RUNTIME_PASSWORD', 'SUPERUSER'] as $key) {
        if (fgdpEnv($key) === null) {
            return false;
        }
    }

    return true;
}

/** Points pgsql_migrate, a runtime connection and the role config at the private cluster. */
function fgdpUseCluster(): void
{
    $base = [
        'driver' => 'pgsql', 'host' => fgdpEnv('SOCKET_DIR'), 'port' => fgdpEnv('PORT'), 'database' => fgdpEnv('DATABASE'),
        'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public', 'sslmode' => 'disable',
    ];
    config([
        'database.connections.pgsql_migrate' => $base + ['username' => fgdpEnv('MIGRATOR'), 'password' => '', 'server_options' => ['role' => fgdpEnv('OWNER')]],
        'database.connections.fgdp_runtime' => $base + ['username' => fgdpEnv('RUNTIME'), 'password' => fgdpEnv('RUNTIME_PASSWORD')],
    ]);
    DB::purge('pgsql_migrate');
    DB::purge('fgdp_runtime');
    fgdpRoles(fgdpEnv('RUNTIME'), fgdpEnv('OWNER'));
}

function fgdpPdo(string $user, string $password = ''): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', fgdpEnv('SOCKET_DIR'), fgdpEnv('PORT'), fgdpEnv('DATABASE'));

    return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fgdpSuper(): PDO
{
    return fgdpPdo(fgdpEnv('SUPERUSER'));
}

function fgdpRuntimePdo(): PDO
{
    return fgdpPdo(fgdpEnv('RUNTIME'), (string) fgdpEnv('RUNTIME_PASSWORD'));
}

/** SQLSTATE of $sql on $pdo, or 'ok'. */
function fgdpState(PDO $pdo, string $sql): string
{
    try {
        $pdo->exec($sql);

        return 'ok';
    } catch (PDOException $e) {
        return (string) $e->getCode();
    }
}

/** $sql with the design role names substituted (:runtime, :owner, :db, :super). */
function fgdpSql(string $sql): string
{
    return strtr($sql, [':RUNTIMEUPPER' => strtoupper((string) fgdpEnv('RUNTIME')), ':runtime' => fgdpEnv('RUNTIME'), ':owner' => fgdpEnv('OWNER'), ':db' => fgdpEnv('DATABASE'), ':super' => fgdpEnv('SUPERUSER')]);
}

/**
 * The catalog digest (ownership, normalised ACLs of every relation, column,
 * schema and the database, memberships, the runtime's attributes, function
 * owners and settings, trigger states), read as the superuser.
 */
function fgdpDigest(): string
{
    $rows = fgdpSuper()->query(<<<'SQL'
        select 'rel|' || c.relname || '|' || c.relkind::text || '|' || pg_get_userbyid(c.relowner) || '|' ||
               coalesce((select string_agg(pg_get_userbyid(a.grantor) || '>' || case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end || ':' || a.privilege_type || ':' || a.is_grantable, ',' order by 1)
                         from aclexplode(coalesce(c.relacl, acldefault((case when c.relkind = 'S' then 's' else 'r' end)::"char", c.relowner))) a), '')
          from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public'
        union all
        select 'col|' || c.relname || '.' || att.attname || '|' ||
               (select string_agg(case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end || ':' || a.privilege_type || ':' || a.is_grantable, ',' order by 1) from aclexplode(att.attacl) a)
          from pg_attribute att join pg_class c on c.oid = att.attrelid join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = 'public' and att.attacl is not null
        union all
        select 'nsp|' || pg_get_userbyid(nspowner) || '|' || coalesce(array_to_string(nspacl, ','), '') from pg_namespace where nspname = 'public'
        union all
        select 'db|' || pg_get_userbyid(datdba) || '|' || coalesce(array_to_string(datacl, ','), '') from pg_database where datname = current_database()
        union all
        select 'mem|' || pg_get_userbyid(roleid) || '>' || pg_get_userbyid(member) || '|' || admin_option || inherit_option || set_option from pg_auth_members
        union all
        select 'role|' || rolname || '|' || rolsuper || rolcreaterole || rolcreatedb || rolbypassrls || rolreplication || rolcanlogin from pg_roles where rolname not like 'pg\_%'
        union all
        select 'fn|' || p.proname || '|' || pg_get_userbyid(p.proowner) || '|' || p.prosecdef || '|' || coalesce(array_to_string(p.proconfig, ','), '') || '|' || coalesce(array_to_string(p.proacl, ','), '')
          from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public'
        union all
        select 'trg|' || c.relname || '.' || t.tgname || '|' || t.tgenabled::text from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and not t.tgisinternal
        order by 1
        SQL)->fetchAll(PDO::FETCH_COLUMN);

    return hash('sha256', implode("\n", $rows));
}

/** The schema migrated as the owner role (once); returns nothing. */
function fgdpMigrated(): void
{
    expect(Artisan::call('migrate', ['--database' => 'pgsql_migrate', '--force' => true]))->toBe(0);
}

/**
 * The state before any R6-B3 grant: the runtime holds only SELECT on every
 * table, as the cluster's default privileges gave it.
 */
function fgdpUngranted(): void
{
    $super = fgdpSuper();
    $super->exec(fgdpSql('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM ":runtime"; REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM ":runtime"; GRANT SELECT ON ALL TABLES IN SCHEMA public TO ":runtime"'));
}

/**
 * The state after provisioning (R6-D does this with psql as the superuser):
 * every trigger function pins search_path; pg_stat_statements is installed;
 * then the per-deploy grants are applied.
 */
function fgdpProvisionedAndGranted(): void
{
    $super = fgdpSuper();
    foreach ($super->query("select distinct p.oid::regprocedure::text from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace join pg_proc p on p.oid = t.tgfoid where n.nspname = 'public' and not t.tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $function) {
        // These trigger functions reference no table (they only raise or compare
        // NEW and OLD), so pinning pg_catalog is safe for every one of them.
        $super->exec("ALTER FUNCTION {$function} SET search_path = pg_catalog, pg_temp");
    }
    // Its own schema, as an administrator would install it: in `public` its views
    // would be objects the owner role does not own.
    $super->exec(fgdpSql('CREATE SCHEMA IF NOT EXISTS fgdp_ext; CREATE EXTENSION IF NOT EXISTS pg_stat_statements SCHEMA fgdp_ext; GRANT USAGE ON SCHEMA fgdp_ext TO ":runtime"'));
    // The production design (D-Q1): the runtime holds exactly the two monitoring roles.
    $super->exec(fgdpSql('GRANT pg_read_all_stats, pg_read_all_settings TO ":runtime"'));
    // Round 3, finding 7: PUBLIC loses CONNECT and TEMPORARY on every other database (an R6-D provisioning step).
    $super->exec('REVOKE CONNECT, TEMPORARY ON DATABASE postgres FROM PUBLIC; REVOKE CONNECT, TEMPORARY ON DATABASE template1 FROM PUBLIC');
    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
    expect($code)->toBe(0, $output);
}

// PB-7 (D4): where the role-test cluster is required (R6_ROLE_TEST_REQUIRED=1, set only by the CI step that
// starts it), group B never skips: a missing cluster fails here and in every group B test, so it can never
// silently turn into skips again (CG-17).
function fgdpClusterRequired(): bool
{
    return getenv('R6_ROLE_TEST_REQUIRED') === '1';
}

$fgdpSkip = fn () => ! fgdpClusterConfigured() && ! fgdpClusterRequired();
$fgdpSkipReason = 'needs the private PostgreSQL 16 role-test cluster (R6_ROLE_TEST_*, with SUPERUSER); no CI job provides it until PB-7';

it('fails closed when the role-test cluster is required but not configured (PB-7, D4)', function () {
    expect(! fgdpClusterRequired() || fgdpClusterConfigured())
        ->toBeTrue('R6_ROLE_TEST_REQUIRED=1 but the role-test cluster (R6_ROLE_TEST_*, with SUPERUSER) is not configured');
});

afterEach(function () {
    DB::purge('pgsql_migrate');
    DB::purge('fgdp_runtime');
});

it('reports drift before any grant, as the runtime, and names no role', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpUngranted();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);

    expect($code)->toBe(DatabasePrivilegeCheck::DRIFT)
        ->and($output)->toContain('FAIL  table users (FULL_DML): the runtime lacks INSERT')
        ->and($output)->toContain('FAIL  table trx_inventory_movements: the runtime cannot UPDATE column inventory_batch_id')
        ->and($output)->toContain('PASS  the runtime holds no elevated attribute')
        ->and($output)->toContain('PASS  the database is owned by a superuser (N-9)')
        ->and($output)->toContain('DB PRIVILEGE CHECK: DRIFT')
        ->and($output)->not->toContain(fgdpEnv('RUNTIME'))
        ->and($output)->not->toContain(fgdpEnv('OWNER'));
})->skip($fgdpSkip, $fgdpSkipReason);

it('dry-runs on the cluster without changing the catalog', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpUngranted();
    $before = fgdpDigest();

    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--database' => 'pgsql_migrate', '--json' => true]);
    $report = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($report['apply_would_refuse'])->toBeFalse()
        // Every object differs except the read-only table: the runtime already holds
        // exactly SELECT there, which is its class.
        ->and($report['objects_to_change'])->toBe($report['tables'] + $report['sequences'] - $report['classes']['READ_ONLY_RUNTIME'])
        ->and($report['changed'])->not->toContain('migrations')
        ->and(fgdpDigest())->toBe($before);
})->skip($fgdpSkip, $fgdpSkipReason);

it('applies idempotently and its printed rollback restores the pre-state digest exactly', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpUngranted();
    // Review round 1, T3: a pre-state with a column grant, a grant option, a
    // sequence UPDATE and a missing SELECT, so every path of the rollback runs.
    fgdpSuper()->exec(fgdpSql('GRANT UPDATE (expiration) ON public.cache TO ":runtime"; GRANT SELECT ON public.users TO ":runtime" WITH GRANT OPTION; GRANT UPDATE ON SEQUENCE public.users_id_seq TO ":runtime"; REVOKE SELECT ON public.mst_branches FROM ":runtime"'));
    $pre = fgdpDigest();

    [, $rollback] = fgdpRun('foundation:db-privilege-apply', ['--database' => 'pgsql_migrate', '--print-sql' => true, '--rollback' => true]);
    expect($rollback)->toContain('GRANT SELECT ON TABLE public."users" TO "'.fgdpEnv('RUNTIME').'" WITH GRANT OPTION;')
        ->and($rollback)->toContain('GRANT UPDATE ("expiration") ON TABLE public."cache" TO "'.fgdpEnv('RUNTIME').'";')
        ->and($rollback)->toContain('GRANT UPDATE ON SEQUENCE public."users_id_seq" TO "'.fgdpEnv('RUNTIME').'";');
    expect(fgdpDigest())->toBe($pre);

    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
    expect($code)->toBe(0, $output)->and($output)->toContain('APPLIED:');
    $granted = fgdpDigest();
    expect($granted)->not->toBe($pre);

    // Idempotent: a second run changes no object and leaves the same digest.
    [$again, $againOutput] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
    expect($again)->toBe(0)->and($againOutput)->toContain('APPLIED: 0 statement(s) for 0 object(s)')
        ->and(fgdpDigest())->toBe($granted);

    // The printed rollback, run as the owner role, restores the pre-state exactly.
    DB::connection('pgsql_migrate')->unprepared($rollback);
    expect(fgdpDigest())->toBe($pre);

    // Forward again reaches the same granted state.
    expect(fgdpRun('foundation:db-privilege-apply', ['--apply' => true])[0])->toBe(0)
        ->and(fgdpDigest())->toBe($granted);
})->skip($fgdpSkip, $fgdpSkipReason);

it('matches once provisioned and granted, and notes the foreign-key actions an owner must decide (round 2, N5)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);

    expect($code)->toBe(DatabasePrivilegeCheck::MATCH, $output)
        ->and($output)->toContain('DB PRIVILEGE CHECK: MATCH')
        ->and($output)->not->toContain('FAIL')
        ->and($output)->toMatch('/NOTE  foreign key \\S+ on trx_rme_payments \\(not FULL_DML, no guard trigger\\)/');
})->skip($fgdpSkip, $fgdpSkipReason);

it('fails on each injected drift and matches again once it is undone', function (string $inject, string $undo, string $expected) {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();

    $super->exec(fgdpSql($inject));
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        expect($code)->toBe(DatabasePrivilegeCheck::DRIFT, $output)
            ->and($output)->toContain($expected)
            // Round 3, finding 4: no role name in any case, not even inside a longer name.
            ->and(stripos($output, (string) fgdpEnv('RUNTIME')))->toBeFalse()
            ->and(stripos($output, (string) fgdpEnv('OWNER')))->toBeFalse();
    } finally {
        $super->exec(fgdpSql($undo));
    }

    DB::purge('fgdp_runtime');
    expect(fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime'])[0])->toBe(DatabasePrivilegeCheck::MATCH);
})->with([
    'UPDATE on an append-only table' => ['GRANT UPDATE ON public.trx_rme_record_versions TO ":runtime"', 'REVOKE UPDATE ON public.trx_rme_record_versions FROM ":runtime"', 'table trx_rme_record_versions (APPEND_ONLY): the runtime holds UPDATE'],
    'DELETE on an append-only table through PUBLIC' => ['GRANT DELETE ON public.trx_rme_record_versions TO PUBLIC', 'REVOKE DELETE ON public.trx_rme_record_versions FROM PUBLIC', 'the runtime holds DELETE'],
    'TRUNCATE on a FULL_DML table' => ['GRANT TRUNCATE ON public.users TO ":runtime"', 'REVOKE TRUNCATE ON public.users FROM ":runtime"', 'table users (FULL_DML): the runtime holds TRUNCATE'],
    'a missing FULL_DML privilege' => ['REVOKE INSERT ON public.users FROM ":runtime"', 'GRANT INSERT ON public.users TO ":runtime"', 'table users (FULL_DML): the runtime lacks INSERT'],
    'INSERT on the read-only table' => ['GRANT INSERT ON public.migrations TO ":runtime"', 'REVOKE INSERT ON public.migrations FROM ":runtime"', 'table migrations (READ_ONLY_RUNTIME): the runtime holds INSERT'],
    'an unlisted column UPDATE' => ['GRANT UPDATE (id) ON public.trx_inventory_movements TO ":runtime"', 'REVOKE UPDATE (id) ON public.trx_inventory_movements FROM ":runtime"', 'table trx_inventory_movements: the runtime can UPDATE column id'],
    'a column UPDATE on an append-only table' => ['GRANT UPDATE (id) ON public.trx_rme_record_versions TO ":runtime"', 'REVOKE UPDATE (id) ON public.trx_rme_record_versions FROM ":runtime"', 'the runtime holds UPDATE on a column'],
    'a sequence setval' => ['GRANT UPDATE ON SEQUENCE public.users_id_seq TO ":runtime"', 'REVOKE UPDATE ON SEQUENCE public.users_id_seq FROM ":runtime"', 'sequence users_id_seq: the runtime holds UPDATE (setval)'],
    'CREATE on public' => ['GRANT CREATE ON SCHEMA public TO ":runtime"', 'REVOKE CREATE ON SCHEMA public FROM ":runtime"', 'the runtime can CREATE in schema public'],
    'CREATE on the database' => ['GRANT CREATE ON DATABASE ":db" TO ":runtime"', 'REVOKE CREATE ON DATABASE ":db" FROM ":runtime"', 'the runtime can CREATE in the database'],
    'an elevated attribute' => ['ALTER ROLE ":runtime" CREATEDB', 'ALTER ROLE ":runtime" NOCREATEDB', 'the runtime holds CREATEDB'],
    'membership of the owner role' => ['GRANT ":owner" TO ":runtime"', 'REVOKE ":owner" FROM ":runtime"', 'the runtime is a member of the owner role'],
    'all of pg_monitor' => ['GRANT pg_monitor TO ":runtime"', 'REVOKE pg_monitor FROM ":runtime"', 'the runtime is a member of pg_monitor'],
    'server file writes' => ['GRANT pg_write_server_files TO ":runtime"', 'REVOKE pg_write_server_files FROM ":runtime"', 'the runtime is a member of pg_write_server_files'],
    'an object the runtime owns' => ['CREATE FUNCTION public.fgdp_owned() RETURNS int LANGUAGE sql AS $$ select 1 $$; ALTER FUNCTION public.fgdp_owned() OWNER TO ":runtime"', 'DROP FUNCTION public.fgdp_owned()', 'the runtime or a role it reaches owns 1 object(s) in this database'],
    'a disabled guard trigger' => ['ALTER TABLE public.trx_rme_record_versions DISABLE TRIGGER trx_rme_record_versions_immutable', 'ALTER TABLE public.trx_rme_record_versions ENABLE TRIGGER trx_rme_record_versions_immutable', 'trigger trx_rme_record_versions_immutable on trx_rme_record_versions does not fire'],
    'an unpinned trigger function' => ['ALTER FUNCTION public.trx_rme_record_versions_immutable() RESET search_path', 'ALTER FUNCTION public.trx_rme_record_versions_immutable() SET search_path = pg_catalog, pg_temp', 'trigger function trx_rme_record_versions_immutable does not pin search_path with pg_temp last'],
    'a SECURITY DEFINER trigger function' => ['ALTER FUNCTION public.trx_rme_record_versions_immutable() SECURITY DEFINER', 'ALTER FUNCTION public.trx_rme_record_versions_immutable() SECURITY INVOKER', 'trigger function trx_rme_record_versions_immutable is SECURITY DEFINER'],
    'a trigger function another role owns' => ['ALTER FUNCTION public.trx_rme_record_versions_immutable() OWNER TO ":super"', 'ALTER FUNCTION public.trx_rme_record_versions_immutable() OWNER TO ":owner"', 'trigger function trx_rme_record_versions_immutable is not owned by the owner role'],
    'a database owned by a non-superuser (N-9)' => ['ALTER DATABASE ":db" OWNER TO ":owner"', 'ALTER DATABASE ":db" OWNER TO ":super"', 'the database owner is not a superuser (N-9)'],
    'a statistics reset' => ['GRANT EXECUTE ON FUNCTION fgdp_ext.pg_stat_statements_reset(oid, oid, bigint) TO ":runtime"', 'REVOKE EXECUTE ON FUNCTION fgdp_ext.pg_stat_statements_reset(oid, oid, bigint) FROM ":runtime"', 'pg_stat_statements_reset'],
    'a server-file export' => ['GRANT EXECUTE ON FUNCTION pg_catalog.lo_export(oid, text) TO ":runtime"', 'REVOKE EXECUTE ON FUNCTION pg_catalog.lo_export(oid, text) FROM ":runtime"', 'the runtime can EXECUTE lo_export(oid,text)'],
    'switching triggers off' => ['GRANT SET ON PARAMETER session_replication_role TO ":runtime"', 'REVOKE SET ON PARAMETER session_replication_role FROM ":runtime"', 'the runtime holds SET on parameter session_replication_role'],
    // Review round 1, finding 1: triggers switched off by a default, not by the runtime.
    'a role default switches triggers off' => ['ALTER ROLE ":runtime" SET session_replication_role = replica', 'ALTER ROLE ":runtime" RESET session_replication_role', 'session_replication_role is not origin in the runtime session'],
    'a database default switches triggers off' => ['ALTER DATABASE ":db" SET session_replication_role = replica', 'ALTER DATABASE ":db" RESET session_replication_role', 'a default sets session_replication_role for every role in this database'],
    'a role-in-database default switches triggers off' => ['ALTER ROLE ":runtime" IN DATABASE ":db" SET session_replication_role = replica', 'ALTER ROLE ":runtime" IN DATABASE ":db" RESET session_replication_role', 'a default sets session_replication_role for the runtime role in this database'],
    // Finding 2: owner rights through a SECURITY DEFINER function or a rewrite rule.
    'a SECURITY DEFINER function the runtime can run' => ['CREATE FUNCTION public.fgdp_definer() RETURNS int LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$ select 1 $$; ALTER FUNCTION public.fgdp_definer() OWNER TO ":owner"', 'DROP FUNCTION public.fgdp_definer()', 'SECURITY DEFINER function public.fgdp_definer() exists'],
    'a rewrite rule on a table' => ['CREATE RULE fgdp_rule AS ON INSERT TO public.cache DO ALSO NOTHING', 'DROP RULE fgdp_rule ON public.cache', 'table cache carries rewrite rule fgdp_rule'],
    // Finding 3: a writable view.
    'an owner-owned updatable view' => ['CREATE VIEW public.fgdp_view AS SELECT * FROM public.migrations; ALTER VIEW public.fgdp_view OWNER TO ":owner"; GRANT UPDATE ON public.fgdp_view TO ":runtime"', 'DROP VIEW public.fgdp_view', 'relation public.fgdp_view (kind v): the runtime can write to it outside the table registry'],
    // Finding 4: a restricted built-in.
    'reading server files' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) TO ":runtime"', 'REVOKE EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) FROM ":runtime"', 'the runtime can EXECUTE pg_read_binary_file(text) beyond its initial privileges (granted to the runtime)'],
    'resetting statistics' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_reset() TO ":runtime"', 'REVOKE EXECUTE ON FUNCTION pg_catalog.pg_stat_reset() FROM ":runtime"', 'the runtime can EXECUTE pg_stat_reset() beyond its initial privileges (granted to the runtime)'],
    // Finding 5: parameter privileges.
    'ALTER SYSTEM on a parameter' => ['GRANT ALTER SYSTEM ON PARAMETER archive_command TO ":runtime"', 'REVOKE ALTER SYSTEM ON PARAMETER archive_command FROM ":runtime"', 'the runtime holds ALTER SYSTEM on parameter archive_command'],
    'SET on a superuser parameter' => ['GRANT SET ON PARAMETER log_statement TO ":runtime"', 'REVOKE SET ON PARAMETER log_statement FROM ":runtime"', 'the runtime holds SET on parameter log_statement'],
    // Finding 6: a missing or an unexpected trigger.
    'a dropped guard trigger' => ['DROP TRIGGER trx_rme_record_versions_immutable ON public.trx_rme_record_versions', 'CREATE TRIGGER trx_rme_record_versions_immutable BEFORE UPDATE OR DELETE ON public.trx_rme_record_versions FOR EACH ROW EXECUTE FUNCTION trx_rme_record_versions_immutable()', 'an expected guard trigger is missing or changed: trx_rme_record_versions_immutable on public.trx_rme_record_versions'],
    'an unexpected trigger' => ['CREATE TRIGGER fgdp_extra AFTER INSERT ON public.cache FOR EACH STATEMENT EXECUTE FUNCTION trx_prpcr_refuse_change()', 'DROP TRIGGER fgdp_extra ON public.cache', 'a trigger that is not in the expected list exists: fgdp_extra on public.cache'],
    // Finding 8: a grant option.
    'a grant option' => ['GRANT SELECT ON public.users TO ":runtime" WITH GRANT OPTION', 'REVOKE GRANT OPTION FOR SELECT ON public.users FROM ":runtime"', 'the runtime or a role it reaches holds a grant option (SELECT) on relation users'],
    // Finding 9: TEMPORARY, and a search_path without pg_temp last.
    'TEMPORARY on the database' => ['GRANT TEMPORARY ON DATABASE ":db" TO ":runtime"', 'REVOKE TEMPORARY ON DATABASE ":db" FROM ":runtime"', 'the runtime can create TEMPORARY objects in the database'],
    'a trigger function pinned without pg_temp' => ['ALTER FUNCTION public.trx_rme_amendments_guard() SET search_path = public', 'ALTER FUNCTION public.trx_rme_amendments_guard() SET search_path = pg_catalog, pg_temp', 'trigger function trx_rme_amendments_guard does not pin search_path with pg_temp last'],
    // Finding 13: default privileges.
    // Review round 2 (fix verification), N1: a restricted built-in granted to PUBLIC or to a monitoring role.
    'a restricted built-in granted to PUBLIC' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) TO PUBLIC', 'REVOKE EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) FROM PUBLIC', 'the runtime can EXECUTE pg_read_binary_file(text) beyond its initial privileges (granted to PUBLIC)'],
    'a restricted built-in granted to a monitoring role' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_reset() TO pg_read_all_stats', 'REVOKE EXECUTE ON FUNCTION pg_catalog.pg_stat_reset() FROM pg_read_all_stats', 'the runtime can EXECUTE pg_stat_reset() beyond its initial privileges (granted to pg_read_all_stats)'],
    // N2: SECURITY DEFINER in pg_catalog, and an event trigger.
    'a SECURITY DEFINER function in pg_catalog' => ['CREATE FUNCTION pg_catalog.fgdp_cat_definer() RETURNS int LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$ select 1 $$', 'DROP FUNCTION pg_catalog.fgdp_cat_definer()', 'SECURITY DEFINER function fgdp_cat_definer() exists'],
    'an event trigger' => ['CREATE FUNCTION public.fgdp_evt() RETURNS event_trigger LANGUAGE plpgsql AS $$ BEGIN END $$; CREATE EVENT TRIGGER fgdp_evt ON ddl_command_end EXECUTE FUNCTION public.fgdp_evt()', 'DROP EVENT TRIGGER fgdp_evt; DROP FUNCTION public.fgdp_evt()', 'event trigger fgdp_evt exists'],
    // N3: table inheritance.
    'table inheritance' => ['CREATE TABLE public.fgdp_parent (key varchar(255)); ALTER TABLE public.cache INHERIT public.fgdp_parent', 'ALTER TABLE public.cache NO INHERIT public.fgdp_parent; DROP TABLE public.fgdp_parent', 'table cache inherits from fgdp_parent'],
    // N6: an object named after a role never prints the role name.
    'a schema named after the runtime' => ['CREATE SCHEMA ":runtime"; GRANT CREATE ON SCHEMA ":runtime" TO ":runtime"', 'DROP SCHEMA ":runtime"', 'the runtime can CREATE in schema <name withheld>'],
    // N7: grant options and ADMIN OPTION held through a reached role, and on a language.
    'a grant option held by a monitoring role' => ['GRANT SELECT ON public.users TO pg_read_all_stats WITH GRANT OPTION', 'REVOKE SELECT ON public.users FROM pg_read_all_stats', 'the runtime or a role it reaches holds a grant option (SELECT) on relation users'],
    'ADMIN OPTION on a monitoring role' => ['GRANT pg_read_all_stats TO ":runtime" WITH ADMIN OPTION', 'REVOKE ADMIN OPTION FOR pg_read_all_stats FROM ":runtime"', 'the runtime or a role it reaches holds ADMIN OPTION on pg_read_all_stats'],
    'a grant option on a language' => ['GRANT USAGE ON LANGUAGE plpgsql TO ":runtime" WITH GRANT OPTION', 'REVOKE USAGE ON LANGUAGE plpgsql FROM ":runtime"', 'holds a grant option (USAGE) on language plpgsql'],
    // N8: ownership and defaults through a reached role; a sequence outside public.
    'an object owned by a monitoring role' => ['CREATE FUNCTION public.fgdp_stats_owned() RETURNS int LANGUAGE sql AS $$ select 1 $$; ALTER FUNCTION public.fgdp_stats_owned() OWNER TO pg_read_all_stats', 'DROP FUNCTION public.fgdp_stats_owned()', 'a built-in role the runtime reaches owns 1 object(s)'],
    'a default privilege for a monitoring role' => ['ALTER DEFAULT PRIVILEGES FOR ROLE ":owner" IN SCHEMA public GRANT ALL ON TABLES TO pg_read_all_stats', 'ALTER DEFAULT PRIVILEGES FOR ROLE ":owner" IN SCHEMA public REVOKE ALL ON TABLES FROM pg_read_all_stats', 'a default privilege gives pg_read_all_stats'],
    'a sequence outside public' => ['CREATE SEQUENCE fgdp_ext.fgdp_seq; GRANT UPDATE ON SEQUENCE fgdp_ext.fgdp_seq TO ":runtime"', 'DROP SEQUENCE fgdp_ext.fgdp_seq', 'sequence fgdp_ext.fgdp_seq: the runtime holds UPDATE (setval)'],
    // T4: a writable partition.
    'a writable partition' => ['CREATE TABLE public.fgdp_part (id int) PARTITION BY RANGE (id); CREATE TABLE public.fgdp_part_1 PARTITION OF public.fgdp_part FOR VALUES FROM (0) TO (10); GRANT INSERT ON public.fgdp_part_1 TO ":runtime"', 'DROP TABLE public.fgdp_part', 'relation public.fgdp_part_1 (kind r): the runtime can write to it outside the table registry'],
    // Review round 3 (verification), finding 1c: the round-1 current-ACL rule, kept.
    'a withheld function granted to the runtime' => ['CREATE FUNCTION pg_catalog.fgdp_restricted() RETURNS int LANGUAGE sql AS $$ select 1 $$; REVOKE EXECUTE ON FUNCTION pg_catalog.fgdp_restricted() FROM PUBLIC; GRANT EXECUTE ON FUNCTION pg_catalog.fgdp_restricted() TO ":runtime"', 'DROP FUNCTION pg_catalog.fgdp_restricted()', 'the runtime can EXECUTE fgdp_restricted(), which PUBLIC may not'],
    // Finding 2: code the runtime runs without a grant.
    'an internal alias of a restricted built-in' => ["CREATE FUNCTION fgdp_ext.fgdp_rd(text) RETURNS bytea LANGUAGE internal STRICT AS 'pg_read_binary_file_all'", 'DROP FUNCTION fgdp_ext.fgdp_rd(text)', 'is internal or C code added after initdb, outside an extension'],
    'an aggregate whose final function is restricted' => ['CREATE AGGREGATE public.fgdp_agg(text) (sfunc = textcat, stype = text, finalfunc = pg_catalog.pg_read_file)', 'DROP AGGREGATE public.fgdp_agg(text)', 'aggregate public.fgdp_agg(text) runs a support function the runtime may not execute'],
    'an extension outside the allowlist' => ['CREATE EXTENSION IF NOT EXISTS pgcrypto SCHEMA fgdp_ext', 'DROP EXTENSION pgcrypto', 'extension pgcrypto is not on the allowlist'],
    // Finding 3: reads outside the registry.
    'a readable credential catalog' => ['GRANT SELECT ON pg_catalog.pg_authid TO PUBLIC', 'REVOKE SELECT ON pg_catalog.pg_authid FROM PUBLIC', 'the runtime can reach system relation pg_authid beyond its initial privileges'],
    'a readable credential column' => ['GRANT SELECT (rolpassword) ON pg_catalog.pg_authid TO pg_read_all_stats', 'REVOKE SELECT (rolpassword) ON pg_catalog.pg_authid FROM pg_read_all_stats', 'the runtime can reach system relation pg_authid (a column) beyond its initial privileges'],
    'a readable view outside the registry' => ['CREATE VIEW fgdp_ext.fgdp_auth AS SELECT rolname FROM pg_catalog.pg_authid; GRANT SELECT ON fgdp_ext.fgdp_auth TO PUBLIC', 'DROP VIEW fgdp_ext.fgdp_auth', 'the runtime can read relation fgdp_ext.fgdp_auth (kind v) outside the table registry'],
    'a privilege on a large object' => ['SELECT lo_create(424242); GRANT SELECT ON LARGE OBJECT 424242 TO ":runtime"', 'SELECT lo_unlink(424242)', 'holds a privilege on 1 large object(s)'],
    // Finding 4: withholding is case-insensitive and inside longer names.
    'a schema whose name contains the runtime name' => ['CREATE SCHEMA ":runtime_data"; GRANT CREATE ON SCHEMA ":runtime_data" TO ":runtime"', 'DROP SCHEMA ":runtime_data"', 'the runtime can CREATE in schema <name withheld>_data'],
    'a schema named after the runtime in upper case' => ['CREATE SCHEMA ":RUNTIMEUPPER"; GRANT CREATE ON SCHEMA ":RUNTIMEUPPER" TO ":runtime"', 'DROP SCHEMA ":RUNTIMEUPPER"', 'the runtime can CREATE in schema <name withheld>'],
    // Finding 5: a guard's search_path widened without touching its body.
    'a guard whose search_path lets public shadow pg_catalog' => ['ALTER FUNCTION public.dms_inventory_ledger_guard() SET search_path = public, pg_catalog, pg_temp', 'ALTER FUNCTION public.dms_inventory_ledger_guard() SET search_path = pg_catalog, pg_temp', 'guard function dms_inventory_ledger_guard settings are not exactly search_path=pg_catalog, pg_temp'],
    'a guard pinned with public after pg_catalog' => ['ALTER FUNCTION public.dms_inventory_ledger_guard() SET search_path = pg_catalog, public, pg_temp', 'ALTER FUNCTION public.dms_inventory_ledger_guard() SET search_path = pg_catalog, pg_temp', 'guard function dms_inventory_ledger_guard settings are not exactly search_path=pg_catalog, pg_temp'],
    // Finding 6: ownership of a language or another database by a reached built-in role.
    'a language owned by a monitoring role' => ['ALTER LANGUAGE plpgsql OWNER TO pg_read_all_stats', 'ALTER LANGUAGE plpgsql OWNER TO ":super"', 'a built-in role the runtime reaches owns 1 object(s)'],
    'another database owned by a monitoring role' => ['ALTER DATABASE postgres OWNER TO pg_read_all_stats', 'ALTER DATABASE postgres OWNER TO ":super"', 'a built-in role the runtime reaches owns 1 object(s)'],
    // Finding 7: another database reachable.
    'CONNECT on another database' => ['GRANT CONNECT ON DATABASE postgres TO PUBLIC', 'REVOKE CONNECT ON DATABASE postgres FROM PUBLIC', 'the runtime can connect to or create in 1 other database(s)'],
    // Finding 9: large-object ACL checks switched off by a default.
    'lo_compat_privileges switched on by a role default' => ['ALTER ROLE ":runtime" SET lo_compat_privileges = on', 'ALTER ROLE ":runtime" RESET lo_compat_privileges', 'lo_compat_privileges is not off in the runtime session'],
    // Review round 4, N1: the check runs with only pg_catalog searched, and public holds nothing unexpected.
    'a shadowing operator in public' => ['CREATE FUNCTION public.fgdp_oideq(oid, integer) RETURNS boolean LANGUAGE sql AS $$ select false $$; CREATE OPERATOR public.= (leftarg = oid, rightarg = integer, function = public.fgdp_oideq)', 'DROP OPERATOR public.= (oid, integer); DROP FUNCTION public.fgdp_oideq(oid, integer)', 'public holds operator public.=(oid,integer)'],
    'a shadowing operator does not hide a real drift' => ['CREATE FUNCTION public.fgdp_oideq(oid, integer) RETURNS boolean LANGUAGE sql AS $$ select false $$; CREATE OPERATOR public.= (leftarg = oid, rightarg = integer, function = public.fgdp_oideq); GRANT SELECT ON pg_catalog.pg_authid TO PUBLIC', 'REVOKE SELECT ON pg_catalog.pg_authid FROM PUBLIC; DROP OPERATOR public.= (oid, integer); DROP FUNCTION public.fgdp_oideq(oid, integer)', 'the runtime can reach system relation pg_authid beyond its initial privileges'],
    'a shadowing function in public' => ['CREATE FUNCTION public.convert_to(text, text) RETURNS bytea LANGUAGE sql AS $$ select null::bytea $$', 'DROP FUNCTION public.convert_to(text, text)', 'public holds function public.convert_to(text,text), which is not a pinned guard function'],
    'a type in public' => ['CREATE TYPE public.fgdp_mood AS ENUM (\'a\')', 'DROP TYPE public.fgdp_mood', 'public holds type fgdp_mood'],
    // N2: a runtime default turning standard_conforming_strings off, alone and hiding a schema.
    'standard_conforming_strings off by a role default' => ['ALTER ROLE ":runtime" SET standard_conforming_strings = off', 'ALTER ROLE ":runtime" RESET standard_conforming_strings', 'standard_conforming_strings is not on in the runtime session'],
    'a schema LIKE-escapes would have hidden' => ['ALTER ROLE ":runtime" SET standard_conforming_strings = off; CREATE SCHEMA pgxtempxx; GRANT CREATE ON SCHEMA pgxtempxx TO ":runtime"', 'DROP SCHEMA pgxtempxx; ALTER ROLE ":runtime" RESET standard_conforming_strings', 'the runtime can CREATE in schema pgxtempxx'],
    // N4: an information_schema relation initdb left without an ACL.
    'an information_schema relation granted to PUBLIC' => ['GRANT SELECT ON information_schema._pg_user_mappings TO PUBLIC', 'REVOKE SELECT ON information_schema._pg_user_mappings FROM PUBLIC', 'system relation information_schema._pg_user_mappings beyond its initial privileges'],
    // N5: any privilege on a sequence outside public.
    'USAGE on a sequence outside public' => ['CREATE SEQUENCE fgdp_ext.fgdp_useq; GRANT USAGE ON SEQUENCE fgdp_ext.fgdp_useq TO ":runtime"', 'DROP SEQUENCE fgdp_ext.fgdp_useq', 'sequence fgdp_ext.fgdp_useq: the runtime holds a privilege on a sequence outside public'],
    // Review round 5, F1: a monitoring membership usable through SET ROLE but not inherited.
    'a non-inherited monitoring membership' => ['REVOKE pg_read_all_stats FROM ":runtime"; GRANT pg_read_all_stats TO ":runtime" WITH INHERIT FALSE, SET TRUE', 'REVOKE pg_read_all_stats FROM ":runtime"; GRANT pg_read_all_stats TO ":runtime"', 'the runtime can SET ROLE to pg_read_all_stats without inheriting it'],
    'a non-inherited membership hiding a table grant' => ['REVOKE pg_read_all_stats FROM ":runtime"; GRANT pg_read_all_stats TO ":runtime" WITH INHERIT FALSE, SET TRUE; GRANT DELETE ON public.trx_rme_record_versions TO pg_read_all_stats', 'REVOKE DELETE ON public.trx_rme_record_versions FROM pg_read_all_stats; REVOKE pg_read_all_stats FROM ":runtime"; GRANT pg_read_all_stats TO ":runtime"', 'the runtime can SET ROLE to pg_read_all_stats without inheriting it'],
    // F3: the rest of the public inventory.
    'a standalone composite type in public' => ['CREATE TYPE public.fgdp_pair AS (a int, b int)', 'DROP TYPE public.fgdp_pair', 'public holds relation fgdp_pair (kind c) outside the table registry'],
    'a text search configuration in public' => ['CREATE TEXT SEARCH CONFIGURATION public.fgdp_ts (COPY = pg_catalog.simple)', 'DROP TEXT SEARCH CONFIGURATION public.fgdp_ts', 'public holds text search configuration fgdp_ts'],
    'a statistics object in public' => ['CREATE STATISTICS public.fgdp_stx ON key, expiration FROM public.cache', 'DROP STATISTICS public.fgdp_stx', 'public holds statistics object fgdp_stx'],
    'an overload of a guard function name' => ['CREATE FUNCTION public.dms_append_only_guard(integer) RETURNS integer LANGUAGE sql AS $$ select 1 $$', 'DROP FUNCTION public.dms_append_only_guard(integer)', 'public holds function public.dms_append_only_guard(integer), which is not a pinned guard function'],
    'a wide default privilege' => ['ALTER DEFAULT PRIVILEGES FOR ROLE ":owner" IN SCHEMA public GRANT ALL ON TABLES TO ":runtime"', 'ALTER DEFAULT PRIVILEGES FOR ROLE ":owner" IN SCHEMA public REVOKE ALL ON TABLES FROM ":runtime"; ALTER DEFAULT PRIVILEGES FOR ROLE ":owner" IN SCHEMA public GRANT SELECT ON TABLES TO ":runtime"', 'a default privilege gives the runtime INSERT on new objects of type r'],
])->skip($fgdpSkip, $fgdpSkipReason);

it('denies the runtime everything outside its class (42501)', function (string $sql) {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    expect(fgdpState(fgdpRuntimePdo(), fgdpSql($sql)))->toBe('42501');
})->with([
    'UPDATE an append-only table' => ['UPDATE public.trx_rme_record_versions SET id = id WHERE false'],
    'DELETE from an append-only table' => ['DELETE FROM public.trx_rme_record_versions WHERE false'],
    'TRUNCATE a FULL_DML table' => ['TRUNCATE public.cache'],
    'UPDATE an unlisted column' => ['UPDATE public.trx_inventory_movements SET id = id WHERE false'],
    'DELETE from a column-limited table' => ['DELETE FROM public.trx_inventory_movements WHERE false'],
    'INSERT into migrations' => ["INSERT INTO public.migrations (migration, batch) VALUES ('fgdp', 0)"],
    'setval' => ["SELECT setval('public.users_id_seq', 1)"],
    'CREATE TABLE' => ['CREATE TABLE public.fgdp_runtime_table (id int)'],
    'CREATE SCHEMA' => ['CREATE SCHEMA fgdp_runtime_schema'],
    'reset statistics' => ['SELECT fgdp_ext.pg_stat_statements_reset()'],
    'export a server file' => ["SELECT lo_export(0, '/tmp/fgdp')"],
    'switch triggers off' => ['SET session_replication_role = replica'],
    'assume the owner role' => ['SET ROLE ":owner"'],
    'row-lock an append-only table' => ['SELECT 1 FROM public.trx_rme_record_versions FOR UPDATE'],
])->skip($fgdpSkip, $fgdpSkipReason);

it('lets the runtime row-lock every table that keeps UPDATE, and update the listed columns', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $runtime = fgdpRuntimePdo();
    $failures = [];

    [$entries] = (new RuntimePrivilegeRegistry)->expected();
    foreach ($entries as $table => $entry) {
        if (! in_array($entry['class'], ['FULL_DML', 'APPEND_ONLY_WITH_COLUMNS'], true)) {
            continue;
        }
        foreach (['FOR UPDATE', 'FOR NO KEY UPDATE'] as $lock) {
            $runtime->beginTransaction();
            $state = fgdpState($runtime, "SELECT 1 FROM public.\"{$table}\" {$lock}");
            $runtime->rollBack();
            if ($state !== 'ok') {
                $failures[] = "{$table} {$lock}: {$state}";
            }
        }
        foreach ($entry['columns'] as $column) {
            $state = fgdpState($runtime, "UPDATE public.\"{$table}\" SET \"{$column}\" = \"{$column}\" WHERE false");
            if ($state !== 'ok') {
                $failures[] = "{$table}.{$column} UPDATE: {$state}";
            }
        }
    }

    expect($failures)->toBe([]);
})->skip($fgdpSkip, $fgdpSkipReason);

it('refuses to apply before provisioning and changes nothing', function () {
    fgdpUseCluster();
    fgdpMigrated();
    $super = fgdpSuper();
    $super->exec(fgdpSql('ALTER TABLE public.cache OWNER TO ":super"'));
    try {
        $before = fgdpDigest();
        [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('not owned by the owner role: provisioning (R6-D) comes first')
            ->and(fgdpDigest())->toBe($before);
    } finally {
        $super->exec(fgdpSql('ALTER TABLE public.cache OWNER TO ":owner"'));
    }
})->skip($fgdpSkip, $fgdpSkipReason);

it('rolls the whole apply back when the result still differs from the classes', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    // One object to change, and a PUBLIC grant the apply never touches.
    $super->exec(fgdpSql('REVOKE INSERT ON public.users FROM ":runtime"; GRANT DELETE ON public.trx_rme_record_versions TO PUBLIC'));
    try {
        $before = fgdpDigest();
        [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('nothing was changed: after the grants the runtime still differs from its classes')
            ->and($output)->toContain('trx_rme_record_versions')
            ->and(fgdpDigest())->toBe($before);
    } finally {
        $super->exec(fgdpSql('REVOKE DELETE ON public.trx_rme_record_versions FROM PUBLIC; GRANT INSERT ON public.users TO ":runtime"'));
    }
})->skip($fgdpSkip, $fgdpSkipReason);

it('refuses to check from any session but the runtime itself', function () {
    fgdpUseCluster();
    fgdpMigrated();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'pgsql_migrate']);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('not under SET ROLE');
})->skip($fgdpSkip, $fgdpSkipReason);

it('cannot verify, as the runtime, when the configured owner role does not exist (review round 1, T1)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpRoles(fgdpEnv('RUNTIME'), 'fgdp_no_such_owner');

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('the configured owner role does not exist')
        ->and($output)->not->toContain(fgdpEnv('RUNTIME'));
})->skip($fgdpSkip, $fgdpSkipReason);

it('reads only, in a read-only transaction, for the check and the dry run (review round 1, T2)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $seen = [];
    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        $seen[$query->connectionName][] = trim($query->sql);
    });

    expect(fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime'])[0])->toBe(DatabasePrivilegeCheck::MATCH)
        ->and(fgdpRun('foundation:db-privilege-apply', ['--database' => 'pgsql_migrate'])[0])->toBe(0);

    // Round 4, N1/N2: besides reads, the only statements are the two session pins.
    $pins = ['SET LOCAL search_path = pg_catalog', 'SET LOCAL standard_conforming_strings = on'];
    foreach (['fgdp_runtime', 'pgsql_migrate'] as $name) {
        expect($seen[$name][0] ?? null)->toBe('SET TRANSACTION READ ONLY')
            ->and(array_values(array_intersect($seen[$name], $pins)))->toBe($pins);
        foreach (array_slice($seen[$name], 1) as $sql) {
            if (in_array($sql, $pins, true)) {
                continue;
            }
            expect(preg_match('/^(select|with)\b/i', $sql))->toBe(1, "{$name} ran a statement that is not a read: {$sql}");
        }
    }
})->skip($fgdpSkip, $fgdpSkipReason);

it('catches a replaced guard function body that keeps its owner, pin and trigger (review round 2, N4)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    $original = (string) $super->query("select pg_get_functiondef('public.trx_rme_amendments_guard()'::regprocedure)")->fetchColumn();

    $super->exec('CREATE OR REPLACE FUNCTION public.trx_rme_amendments_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $f$ BEGIN RETURN NEW; END $f$');
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        expect($code)->toBe(DatabasePrivilegeCheck::DRIFT, $output)
            ->and($output)->toContain('guard function trx_rme_amendments_guard body differs from its pinned SHA-256')
            ->and($output)->not->toContain('does not pin search_path')
            ->and($output)->not->toContain('missing or changed');
    } finally {
        $super->exec($original);
    }

    DB::purge('fgdp_runtime');
    expect(fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime'])[0])->toBe(DatabasePrivilegeCheck::MATCH);
})->skip($fgdpSkip, $fgdpSkipReason);

it('withholds every role name in the apply and dry-run output (review round 4, N3)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    $noLeak = function (string $output): void {
        expect(stripos($output, (string) fgdpEnv('RUNTIME')))->toBeFalse($output)
            ->and(stripos($output, (string) fgdpEnv('OWNER')))->toBeFalse($output)
            ->and(stripos($output, (string) fgdpEnv('MIGRATOR')))->toBeFalse($output);
    };

    // A public table named after a role: the dry run refuses with the registry error, name withheld.
    $super->exec(fgdpSql('CREATE TABLE public.":runtime_notes" (id int)'));
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--database' => 'pgsql_migrate']);
        expect($code)->toBe(1)->and($output)->toContain('<name withheld>_notes');
        $noLeak($output);
    } finally {
        $super->exec(fgdpSql('DROP TABLE public.":runtime_notes"'));
    }

    // A writable relation in a schema named after a role: the apply's re-check refuses, name withheld.
    $super->exec(fgdpSql('CREATE SCHEMA ":runtime_x"; CREATE TABLE ":runtime_x".t (id int); GRANT USAGE ON SCHEMA ":runtime_x" TO ":runtime"; GRANT INSERT ON ":runtime_x".t TO ":runtime"'));
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
        expect($code)->toBe(1)->and($output)->toContain('<name withheld>_x');
        $noLeak($output);
    } finally {
        $super->exec(fgdpSql('DROP SCHEMA ":runtime_x" CASCADE'));
    }
})->skip($fgdpSkip, $fgdpSkipReason);

it('accepts a default that sets a protected setting to its safe value (review round 5, F2)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    $super->exec(fgdpSql('ALTER DATABASE ":db" SET standard_conforming_strings = on; ALTER ROLE ":runtime" SET session_replication_role = origin'));
    try {
        DB::purge('fgdp_runtime');
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        expect($code)->toBe(DatabasePrivilegeCheck::MATCH, $output);
    } finally {
        $super->exec(fgdpSql('ALTER DATABASE ":db" RESET standard_conforming_strings; ALTER ROLE ":runtime" RESET session_replication_role'));
    }
})->skip($fgdpSkip, $fgdpSkipReason);

// ---------------------------------------------------------------- Group B, R6-B3c (pack 63c §1)

/** The restricted built-in's initial privilege as initdb records it: EXECUTE for the bootstrap superuser only. */
const FGDP_RESTORE_INIT_PRIVS = "INSERT INTO pg_catalog.pg_init_privs SELECT 'pg_catalog.pg_read_binary_file(text)'::regprocedure, 'pg_catalog.pg_proc'::regclass, 0, 'i', ARRAY[pg_catalog.makeaclitem(10, 10, 'EXECUTE', false)] WHERE NOT EXISTS (SELECT 1 FROM pg_catalog.pg_init_privs WHERE objoid = 'pg_catalog.pg_read_binary_file(text)'::regprocedure AND classoid = 'pg_catalog.pg_proc'::regclass)";

/** The structural comparison alone, as the runtime, in a pinned read-only transaction: no R6-B3 rule runs. */
function fgdpSurfaceDifference(): array
{
    DB::purge('fgdp_runtime');
    $runtime = DB::connection('fgdp_runtime');
    $runtime->beginTransaction();
    try {
        $runtime->statement('SET TRANSACTION READ ONLY');
        RuntimeGrantPlan::pinSession($runtime);
        $surface = RuntimePrivilegeSurface::enumerate($runtime, fgdpEnv('OWNER'));
        $expected = (new RuntimePrivilegeSurface(new RuntimePrivilegeRegistry))->expected($runtime, RuntimePrivilegeSurface::baseline()['lines']);

        return RuntimePrivilegeSurface::compare($surface, $expected['required'], $expected['permitted']);
    } finally {
        $runtime->rollBack();
    }
}

/**
 * No configured role name of the cluster, in any case, anywhere in $output. The bootstrap superuser may be
 * called postgres, which the built-in postgresql_fdw_validator contains: a raw baseline is checked without it
 * (the generator itself refuses a role that is a whole identifier of a line).
 */
function fgdpNoRoleName(string $output, bool $withSuperuser = true): void
{
    foreach (array_filter([fgdpEnv('RUNTIME'), fgdpEnv('OWNER'), fgdpEnv('MIGRATOR'), $withSuperuser ? fgdpEnv('SUPERUSER') : null, fgdpEnv('NON_MEMBER')]) as $role) {
        expect(stripos($output, $role))->toBeFalse("a role name appears in the output: {$output}");
    }
}

/** $alter (an ALTER … prefix) OWNER TO the bootstrap superuser, OID 10, whatever the cluster calls it. */
function fgdpToBootstrap(string $alter): string
{
    return "DO \$fgdp\$ BEGIN EXECUTE pg_catalog.format('{$alter} OWNER TO %I', (SELECT rolname FROM pg_catalog.pg_roles WHERE oid = 10)); END \$fgdp\$";
}

/**
 * A fresh database with the allowlisted extensions, reachable as the runtime through the `fgdp_fresh`
 * connection; the runtime is then a plain login holding exactly the two monitoring roles WITH INHERIT TRUE
 * (the provisioning). Dropped afterwards whatever happens. $body receives a superuser handle on it.
 *
 * Review round 1, #5a: the database is copied from template0, which accepts no connection and so still
 * holds initdb's catalogs exactly, however the drift cases changed the role-test database or template1.
 * The shared catalogs (roles, memberships, databases, tablespaces, parameter ACLs) cannot be made fresh
 * inside a shared cluster; the generator refuses them when they differ (round 1, #3), and a truly fresh
 * initdb is PB-7's CI step (`--print-baseline`, then a byte comparison with the fixture).
 */
function fgdpWithFreshDatabase(Closure $body): void
{
    $super = fgdpSuper();
    $super->exec('DROP DATABASE IF EXISTS fgdp_fresh WITH (FORCE)');
    $super->exec('CREATE DATABASE fgdp_fresh TEMPLATE template0');
    try {
        $fresh = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=fgdp_fresh', fgdpEnv('SOCKET_DIR'), fgdpEnv('PORT')), fgdpEnv('SUPERUSER'), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $fresh->exec('CREATE EXTENSION pg_stat_statements');
        config(['database.connections.fgdp_fresh' => array_merge((array) config('database.connections.fgdp_runtime'), ['database' => 'fgdp_fresh'])]);
        DB::purge('fgdp_fresh');
        $body($fresh);
        $fresh = null;
    } finally {
        DB::purge('fgdp_fresh');
        $super->exec('DROP DATABASE IF EXISTS fgdp_fresh WITH (FORCE)');
    }
}

it('regenerates the pinned baseline byte for byte from a fresh database (R6-B3c, C3)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    fgdpWithFreshDatabase(function (): void {
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fresh', '--print-baseline' => true]);

        expect($code)->toBe(0, $output)
            ->and(hash('sha256', $output))->toBe(FGDP_BASELINE_SHA256)
            ->and($output)->toBe((string) file_get_contents(base_path('tests/Fixtures/r6/pg16-runtime-baseline.txt')));
        fgdpNoRoleName($output, false);
    });
})->skip($fgdpSkip, $fgdpSkipReason);

it('generates the baseline beside a role whose name is part of a built-in name, and refuses one equal to a built-in name (R6-B3c, C3)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();

    fgdpWithFreshDatabase(function () use ($super): void {
        // Part of postgresql_fdw_validator, as a bootstrap superuser called postgres would be.
        $super->exec('CREATE ROLE fdw_validator NOLOGIN');
        try {
            [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fresh', '--print-baseline' => true]);
            expect($code)->toBe(0, $output)->and(hash('sha256', $output))->toBe(FGDP_BASELINE_SHA256);
        } finally {
            $super->exec('DROP ROLE fdw_validator');
        }

        // Equal to the built-in function abs: the line would name the role, so nothing is printed.
        $super->exec('CREATE ROLE abs NOLOGIN');
        try {
            [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fresh', '--print-baseline' => true]);
            expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
                ->and($output)->toContain('ERROR a baseline line names a role of this cluster')
                ->and($output)->not->toContain('pg_catalog.abs(');
        } finally {
            $super->exec('DROP ROLE abs');
        }
    });
})->skip($fgdpSkip, $fgdpSkipReason);

it('refuses to generate a baseline when an initial privilege was changed, and generates it again once undone (R6-B3c, review round 1, #3)', function (string $inject, string $undo, string $reason) {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    fgdpWithFreshDatabase(function (PDO $fresh) use ($inject, $undo, $reason): void {
        $fresh->exec(fgdpSql($inject));
        try {
            [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fresh', '--print-baseline' => true]);
            expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY, $output)
                ->and($output)->toMatch('/^ERROR the (database|cluster) is not fresh: /m')
                ->and($output)->toContain($reason)
                ->and($output)->toContain('DB PRIVILEGE BASELINE: CANNOT GENERATE');
            fgdpNoRoleName($output);
        } finally {
            $fresh->exec(fgdpSql($undo));
        }
        DB::purge('fgdp_fresh');
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_fresh', '--print-baseline' => true]);
        expect($code)->toBe(0, $output)->and(hash('sha256', $output))->toBe(FGDP_BASELINE_SHA256);
    });
})->with([
    'a catalog readable by PUBLIC' => ['GRANT SELECT ON pg_catalog.pg_authid TO PUBLIC', 'REVOKE SELECT ON pg_catalog.pg_authid FROM PUBLIC', 'the database is not fresh: 1 object(s) carry privileges that differ from their initial privileges'],
    'a catalog column readable by PUBLIC' => ['GRANT SELECT (subconninfo) ON pg_catalog.pg_subscription TO PUBLIC', 'REVOKE SELECT (subconninfo) ON pg_catalog.pg_subscription FROM PUBLIC', 'the database is not fresh: 1 object(s) carry privileges'],
    'a restricted built-in executable by PUBLIC' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) TO PUBLIC', 'REVOKE EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) FROM PUBLIC', 'the database is not fresh: 1 object(s) carry privileges'],
    'an information_schema view revoked from PUBLIC' => ['REVOKE SELECT ON information_schema.tables FROM PUBLIC', 'GRANT SELECT ON information_schema.tables TO PUBLIC', 'the database is not fresh: 1 object(s) carry privileges'],
    'an extension member beyond its extension\'s initial privilege' => ['GRANT EXECUTE ON FUNCTION public.pg_stat_statements_reset(oid, oid, bigint) TO PUBLIC', 'REVOKE EXECUTE ON FUNCTION public.pg_stat_statements_reset(oid, oid, bigint) FROM PUBLIC', 'the database is not fresh: 1 object(s) carry privileges'],
    'a tablespace grant' => ['GRANT CREATE ON TABLESPACE pg_default TO PUBLIC', 'REVOKE CREATE ON TABLESPACE pg_default FROM PUBLIC', 'the database is not fresh: 1 object(s) carry privileges'],
    'a parameter ACL' => ['GRANT SET ON PARAMETER log_statement TO PUBLIC', 'REVOKE SET ON PARAMETER log_statement FROM PUBLIC', 'the cluster is not fresh: pg_parameter_acl holds 1 row(s)'],
    // Review round 2, N2: a new owner's default ACL is not an initial privilege.
    'a built-in function owned by the login' => ['ALTER FUNCTION pg_catalog.pg_sleep(double precision) OWNER TO ":runtime"', fgdpToBootstrap('ALTER FUNCTION pg_catalog.pg_sleep(double precision)'), 'object(s) created by initdb are no longer owned by the bootstrap superuser'],
    'a built-in type owned by a monitoring role' => ['ALTER TYPE pg_catalog.int4 OWNER TO pg_read_all_stats', fgdpToBootstrap('ALTER TYPE pg_catalog.int4'), 'object(s) created by initdb are no longer owned by the bootstrap superuser'],
    'the information_schema schema owned by the login' => ['ALTER SCHEMA information_schema OWNER TO ":runtime"', fgdpToBootstrap('ALTER SCHEMA information_schema'), 'object(s) created by initdb are no longer owned by the bootstrap superuser'],
    'an information_schema view owned by a monitoring role' => ['ALTER VIEW information_schema.tables OWNER TO pg_read_all_stats', fgdpToBootstrap('ALTER VIEW information_schema.tables'), 'object(s) created by initdb are no longer owned by the bootstrap superuser'],
    // Review round 2, N3: a member added outside its extension's schema is an object beyond the extension.
    'a function added to pg_stat_statements from pg_catalog' => ["CREATE FUNCTION pg_catalog.zz_probe() RETURNS integer LANGUAGE sql AS 'select 1'; ALTER EXTENSION pg_stat_statements ADD FUNCTION pg_catalog.zz_probe()", 'ALTER EXTENSION pg_stat_statements DROP FUNCTION pg_catalog.zz_probe(); DROP FUNCTION pg_catalog.zz_probe()', 'the database is not fresh: 1 object(s) or default(s) exist beyond initdb and the allowlisted extensions'],
    // Review round 2, N6: a column whose initial grant was revoked entirely has a NULL ACL again.
    'a catalog column grant revoked from PUBLIC' => ['REVOKE SELECT (subname) ON pg_catalog.pg_subscription FROM PUBLIC', 'GRANT SELECT (subname) ON pg_catalog.pg_subscription TO PUBLIC', 'the database is not fresh: 1 object(s) carry privileges that differ from their initial privileges'],
])->skip($fgdpSkip, $fgdpSkipReason);

it('refuses to generate a baseline from a database that is not fresh, and names no role (R6-B3c, C3)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime', '--print-baseline' => true]);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('ERROR the database is not fresh')
        ->and($output)->toContain('DB PRIVILEGE BASELINE: CANNOT GENERATE');
    fgdpNoRoleName($output);
})->skip($fgdpSkip, $fgdpSkipReason);

it('cannot verify, as the runtime, against a baseline that does not match its pin (R6-B3c, C2)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    config(['runtime_privilege_classes.baseline.sha256' => str_repeat('a', 64)]);

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime', '--json' => true]);
    $report = json_decode($output, true);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($report['verdict'])->toBe('CANNOT VERIFY')
        ->and($report['problems'])->toBe(['the PostgreSQL 16 baseline fixture does not match its pinned SHA-256.'])
        ->and($report)->toHaveKeys(['status', 'problems', 'passed', 'notes', 'surface'])
        ->and($report['surface'])->toBeNull();
})->skip($fgdpSkip, $fgdpSkipReason);

it('matches structurally on the provisioned layout, with the JSON shape kept and extended (R6-B3c, C4)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime', '--json' => true]);
    $report = json_decode($output, true);

    expect($code)->toBe(DatabasePrivilegeCheck::MATCH, $output)
        ->and(array_keys($report))->toBe(['verdict', 'status', 'problems', 'passed', 'notes', 'surface'])
        ->and($report['surface']['verdict'])->toBe('MATCH')
        ->and($report['surface']['extra'])->toBe([])
        ->and($report['surface']['missing'])->toBe([])
        ->and($report['surface']['baseline_sha256'])->toBe(FGDP_BASELINE_SHA256)
        ->and($report['surface']['items'])->toBe($report['surface']['required'] + $report['surface']['permitted_present'])
        ->and($report['passed'])->toContain("the runtime's privilege surface equals the expected set exactly: the pinned PostgreSQL 16 baseline, the registry and the layout (R6-B3c)")
        ->and(fgdpSurfaceDifference())->toBe(['extra' => [], 'missing' => []]);
    fgdpNoRoleName($output);
})->skip($fgdpSkip, $fgdpSkipReason);

it('reports a privilege no R6-B3 rule enumerates as an extra surface item, and matches once it is undone (R6-B3c)', function (string $inject, string $undo, string $line) {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();

    $super->exec(fgdpSql($inject));
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        $failures = array_values(array_filter(explode("\n", $output), fn (string $l): bool => str_starts_with($l, 'FAIL  ')));

        expect($code)->toBe(DatabasePrivilegeCheck::DRIFT, $output)
            ->and($output)->toContain("FAIL  surface: extra {$line}\n")
            // No R6-B3 rule sees it: every failure is a structural one.
            ->and(array_values(array_filter($failures, fn (string $l): bool => ! str_starts_with($l, 'FAIL  surface: '))))->toBe([]);
        fgdpNoRoleName($output);
    } finally {
        $super->exec(fgdpSql($undo));
    }

    DB::purge('fgdp_runtime');
    expect(fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime'])[0])->toBe(DatabasePrivilegeCheck::MATCH);
})->with([
    'USAGE on a new type outside public' => ["CREATE TYPE fgdp_ext.fgdp_kind AS ENUM ('a'); GRANT USAGE ON TYPE fgdp_ext.fgdp_kind TO \":runtime\"", 'DROP TYPE fgdp_ext.fgdp_kind', 'type|fgdp_ext.fgdp_kind|USAGE|RUNTIME|NO'],
    'USAGE on a foreign-data wrapper' => ['CREATE FOREIGN DATA WRAPPER fgdp_fdw; GRANT USAGE ON FOREIGN DATA WRAPPER fgdp_fdw TO ":runtime"', 'DROP FOREIGN DATA WRAPPER fgdp_fdw', 'foreign-data wrapper|fgdp_fdw|USAGE|RUNTIME|NO'],
    'CREATE on a tablespace' => ['GRANT CREATE ON TABLESPACE pg_default TO ":runtime"', 'REVOKE CREATE ON TABLESPACE pg_default FROM ":runtime"', 'tablespace|pg_default|CREATE|RUNTIME|NO'],
    'USAGE on a language, granted to the runtime itself' => ['GRANT USAGE ON LANGUAGE plpgsql TO ":runtime"', 'REVOKE USAGE ON LANGUAGE plpgsql FROM ":runtime"', 'language|plpgsql|USAGE|RUNTIME|NO'],
    // Review round 4 residual: the catalog TOAST tables (here pg_authid's, which holds rolpassword).
    'a readable catalog TOAST table' => ['GRANT SELECT ON pg_toast.pg_toast_1260 TO PUBLIC', 'REVOKE SELECT ON pg_toast.pg_toast_1260 FROM PUBLIC', 'toast table|toast of pg_catalog.pg_authid|SELECT|PUBLIC|NO'],
    // Review round 3, findings 1a/1b: the initial-privilege baseline lived in a mutable catalog.
    'a restricted built-in whose initial privilege was erased (round 3, 1a)' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) TO pg_read_all_stats; ALTER EXTENSION plpgsql ADD FUNCTION pg_catalog.pg_read_binary_file(text); ALTER EXTENSION plpgsql DROP FUNCTION pg_catalog.pg_read_binary_file(text)', 'REVOKE EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) FROM pg_read_all_stats; '.FGDP_RESTORE_INIT_PRIVS, 'function|pg_catalog.pg_read_binary_file(text)|EXECUTE|pg_read_all_stats|NO'],
    'a restricted built-in whose initial privilege was forged (round 3, 1b)' => ['GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) TO pg_read_all_stats; ALTER EXTENSION plpgsql ADD FUNCTION pg_catalog.pg_read_binary_file(text)', 'ALTER EXTENSION plpgsql DROP FUNCTION pg_catalog.pg_read_binary_file(text); REVOKE EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) FROM pg_read_all_stats; '.FGDP_RESTORE_INIT_PRIVS, 'function|extension:plpgsql.pg_read_binary_file(text)|EXECUTE|pg_read_all_stats|NO'],
    // Review round 4 residual: a readable view moved into an allowlisted extension.
    'a credential view moved into an allowlisted extension' => ['CREATE VIEW fgdp_ext.fgdp_authview AS SELECT rolname FROM pg_catalog.pg_authid; GRANT SELECT ON fgdp_ext.fgdp_authview TO PUBLIC; ALTER EXTENSION pg_stat_statements ADD VIEW fgdp_ext.fgdp_authview', 'ALTER EXTENSION pg_stat_statements DROP VIEW fgdp_ext.fgdp_authview; DROP VIEW fgdp_ext.fgdp_authview', 'view|extension:pg_stat_statements.fgdp_authview|SELECT|PUBLIC|NO'],
    // R6-B3c review round 1, #1: a member added outside its extension's schema under a real member's name
    // keeps its real schema, so it cannot reproduce the baseline's line for that member.
    'a credential view named like a member, added to the extension from public' => ['CREATE VIEW public.pg_stat_statements AS SELECT rolname, rolpassword FROM pg_catalog.pg_authid; GRANT SELECT ON public.pg_stat_statements TO PUBLIC; ALTER EXTENSION pg_stat_statements ADD VIEW public.pg_stat_statements', 'ALTER EXTENSION pg_stat_statements DROP VIEW public.pg_stat_statements; DROP VIEW public.pg_stat_statements', 'view|public.pg_stat_statements|SELECT|PUBLIC|NO'],
    'a function named like a member, added to the extension from pg_catalog' => ["CREATE FUNCTION pg_catalog.pg_stat_statements(boolean) RETURNS integer LANGUAGE sql AS 'select 1'; ALTER EXTENSION pg_stat_statements ADD FUNCTION pg_catalog.pg_stat_statements(boolean)", 'ALTER EXTENSION pg_stat_statements DROP FUNCTION pg_catalog.pg_stat_statements(boolean); DROP FUNCTION pg_catalog.pg_stat_statements(boolean)', 'function|pg_catalog.pg_stat_statements(boolean)|EXECUTE|PUBLIC|NO'],
])->skip($fgdpSkip, $fgdpSkipReason);

it('matches with pg_stat_statements in public as well as in its own schema (R6-B3c, review round 1, #1)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();

    // PB-8 §5: production keeps the extension in `public`; the runtime then needs no USAGE on another schema.
    $super->exec(fgdpSql('ALTER EXTENSION pg_stat_statements SET SCHEMA public; REVOKE USAGE ON SCHEMA fgdp_ext FROM ":runtime"'));
    try {
        DB::purge('fgdp_runtime');
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        expect($code)->toBe(DatabasePrivilegeCheck::MATCH, $output)
            ->and(fgdpSurfaceDifference())->toBe(['extra' => [], 'missing' => []]);
    } finally {
        $super->exec(fgdpSql('ALTER EXTENSION pg_stat_statements SET SCHEMA fgdp_ext; GRANT USAGE ON SCHEMA fgdp_ext TO ":runtime"'));
    }

    DB::purge('fgdp_runtime');
    expect(fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime'])[0])->toBe(DatabasePrivilegeCheck::MATCH);
})->skip($fgdpSkip, $fgdpSkipReason);

it('finds an unenumerated kind and a missing item with the structural comparison alone, no R6-B3 rule involved (R6-B3c)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    expect(fgdpSurfaceDifference())->toBe(['extra' => [], 'missing' => []]);

    // A parameter ACL and a type grant: exactly these two items, nothing else.
    $super->exec(fgdpSql("GRANT SET ON PARAMETER log_statement TO PUBLIC; CREATE TYPE fgdp_ext.fgdp_level AS ENUM ('a'); REVOKE USAGE ON TYPE fgdp_ext.fgdp_level FROM PUBLIC; GRANT USAGE ON TYPE fgdp_ext.fgdp_level TO \":runtime\""));
    try {
        expect(fgdpSurfaceDifference())->toBe(['extra' => ['parameter|log_statement|SET|PUBLIC|NO', 'type|fgdp_ext.fgdp_level|USAGE|RUNTIME|NO'], 'missing' => []]);
    } finally {
        $super->exec('REVOKE SET ON PARAMETER log_statement FROM PUBLIC; DROP TYPE fgdp_ext.fgdp_level');
    }

    // A class privilege taken away is a missing item.
    $super->exec(fgdpSql('REVOKE INSERT ON public.users FROM ":runtime"'));
    try {
        expect(fgdpSurfaceDifference())->toBe(['extra' => [], 'missing' => ['table|public.users|INSERT|RUNTIME|NO']]);
    } finally {
        $super->exec(fgdpSql('GRANT INSERT ON public.users TO ":runtime"'));
    }

    // Review round 1, #5f: missing items of other kinds. A monitoring membership dropped (no R6-B3 rule
    // asks for it), and a baseline function revoked from PUBLIC (a hardening the exact set also reports).
    $super->exec(fgdpSql('REVOKE pg_read_all_settings FROM ":runtime"; REVOKE EXECUTE ON FUNCTION pg_catalog.pg_sleep(double precision) FROM PUBLIC'));
    try {
        expect(fgdpSurfaceDifference())->toBe(['extra' => [], 'missing' => [
            'function|pg_catalog.pg_sleep(double precision)|EXECUTE|PUBLIC|NO',
            'membership|pg_read_all_settings|INHERIT|RUNTIME|NO',
            'membership|pg_read_all_settings|MEMBER|RUNTIME|NO',
        ]]);
    } finally {
        $super->exec(fgdpSql('GRANT pg_read_all_settings TO ":runtime"; GRANT EXECUTE ON FUNCTION pg_catalog.pg_sleep(double precision) TO PUBLIC'));
    }
    expect(fgdpSurfaceDifference())->toBe(['extra' => [], 'missing' => []]);
})->skip($fgdpSkip, $fgdpSkipReason);

it('never prints a role name: not in the surface lines, the JSON or the capped text (R6-B3c)', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    // A schema named after the runtime, and the owner role reached: hundreds of extra items. Review round 1,
    // #4: two more roles, one with a non-ASCII byte and one with `|`, each owning a schema the runtime can use,
    // whose names a surface line prints escaped and quoted.
    $unusual = ["fgdp_r\u{F4}le_x", 'fgdp_ops|admin'];
    $super->exec(fgdpSql('CREATE SCHEMA ":runtime_s"; GRANT USAGE ON SCHEMA ":runtime_s" TO ":runtime"; GRANT ":owner" TO ":runtime"'));
    foreach ($unusual as $role) {
        $super->exec(fgdpSql("CREATE ROLE \"{$role}\" NOLOGIN; CREATE SCHEMA AUTHORIZATION \"{$role}\"; GRANT USAGE ON SCHEMA \"{$role}\" TO \":runtime\""));
    }
    try {
        [$code, $json] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime', '--json' => true]);
        [, $text] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        $report = json_decode($json, true);
        $printed = implode("\n", [$text, ...$report['problems'], ...$report['surface']['extra'], ...$report['surface']['missing']]);
        foreach ($unusual as $role) {
            foreach ([$role, RuntimePrivilegeSurface::escape($role)] as $form) {
                expect(stripos($printed, $form))->toBeFalse("role name printed as {$form}");
            }
        }

        expect($code)->toBe(DatabasePrivilegeCheck::DRIFT)
            ->and($report['surface']['verdict'])->toBe('DRIFT')
            ->and($report['surface']['extra'])->toContain('schema|"<name withheld>"|USAGE|RUNTIME|NO')
            ->and($report['surface']['extra'])->toContain('schema|<name withheld>_s|USAGE|RUNTIME|NO')
            ->and($report['surface']['extra'])->toContain('membership|OWNER|MEMBER|RUNTIME|NO')
            ->and($report['surface']['extra'])->toContain('ownership|table public.users|OWNER|OWNER|NO')
            ->and(count($report['surface']['extra']))->toBeGreaterThan(RuntimePrivilegeSurface::PRINTED_DIFFERENCES)
            ->and(substr_count($text, 'FAIL  surface: extra '))->toBe(RuntimePrivilegeSurface::PRINTED_DIFFERENCES)
            ->and($text)->toContain('more extra item(s) not listed here');
        fgdpNoRoleName($json);
        fgdpNoRoleName($text);
    } finally {
        $super->exec(fgdpSql('REVOKE ":owner" FROM ":runtime"; DROP SCHEMA ":runtime_s"'));
        foreach ($unusual as $role) {
            $super->exec("DROP SCHEMA \"{$role}\"; DROP ROLE \"{$role}\"");
        }
    }
})->skip($fgdpSkip, $fgdpSkipReason);
