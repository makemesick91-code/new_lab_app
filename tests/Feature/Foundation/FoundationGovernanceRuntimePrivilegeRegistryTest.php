<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B2: the runtime privilege class registry (rule 245 #6;
| owner decisions D-Q3-a and D-Q3-b).
|
| - Every table of the migrated schema has exactly one class. The 186 historical
|   tables are classified in config/runtime_privilege_classes.php, without
|   editing their migrations. A later migration declares each table it creates
|   with #[RuntimePrivilegeClass].
| - The check fails closed: an unclassified table, a stale entry, an unknown
|   class, a missing updatable column, a double classification or an undeclared
|   new table is an error.
| - No migration runs GRANT, REVOKE or role/ownership SQL.
|
| Selected by the `FoundationGovernance` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fgrpr`.
*/

use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Support\Database\RuntimePrivilegeClass;
use App\Support\Database\RuntimePrivilegeRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/** A scratch migration directory: a historical cutoff file, plus $files (name => PHP source). */
function fgrprMigrations(array $files): string
{
    $dir = sys_get_temp_dir().'/fgrpr-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/2000_01_01_000000_cutoff.php', "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n");
    foreach ($files as $name => $source) {
        file_put_contents("{$dir}/{$name}.php", $source);
    }
    fgrprTrack($dir);

    return $dir;
}

/** Scratch directories to remove after the test (the Pest test proxy cannot hold an array). */
function fgrprTrack(?string $dir = null, bool $flush = false): array
{
    static $dirs = [];
    if ($dir !== null) {
        $dirs[] = $dir;
    }
    if ($flush) {
        [$all, $dirs] = [$dirs, []];

        return $all;
    }

    return $dirs;
}

/** @param  array<string, mixed>  $tables */
function fgrprRegistry(array $tables, string $migrationPath, string $cutoff = '2000_01_01_000000_cutoff'): RuntimePrivilegeRegistry
{
    return new RuntimePrivilegeRegistry(['historical_through' => $cutoff, 'tables' => $tables], $migrationPath);
}

function fgrprMigration(string $attributes, string $body = ''): string
{
    return "<?php\nuse App\\Support\\Database\\RuntimePrivilegeClass;\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n"
        ."return new {$attributes} class extends Migration {\n    public function up(): void {\n        {$body}\n    }\n};\n";
}

afterEach(function () {
    foreach (fgrprTrack(flush: true) as $dir) {
        File::deleteDirectory($dir);
    }
});

it('classifies every table of the migrated schema exactly once, with no other problem', function () {
    $registry = new RuntimePrivilegeRegistry;

    expect($registry->verify(DB::connection()))->toBe([])
        ->and($registry->historicalThrough())->toBe('2026_11_18_300001_add_unassigned_reason_to_trx_rme_patient_doctor_assignments_table')
        ->and(count(config('runtime_privilege_classes.tables')))->toBe(186);
});

it('records the evidence-based classes of the append-only, column-limited and read-only tables', function () {
    $tables = config('runtime_privilege_classes.tables');

    foreach (['sys_audit_logs', 'trx_lab_order_status_logs', 'trx_rme_billing_dispute_events', 'trx_rme_invoice_correction_events',
        'trx_rme_overpayment_obligation_events', 'trx_rme_refund_request_events', 'trx_rme_refund_settlement_events',
        'trx_rme_record_versions', 'trx_rme_invoice_document_versions', 'trx_rme_receipt_snapshots',
        'trx_patient_rm_paper_card_registrations'] as $table) {
        expect($tables[$table] ?? null)->toBe(RuntimePrivilegeClass::APPEND_ONLY, $table);
    }

    expect($tables['trx_inventory_movements'])->toBe([RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, ['inventory_batch_id', 'updated_at']])
        ->and($tables['trx_rme_payments'])->toBe([RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, ['patient_id', 'updated_at']])
        ->and($tables['trx_rme_amendments'][0])->toBe(RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS)
        ->and($tables['migrations'])->toBe(RuntimePrivilegeClass::READ_ONLY_RUNTIME)
        ->and((new RuntimePrivilegeRegistry)->summary())->toBe([
            RuntimePrivilegeClass::FULL_DML => 171,
            RuntimePrivilegeClass::APPEND_ONLY => 11,
            RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS => 3,
            RuntimePrivilegeClass::READ_ONLY_RUNTIME => 1,
        ]);
});

it('lets an amendment change only its decision columns, never what was requested', function () {
    [, $columns] = config('runtime_privilege_classes.tables.trx_rme_amendments');

    expect(array_intersect($columns, RmeAmendment::REQUEST_COLUMNS))->toBe([])
        ->and($columns)->not->toContain('id');
});

it('fails closed on a table that has no class', function () {
    Schema::create('zz_r6b2_unclassified', function (Blueprint $table) {
        $table->id();
    });

    expect((new RuntimePrivilegeRegistry)->verify(DB::connection()))
        ->toContain('zz_r6b2_unclassified has no runtime privilege class: classify it in config/runtime_privilege_classes.php only if a migration up to 2026_11_18_300001_add_unassigned_reason_to_trx_rme_patient_doctor_assignments_table created it, otherwise declare #[RuntimePrivilegeClass] on the migration that creates it');
});

it('reports a stale entry, an unknown class and every column mistake', function () {
    $tables = config('runtime_privilege_classes.tables');
    $tables['zz_r6b2_missing'] = RuntimePrivilegeClass::FULL_DML;
    $tables['users'] = 'EVERYTHING';
    $tables['trx_inventory_movements'] = [RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, ['inventory_batch_id', 'no_such_column']];
    $tables['sessions'] = [RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, []];
    $tables['cache'] = [RuntimePrivilegeClass::FULL_DML, ['value']];

    $errors = (new RuntimePrivilegeRegistry(['historical_through' => config('runtime_privilege_classes.historical_through'), 'tables' => $tables]))
        ->verify(DB::connection());

    expect($errors)->toContain('zz_r6b2_missing is classified (registry) but does not exist')
        ->toContain("users: unknown runtime privilege class 'EVERYTHING' (registry)")
        ->toContain('trx_inventory_movements: updatable column no_such_column does not exist')
        ->toContain('sessions: APPEND_ONLY_WITH_COLUMNS names no updatable column (registry)')
        ->toContain('cache: FULL_DML takes no updatable columns (registry)');
});

it('reads the class of a new table from the migration that creates it', function () {
    $dir = fgrprMigrations([
        '2030_01_01_000001_create_zz_new' => fgrprMigration(
            "#[RuntimePrivilegeClass('zz_new', RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, ['status'])]",
            "Schema::create('zz_new', fn (\$t) => \$t->id());",
        ),
    ]);

    [$entries, $errors] = fgrprRegistry([], $dir)->expected();

    expect($errors)->toBe([])
        ->and($entries['zz_new'])->toBe(['class' => 'APPEND_ONLY_WITH_COLUMNS', 'columns' => ['status'], 'source' => 'migration 2030_01_01_000001_create_zz_new']);
});

it('refuses a new migration that creates a table without declaring it, or declares one twice', function () {
    $dir = fgrprMigrations([
        '2030_01_01_000001_create_zz_undeclared' => fgrprMigration('', "Schema::create('zz_undeclared', fn (\$t) => \$t->id());"),
        '2030_01_01_000002_redeclare_users' => fgrprMigration("#[RuntimePrivilegeClass('users', RuntimePrivilegeClass::FULL_DML)]"),
        '2030_01_01_000003_bogus_class' => fgrprMigration("#[RuntimePrivilegeClass('zz_bogus', 'SUPERPOWER')]"),
        '2030_01_01_000004_not_anonymous' => "<?php\nreturn 1;\n",
    ]);

    [, $errors] = fgrprRegistry(['users' => RuntimePrivilegeClass::FULL_DML], $dir)->expected();

    expect($errors)->toContain("migration 2030_01_01_000001_create_zz_undeclared creates zz_undeclared without #[RuntimePrivilegeClass('zz_undeclared', …)]")
        ->toContain('users is classified twice: registry and migration 2030_01_01_000002_redeclare_users')
        ->toContain("zz_bogus: unknown runtime privilege class 'SUPERPOWER' (migration 2030_01_01_000003_bogus_class)")
        ->toContain('migration 2030_01_01_000004_not_anonymous must return an anonymous migration class so its #[RuntimePrivilegeClass] can be read');
});

it('ignores the attribute on a historical migration: those tables are classified in the registry only', function () {
    $dir = fgrprMigrations([]);
    file_put_contents($dir.'/1999_01_01_000000_old.php', fgrprMigration("#[RuntimePrivilegeClass('zz_old', RuntimePrivilegeClass::FULL_DML)]"));

    [$entries, $errors] = fgrprRegistry([], $dir)->expected();

    expect($entries)->not->toHaveKey('zz_old')->and($errors)->toBe([]);
});

it('fails when the historical cutoff is not a migration', function () {
    [, $errors] = fgrprRegistry([], fgrprMigrations([]), '2000_01_01_999999_nope')->expected();

    expect($errors[0] ?? '')->toStartWith("historical_through '2000_01_01_999999_nope' is not a migration");
});

it('finds no migration that runs GRANT, REVOKE or role and ownership SQL', function () {
    expect((new RuntimePrivilegeRegistry)->forbiddenSqlErrors())->toBe([]);
});

it('refuses a migration that would grant, revoke, change a role or change ownership (D-Q3-b)', function (string $statement, string $label) {
    $dir = fgrprMigrations([
        '2030_01_01_000001_privileged' => fgrprMigration('', "Illuminate\\Support\\Facades\\DB::statement('{$statement}');"),
    ]);

    expect(fgrprRegistry([], $dir)->forbiddenSqlErrors())
        ->toContain("migration 2030_01_01_000001_privileged line 7 runs {$label}: privileges and roles are provisioning (R6-B3), never a migration (D-Q3-b)");
})->with([
    ['GRANT SELECT ON users TO app_runtime', 'GRANT'],
    ['REVOKE ALL ON users FROM PUBLIC', 'REVOKE'],
    ['ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO x', 'ALTER DEFAULT PRIVILEGES'],
    ['CREATE ROLE dms_extra NOLOGIN', 'role DDL'],
    ['ALTER TABLE users OWNER TO dms_owner', 'OWNER TO'],
    ['REASSIGN OWNED BY a TO b', 'REASSIGN OWNED'],
    ['SET LOCAL ROLE dms_owner', 'SET ROLE'],
    // R6-B2b review round 1: every other way a session's role can change.
    ['SET SESSION AUTHORIZATION legacy_author', 'SET SESSION AUTHORIZATION'],
    ['RESET SESSION AUTHORIZATION', 'RESET SESSION AUTHORIZATION'],
    ['RESET ROLE', 'RESET ROLE'],
    ["SELECT set_config('role', 'dms_owner', false)", 'set_config role'],
    ['DISCARD ALL', 'DISCARD ALL'],
]);

it('agrees with the guards PostgreSQL already enforces: a table that refuses UPDATE or DELETE is never FULL_DML', function () {
    $guarded = collect(DB::select(<<<'SQL'
        SELECT DISTINCT c.relname
        FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE NOT t.tgisinternal AND n.nspname = current_schema()
          AND (t.tgtype & 2) = 2 AND (t.tgtype & 1) = 1 AND (t.tgtype & 24) <> 0
        SQL))->pluck('relname');

    expect($guarded)->not->toBeEmpty();
    foreach ($guarded as $table) {
        $class = config("runtime_privilege_classes.tables.{$table}");
        expect(is_array($class) ? $class[0] : $class)->not->toBe(RuntimePrivilegeClass::FULL_DML, $table);
    }
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL triggers');
