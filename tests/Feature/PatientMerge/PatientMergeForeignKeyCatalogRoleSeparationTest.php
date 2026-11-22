<?php

use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Repositories\PatientMergeOwnershipRepository;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use App\Support\Database\ForeignKeyCatalog;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\NonOwnerRole;

/*
 * DMS-SPN4-READY-PILOT-1 G6 / R6-B1 — the patient-merge guard against unregistered
 * patient foreign keys must give the same answer whoever owns the tables.
 *
 * The guard used information_schema.constraint_column_usage, which only lists
 * tables owned by the current role. After the rule 245 ownership split the runtime
 * owns nothing, the view returns zero rows, and an unregistered patient table no
 * longer blocked a merge (fail OPEN). The guard now reads pg_catalog, and a catalog
 * that shows no patient foreign key at all blocks the merge (fail closed).
 *
 * Selected by the `PatientMerge` token in BOTH critical-gate variants (CG-19).
 */

require_once __DIR__.'/helpers.php';

/** The pre-fix lookup, kept verbatim as the regression reference for the root cause. */
function r6b1LegacyPatientForeignKeyRows(): int
{
    return count(DB::select(
        "SELECT kcu.table_name, kcu.column_name
           FROM information_schema.table_constraints tc
           JOIN information_schema.key_column_usage kcu
             ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
           JOIN information_schema.constraint_column_usage ccu
             ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
          WHERE tc.constraint_type = 'FOREIGN KEY'
            AND ccu.table_name = 'mst_patients'
            AND tc.table_schema = current_schema()"
    ));
}

/** Every "table.column" with a foreign key to mst_patients, through Laravel's own schema builder. */
function r6b1SchemaBuilderPatientReferences(): array
{
    $references = [];

    foreach (Schema::getTables(schema: (string) DB::scalar('SELECT current_schema()')) as $table) {
        foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
            if (($foreignKey['foreign_table'] ?? null) === 'mst_patients') {
                foreach ($foreignKey['columns'] as $column) {
                    $references[] = $table['name'].'.'.$column;
                }
            }
        }
    }

    $references = array_values(array_unique($references));
    sort($references);

    return $references;
}

function r6b1CatalogPatientReferences(): array
{
    $references = array_values(array_unique(array_map(
        fn (array $fk): string => $fk['table'].'.'.$fk['column'],
        array_filter(app(ForeignKeyCatalog::class)->referencesTo('mst_patients'), fn (array $fk): bool => $fk['in_current_schema']),
    )));
    sort($references);

    return $references;
}

/** Blocker codes a merge submission is refused with ([] when it is accepted). */
function r6b1BlockerCodes(callable $action): array
{
    try {
        $action();
    } catch (PatientMergeBlockedException $blocked) {
        return array_column($blocked->blockers, 'code');
    }

    return [];
}

function r6b1CreateUnregisteredPatientTable(string $table): void
{
    Schema::create($table, function ($blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('patient_id')->constrained('mst_patients');
    });
}

beforeEach(function (): void {
    seedAccessControl();
    $this->requester = pmUser('Supervisor RME');
});

it('reads every patient foreign key from pg_catalog, matching the schema builder exactly', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_catalog introspection is PostgreSQL-only; SQLite keeps the schema-builder path.');
    }

    $catalog = r6b1CatalogPatientReferences();

    expect($catalog)->not->toBe([])
        ->and($catalog)->toBe(r6b1SchemaBuilderPatientReferences())
        ->and(array_diff($catalog, PatientOwnedRecordRegistry::knownReferences()))->toBe([])
        ->and(app(PatientMergeOwnershipRepositoryInterface::class)->unregisteredPatientForeignKeys())->toBe([]);
});

it('gives the non-owner runtime role the same answer as the owner, where information_schema went blind', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $ownerCatalog = r6b1CatalogPatientReferences();
    $ownerLegacyRows = r6b1LegacyPatientForeignKeyRows();
    $role = NonOwnerRole::create();

    [$runtimeCatalog, $runtimeLegacyRows, $runtimeUnregistered, $runtimeOwnsTables] = NonOwnerRole::run($role, fn (): array => [
        r6b1CatalogPatientReferences(),
        r6b1LegacyPatientForeignKeyRows(),
        app(PatientMergeOwnershipRepositoryInterface::class)->unregisteredPatientForeignKeys(),
        (bool) DB::scalar("SELECT pg_has_role(relowner, 'USAGE') FROM pg_catalog.pg_class WHERE oid = 'mst_patients'::regclass"),
    ]);

    // The fixture is truthful: the probe role really does not own the tables.
    expect($runtimeOwnsTables)->toBeFalse()
        // Root cause, reproduced: the old lookup sees the keys as owner and nothing as runtime.
        ->and($ownerLegacyRows)->toBeGreaterThan(0)
        ->and($runtimeLegacyRows)->toBe(0)
        // Fixed lookup: identical answer for both roles, and the registry still covers it.
        ->and($runtimeCatalog)->toBe($ownerCatalog)
        ->and($runtimeUnregistered)->toBe([]);
});

