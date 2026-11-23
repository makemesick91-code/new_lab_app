<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-SPN4-READY-PILOT-1 G6 / REM-2b — a database error never writes the NIK
| (or any other query value) into the application log
|--------------------------------------------------------------------------
|
| QueryException's message ends in "(Connection: …, SQL: …)" with the bindings
| interpolated verbatim, PostgreSQL adds a DETAIL line echoing the row, and the
| exception object itself (wrapped PDOException, trace arguments) repeats it. Rule
| 244 §5.3 made the patient service's own unique violations value-free; every
| other QueryException still reached the log with its bindings.
|
| Every writing log channel now carries the RedactDatabaseErrors tap. These tests
| read what Monolog actually WROTE (a temporary log file), not the event fired
| before the processors run. The NIK and name are synthetic.
|
| Selected by the `PatientKtp` critical-gate token in both runner variants and
| declared in critical_gate_mandatory_suites (CG-19). Helper prefix `pkdl`.
*/

use App\Support\Logging\RedactDatabaseErrors;
use App\Support\Logging\RedactDatabaseErrorsProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

const PKDL_NIK = '9971010101900004';
const PKDL_NAME = 'Pasien Sintetis Log';
const PKDL_NIK_HEAD = '99710101019';

/** 15 of the NIK's 16 digits: passes varchar(16) and fails the width CHECK, on both drivers. */
const PKDL_SHORT_NIK = '997101010190000';

beforeEach(function (): void {
    $this->pkdlLog = (string) tempnam(sys_get_temp_dir(), 'pkdl-');
    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => $this->pkdlLog,
        'logging.channels.single.level' => 'debug',
    ]);
    app('log')->forgetChannel('single');

    $pgsql = DB::getDriverName() === 'pgsql';
    DB::statement('CREATE TABLE pkdl_probe ('
        .($pgsql ? 'id bigserial PRIMARY KEY' : 'id integer PRIMARY KEY AUTOINCREMENT')
        .', nik varchar(16) NOT NULL, name varchar(100) NOT NULL, age integer NULL'
        .', CONSTRAINT pkdl_probe_nik_unique UNIQUE (nik)'
        .', CONSTRAINT pkdl_probe_nik_width CHECK ('.($pgsql ? 'char_length' : 'length').'(nik) = 16))');
});

afterEach(function (): void {
    app('log')->forgetChannel('single');
    if (is_file($this->pkdlLog)) {
        unlink($this->pkdlLog);
    }
});

/** Runs $statement in a savepoint and returns the QueryException it raised. */
function pkdlQueryException(callable $statement): QueryException
{
    try {
        DB::transaction(fn () => $statement());
    } catch (QueryException $exception) {
        return $exception;
    }

    throw new RuntimeException('The probe statement did not fail.');
}

/** $text as it appears inside the JSON context Monolog writes (backslashes escaped). */
function pkdlJson(string $text): string
{
    return trim((string) json_encode($text), '"');
}

/** The "<environment>.<LEVEL>:" prefix Monolog writes. */
function pkdlLevel(string $level): string
{
    return app()->environment().'.'.$level;
}

function pkdlWritten(): string
{
    return (string) file_get_contents(test()->pkdlLog);
}

function pkdlAssertNoValues(string $written): void
{
    expect($written)->not->toBe('')
        ->and($written)->not->toContain(PKDL_NIK)
        ->and($written)->not->toContain(PKDL_NIK_HEAD)
        ->and($written)->not->toContain(PKDL_NAME)
        ->and($written)->not->toContain('Failing row contains')
        ->and($written)->not->toContain('(Connection: ');
}

it('LR-01 reports a check violation with its diagnosis and without the NIK or the name', function (): void {
    $e = pkdlQueryException(fn () => DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_SHORT_NIK, PKDL_NAME]));

    // The raw exception really carries the values: this is what used to be logged.
    expect($e->getMessage())->toContain(PKDL_NAME);

    report($e);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain(pkdlLevel('ERROR'))
        ->and($written)->toContain(pkdlJson(QueryException::class))
        ->and($written)->toContain('SQLSTATE')
        ->and($written)->toContain('pkdl_probe_nik_width')
        ->and($written)->toContain('insert into pkdl_probe (nik, name) values (?+)')
        ->and($written)->toContain('"binding_count":2')
        ->and($written)->toContain('"trace":[');
});

it('LR-02 reports a unique violation (the subclass) without the duplicated NIK', function (): void {
    DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_NIK, PKDL_NAME]);
    $e = pkdlQueryException(fn () => DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_NIK, PKDL_NAME]));

    expect($e)->toBeInstanceOf(UniqueConstraintViolationException::class);

    report($e);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain(pkdlJson(UniqueConstraintViolationException::class))
        ->and($written)->toContain('SQLSTATE');
});

