<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\Completeness\Services\LegacyPatientArchiveCompletenessService;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessQuery;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — query shape
|--------------------------------------------------------------------------
|
| The legacy estate is already ~1500 patients in production and the scale
| projection reaches tens of thousands, so the property that matters is not a
| query CEILING — it is CONSTANCY. The report must cost the same whether it
| renders 1 patient or 60, and whether the estate holds 10 or 10,000.
|
| A ceiling alone could not see an N+1: a per-row lookup added to a 3-row
| fixture fits inside almost any headroom. Measuring the SAME page at two very
| different sizes is what makes the absence of an N+1 observable.
*/

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::query()->firstOrCreate(
        ['code' => 'TLK1'],
        ['name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true],
    );

    $this->service = app(LegacyPatientArchiveCompletenessService::class);

    $this->governor = User::factory()->create();
    $this->governor->assignRole('Supervisor RME');
    $this->governor = $this->governor->fresh();
});

/**
 * Seed $count legacy patients, each in a different lifecycle shape so the
 * measurement exercises every join rather than only the all-NULL path.
 */
function lcqSeed(Branch $branch, int $count): void
{
    $batch = LegacyPatientImportBatch::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_filename' => 'legacy-patients.csv',
        'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
    ]);

    // The Nomor RM carries a UNIQUE index, so the sequence has to continue from
    // whatever is already there — a second call that restarted at 0001 would
    // collide rather than grow the fixture.
    $offset = Patient::query()->withTrashed()->count();

    for ($i = 0; $i < $count; $i++) {
        $patient = Patient::factory()->create([
            'branch_id' => $branch->id,
            'import_batch_id' => $batch->id,
            'date_of_birth' => '1990-01-01',
            'medical_record_number' => sprintf('DG-TLK1-2024-%04d', $offset + $i + 1),
        ]);

        match ($i % 4) {
            0 => null, // missing both
            1 => LegacyRmeRecord::factory()->create([
                'patient_id' => $patient->id,
                'origin_branch_id' => $branch->id,
            ]),
            2 => LegacyRmeImport::factory()->create([
                'patient_id' => $patient->id,
                'origin_branch_id' => $branch->id,
                'status' => LegacyRmeImportStatus::READY_FOR_REVIEW,
            ]),
            3 => LegacyOdontogramImport::factory()->create([
                'patient_id' => $patient->id,
                'origin_branch_id' => $branch->id,
                'status' => LegacyOdontogramImportStatus::PROCESSING,
            ]),
        };
    }
}