it('reports an unregistered patient foreign key to the non-owner runtime and refuses the merge', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $a = pmPatient();
    $b = pmPatient();
    r6b1CreateUnregisteredPatientTable('trx_r6b1_unregistered_probe');
    $role = NonOwnerRole::create();

    [$unregistered, $codes] = NonOwnerRole::run($role, fn (): array => [
        app(PatientMergeOwnershipRepositoryInterface::class)->unregisteredPatientForeignKeys(),
        r6b1BlockerCodes(fn () => pmSubmittedCase($a, $b, $this->requester)),
    ]);

    expect($unregistered)->toBe(['trx_r6b1_unregistered_probe.patient_id'])
        ->and($codes)->toContain('OWNERSHIP_REGISTRY_INCOMPLETE');
});

it('lets the non-owner runtime submit a merge when every patient foreign key is registered', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $a = pmPatient();
    $b = pmPatient();
    $role = NonOwnerRole::create();

    $codes = NonOwnerRole::run($role, fn (): array => r6b1BlockerCodes(fn () => pmSubmittedCase($a, $b, $this->requester)));

    expect($codes)->not->toContain('OWNERSHIP_REGISTRY_INCOMPLETE');
});

it('reports a patient foreign key from another schema schema-qualified, so it always blocks', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    if (! DB::scalar("SELECT has_database_privilege(current_database(), 'CREATE')")) {
        $this->markTestSkipped('the test database identity cannot create a schema');
    }

    $schema = 'r6b1_other_'.bin2hex(random_bytes(4));
    $current = (string) DB::scalar('SELECT current_schema()');
    DB::statement("CREATE SCHEMA \"{$schema}\"");
    DB::statement("CREATE TABLE \"{$schema}\".\"trx_clinic_visits\" (id bigserial PRIMARY KEY, patient_id bigint NOT NULL REFERENCES \"{$current}\".\"mst_patients\" (id))");

    expect(app(PatientMergeOwnershipRepositoryInterface::class)->unregisteredPatientForeignKeys())
        ->toBe(["{$schema}.trx_clinic_visits.patient_id"]);
});

it('blocks the merge when the catalog shows no patient foreign key at all (fail closed)', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('the empty-catalog sentinel guards the PostgreSQL catalog path.');
    }

    $blind = new class extends ForeignKeyCatalog
    {
        public function referencesTo(string $referencedTable, ?Connection $connection = null): array
        {
            return [];
        }
    };
    app()->instance(PatientMergeOwnershipRepositoryInterface::class, new PatientMergeOwnershipRepository($blind));

    $codes = r6b1BlockerCodes(fn () => pmSubmittedCase(pmPatient(), pmPatient(), $this->requester));

    expect(app(PatientMergeOwnershipRepositoryInterface::class)->unregisteredPatientForeignKeys())
        ->toBe([PatientMergeOwnershipRepository::FOREIGN_KEY_CATALOG_EMPTY])
        ->and($codes)->toContain('OWNERSHIP_REGISTRY_INCOMPLETE');
});

it('keeps the non-owner runtime unable to change the constraints it is checked against', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $constraint = (string) DB::scalar(
        "SELECT conname FROM pg_catalog.pg_constraint
          WHERE contype = 'f' AND conrelid = 'trx_clinic_visits'::regclass AND confrelid = 'mst_patients'::regclass
          LIMIT 1"
    );
    $role = NonOwnerRole::create();

    $states = NonOwnerRole::run($role, fn (): array => [
        'drop_constraint' => NonOwnerRole::sqlstate(fn () => DB::statement("ALTER TABLE trx_clinic_visits DROP CONSTRAINT \"{$constraint}\"")),
        'add_constraint' => NonOwnerRole::sqlstate(fn () => DB::statement('ALTER TABLE trx_clinic_visits ADD CONSTRAINT r6b1_probe CHECK (true)')),
        'create_table' => NonOwnerRole::sqlstate(fn () => DB::statement('CREATE TABLE r6b1_probe_table (id int)')),
        'truncate' => NonOwnerRole::sqlstate(fn () => DB::statement('TRUNCATE trx_clinic_visits')),
    ]);

    expect($constraint)->not->toBe('')
        ->and($states)->toBe([
            'drop_constraint' => '42501',
            'add_constraint' => '42501',
            'create_table' => '42501',
            'truncate' => '42501',
        ]);
});