it('LR-03 reports a not-null violation without the row PostgreSQL echoes', function (): void {
    $e = pkdlQueryException(fn () => DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_NIK, null]));

    report($e);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain('SQLSTATE');

    if (DB::getDriverName() === 'pgsql') {
        expect($e->getMessage())->toContain('Failing row contains')
            ->and($written)->toContain('column "name"')
            ->and($written)->toContain('relation "pkdl_probe"');
    }
});

it('LR-04 redacts a value PostgreSQL quotes in the first line (invalid input syntax)', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite does not type-check the integer column.');
    }

    $e = pkdlQueryException(fn () => DB::insert('insert into pkdl_probe (nik, name, age) values (?, ?, ?)', ['9971010101900005', 'Usia', 'umur '.PKDL_NIK]));

    expect($e->getMessage())->toContain('umur '.PKDL_NIK);

    report($e);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain('22P02')
        ->and($written)->toContain('invalid input syntax for type integer');
});

it('LR-05 redacts a database error a service logs itself, as message text and as a context string', function (): void {
    $e = pkdlQueryException(fn () => DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_SHORT_NIK, PKDL_NAME]));

    Log::warning('Candidate generation failed: '.$e->getMessage(), ['error' => $e->getMessage(), 'visit_id' => 42]);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain(pkdlLevel('WARNING').': Candidate generation failed: SQLSTATE')
        ->and($written)->toContain('"visit_id":42');
});

it('LR-06 describes a wrapped database error through its whole chain without values', function (): void {
    $query = pkdlQueryException(fn () => DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_SHORT_NIK, PKDL_NAME]));

    report(new RuntimeException('Pendaftaran gagal: '.$query->getMessage(), 0, $query));
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain(pkdlJson(RuntimeException::class))
        ->and($written)->toContain('"previous":{"class":"'.pkdlJson(QueryException::class));
});

it('LR-07 leaves the HTTP answer and the rollback exactly as before, and logs one record', function (): void {
    Route::middleware('web')->get('/_pkdl/fails', function () {
        DB::transaction(function (): void {
            DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', ['9971010101900006', 'Baris Pertama']);
            DB::insert('insert into pkdl_probe (nik, name) values (?, ?)', [PKDL_SHORT_NIK, PKDL_NAME]);
        });

        return 'unreachable';
    });

    $this->get('/_pkdl/fails')->assertStatus(500);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect(substr_count($written, pkdlLevel('ERROR')))->toBe(1)
        ->and(DB::table('pkdl_probe')->count())->toBe(0);

    // Not swallowed: without the handler the exception still reaches the caller.
    $this->withoutExceptionHandling();
    expect(fn () => $this->get('/_pkdl/fails'))->toThrow(QueryException::class);
});

it('LR-08 passes every record without a database error through untouched', function (): void {
    Log::warning('Stock movement posted for order 4410', ['movement_id' => 42, 'note' => 'qty 12 "box"']);
    report(new RuntimeException('Kuota antrean penuh untuk cabang SPN4'));
    $written = pkdlWritten();

    expect($written)->toContain(pkdlLevel('WARNING').': Stock movement posted for order 4410 {"movement_id":42,"note":"qty 12 \"box\""}')
        ->and($written)->toContain(pkdlLevel('ERROR').': Kuota antrean penuh untuk cabang SPN4 {"exception":"[object] (RuntimeException(code: 0): Kuota antrean penuh untuk cabang SPN4 at ');
});

it('LR-09 withholds a database-error record instead of writing it raw when redaction itself fails', function (): void {
    $broken = new class('pgsql', 'insert into pkdl_probe (nik) values (?)', [PKDL_NIK], new PDOException('SQLSTATE[23514]: Check violation: 7 ERROR: '.PKDL_NAME)) extends QueryException
    {
        public function getSql(): string
        {
            throw new LogicException('redaction probe');
        }
    };

    report($broken);
    $written = pkdlWritten();

    pkdlAssertNoValues($written);
    expect($written)->toContain(pkdlLevel('ERROR').': '.RedactDatabaseErrorsProcessor::WITHHELD);
});

it('LR-10 taps every writing log channel, so no channel or stack writes database errors raw', function (): void {
    $writing = array_filter(
        config('logging.channels'),
        fn (array $channel, string $name): bool => ! in_array($name, ['stack', 'null', 'emergency'], true),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($writing)->not->toBeEmpty();

    foreach ($writing as $name => $channel) {
        expect($channel['tap'] ?? [])->toContain(RedactDatabaseErrors::class);
    }

    config(['logging.channels.pkdl_stack' => ['driver' => 'stack', 'channels' => ['single']]]);
    $processors = Log::channel('pkdl_stack')->getLogger()->getProcessors();

    expect(collect($processors)->contains(fn ($p) => $p instanceof RedactDatabaseErrorsProcessor))->toBeTrue();
});