/** @return list<string> */
function lcqMeasure(callable $callback): array
{
    $queries = [];

    DB::flushQueryLog();
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

it('costs the same number of queries for one page as for a much larger one', function (): void {
    // Warm up first: the permission registrar caches on first use, and a cold
    // sample would attribute its warm-up queries to the page.
    $this->service->rows($this->service->resolveQuery($this->governor));

    lcqSeed($this->branch, 3);
    $small = lcqMeasure(function (): void {
        $query = $this->service->resolveQuery($this->governor, perPage: 100);
        $this->service->rows($query);
    });

    lcqSeed($this->branch, 60);
    $large = lcqMeasure(function (): void {
        $query = $this->service->resolveQuery($this->governor, perPage: 100);
        $this->service->rows($query);
    });

    // THE LOAD-BEARING ASSERTION. 3 rows and 63 rows must cost the same.
    expect(count($large))->toBe(count($small));
});

it('keeps the whole page render inside a constant query budget', function (): void {
    $this->service->rows($this->service->resolveQuery($this->governor));

    lcqSeed($this->branch, 40);

    $queries = lcqMeasure(function (): void {
        $query = $this->service->resolveQuery($this->governor);
        $this->service->rows($query);
        $this->service->summary($query);
        $this->service->branchOptions($query);
    });

    // A documented ceiling with headroom, on top of the constancy assertion
    // above. The shape is: pagination count + rows, two representative-status
    // lookups, one triage lookup, one summary aggregate, one branch-option
    // query, plus the branch-service lookups the scope resolution performs.
    expect(count($queries))->toBeLessThanOrEqual(14);
});

/**
 * How many STATEMENTS name each legacy table while one page renders.
 *
 * @param  list<string>  $queries
 * @return array<string, int>
 */
function lcqTableTouches(array $queries): array
{
    $touches = [
        'trx_rme_legacy_records' => 0,
        'stg_rme_legacy_imports' => 0,
        'trx_odontogram_legacy_records' => 0,
        'stg_odontogram_legacy_imports' => 0,
        'stg_legacy_review_triage' => 0,
    ];

    foreach ($queries as $sql) {
        foreach (array_keys($touches) as $table) {
            // The aggregates are joined into the listing query, so one
            // statement may legitimately name several tables; what must never
            // happen is the same table being read once per ROW.
            $touches[$table] += str_contains($sql, $table) ? 1 : 0;
        }
    }

    return $touches;
}

it('reads each legacy table a constant number of times regardless of row count', function (): void {
    $this->service->rows($this->service->resolveQuery($this->governor));

    lcqSeed($this->branch, 5);
    $small = lcqTableTouches(lcqMeasure(function (): void {
        $this->service->rows($this->service->resolveQuery($this->governor, perPage: 100));
    }));

    lcqSeed($this->branch, 55);
    $large = lcqTableTouches(lcqMeasure(function (): void {
        $this->service->rows($this->service->resolveQuery($this->governor, perPage: 100));
    }));

    // THE REAL PROPERTY, and it needs no magic number: 5 rows and 60 rows must
    // touch each table the same number of times. An N+1 on any one of them
    // would show up here as a difference of 55.
    expect($large)->toBe($small);

    // The measured constants, documented rather than guessed. The record
    // tables are read twice — once by the pagination COUNT and once by the
    // listing, both through the same grouped aggregate. The staging tables are
    // read a third time by the bounded representative-status lookup, and the
    // triage table once.
    expect($small)->toBe([
        'trx_rme_legacy_records' => 2,
        'stg_rme_legacy_imports' => 3,
        'trx_odontogram_legacy_records' => 2,
        'stg_odontogram_legacy_imports' => 3,
        'stg_legacy_review_triage' => 1,
    ]);
});

it('bounds the page size so a single request cannot read the whole estate', function (): void {
    lcqSeed($this->branch, 60);

    $query = $this->service->resolveQuery($this->governor);
    $paginator = $this->service->rows($query);

    expect($query->perPage)->toBe(LegacyCompletenessQuery::PER_PAGE)
        ->and($paginator->count())->toBeLessThanOrEqual(LegacyCompletenessQuery::PER_PAGE)
        // …and the paginator still knows the real total, so the operator can
        // see how much backlog there is without the request loading it.
        ->and($paginator->total())->toBe(60);
});

it('pages deterministically without repeating or skipping a row', function (): void {
    lcqSeed($this->branch, 40);

    $idsOnPage = function (int $page): array {
        // The paginator resolves its page from the request, so the page is set
        // the way a real request would set it rather than by a private hook.
        request()->merge(['page' => $page]);

        return array_map(
            static fn (object $row): int => $row->patientId,
            $this->service->rows($this->service->resolveQuery($this->governor))->items(),
        );
    };

    $first = $idsOnPage(1);
    $second = $idsOnPage(2);

    // Ordered by Nomor RM then id. The fixture numbers RMs sequentially, so
    // page 1 must be the lowest ids in order and page 2 the next block.
    $sortedFirst = $first;
    sort($sortedFirst);

    expect($first)->toHaveCount(LegacyCompletenessQuery::PER_PAGE)
        ->and($first)->toBe($sortedFirst)
        // No overlap: a non-deterministic order would repeat rows across pages
        // and silently hide others from the operator entirely.
        ->and(array_intersect($first, $second))->toBe([])
        ->and(count($second))->toBe(40 - LegacyCompletenessQuery::PER_PAGE);

    // And the same page asked twice gives the same answer.
    expect($idsOnPage(1))->toBe($first);
});

it('derives every state in SQL rather than by loading legacy models', function (): void {
    lcqSeed($this->branch, 10);

    $queries = lcqMeasure(function (): void {
        $this->service->rows($this->service->resolveQuery($this->governor));
    });

    // No `select * from trx_rme_legacy_records where patient_id = ?`-shaped
    // per-row read. The record tables are only ever reached through a grouped
    // aggregate that joins on patient_id.
    foreach ($queries as $sql) {
        $normalised = strtolower((string) preg_replace('/\s+/', ' ', $sql));

        expect($normalised)->not->toContain('from "trx_rme_legacy_records" where "patient_id" = ?')
            ->and($normalised)->not->toContain('from "trx_odontogram_legacy_records" where "patient_id" = ?');
    }
});

it('runs the full report on the configured driver without error', function (): void {
    // A smoke assertion that the portable SQL actually executes: the
    // `max(case when …)` aggregates and the `escape '!'` clause must run
    // identically on SQLite and on PostgreSQL, and a report whose query only
    // works on one engine is a report nobody can test.
    lcqSeed($this->branch, 5);
    LegacyOdontogramRecord::factory()->create([
        'patient_id' => Patient::query()->whereNotNull('import_batch_id')->value('id'),
        'branch_id' => $this->branch->id,
    ]);

    $query = $this->service->resolveQuery($this->governor, null, 'DG-TLK1');

    expect($this->service->rows($query)->total())->toBeGreaterThan(0)
        ->and($this->service->summary($query))->toHaveKeys([
            'total', 'complete', 'incomplete', 'missing_rme', 'missing_odontogram', 'missing_both', 'in_progress',
        ])
        ->and(DB::connection()->getDriverName())->toBeIn(['sqlite', 'pgsql']);
});
