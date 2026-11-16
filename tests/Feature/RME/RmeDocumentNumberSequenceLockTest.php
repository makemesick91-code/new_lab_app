<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-8B / D2 — RME invoice, payment and consent numbers
|--------------------------------------------------------------------------
|
| Each of the three numbers is "the highest issued this month, plus one":
|
|   RME-YYYYMM-NNNNNN       (RmeInvoiceNumberGeneratorService)
|   RMEPAY-YYYYMM-NNNNNN    (RmePaymentNumberGeneratorService)
|   CONSENT-YYYYMM-NNNNNN   (RmeVisitConsentNumberGeneratorService)
|
| Before this sprint the read serialised nothing (OD-7-8, OD-5-8): two writers
| committing at the same moment both read N and both chose N+1, and the UNIQUE
| index turned the second into HTTP 500. The invoice and payment reads also
| ignored soft-deleted rows while the UNIQUE index still holds their numbers
| (P8-N4), so a single trashed top number would have wedged the whole month.
|
| The fix is the rule 225 pattern: a transaction-scoped PostgreSQL advisory lock
| per sequence and month, taken BEFORE the highest number is read. The lock
| tests use a separate PROBE connection, which can see an advisory lock held
| inside the test's own RefreshDatabase transaction; they only run on
| PostgreSQL and SKIP elsewhere. The numbering and fail-closed tests run on
| every driver.
|
| THE KEYS ARE PINNED HERE ON PURPOSE. classid 92002 / 92003 / 92004, objid =
| the month as YYYYMM. They must never be renumbered: during a rolling deploy an
| old and a new process would take different locks for the same month.
|
| Helper prefix `p8bnum` is unique because the critical gate loads every suite
| into one process.
|
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (P8R-S6, CG-12): the invoice and payment
| months now come from ClinicalClock, as the consent month always did. The
| helpers below follow the generators, and the previous-month arithmetic uses
| `!Ym` (day 1) so it cannot overflow on the 29th-31st (CG-20).
*/

use App\Modules\ClinicVisit\Repositories\ClinicVisitRepository;
use App\Modules\ClinicVisit\Support\ClinicVisitSequenceLock;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentNumberGeneratorService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceNumberGeneratorService;
use App\Modules\RmeInvoice\Services\RmePaymentNumberGeneratorService;
use App\Support\Clinical\ClinicalClock;
use App\Support\Database\DocumentNumberSequenceLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const P8BNUM_PROBE = 'p8bnum_sequence_lock_probe';

function p8bnumInvoiceMonth(): string
{
    // P8R-S6 — the clinic's month, exactly as the generators compute it.
    return app(ClinicalClock::class)->today()->format('Ym');
}

function p8bnumConsentMonth(): string
{
    return app(ClinicalClock::class)->today()->format('Ym');
}

/**
 * The month before a YYYYMM month. `!Ym` resets the day to 1: a bare `Ym` keeps
 * today's day, so on 2027-01-31 "202702 minus one month" overflowed to 202702.
 */
function p8bnumPreviousMonth(string $month): string
{
    return CarbonImmutable::createFromFormat('!Ym', $month)->subMonth()->format('Ym');
}

/** Run a generator the way production does: inside a transaction. */
function p8bnumGenerate(string $generator): string
{
    return DB::transaction(fn (): string => app($generator)->generate());
}

function p8bnumUsePgProbe(object $test): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $test->markTestSkipped('Advisory locks exist only on PostgreSQL; SQLite serialises writers at the file level.');
    }

    config()->set('database.connections.'.P8BNUM_PROBE, config('database.connections.'.config('database.default')));
    DB::purge(P8BNUM_PROBE);
}

/** Whether ANOTHER session could take this sequence lock right now. */
function p8bnumLockFree(int $namespace, string $month): bool
{
    $probe = DB::connection(P8BNUM_PROBE);
    $acquired = (bool) $probe->selectOne('select pg_try_advisory_lock(?, ?) as got', [$namespace, (int) $month])->got;

    if ($acquired) {
        $probe->selectOne('select pg_advisory_unlock(?, ?) as released', [$namespace, (int) $month]);
    }

    return $acquired;
}

afterEach(function () {
    freeTestClock();

    if (config()->has('database.connections.'.P8BNUM_PROBE)) {
        DB::connection(P8BNUM_PROBE)->rollBack(0);
        DB::purge(P8BNUM_PROBE);
    }
});

/*
|--------------------------------------------------------------------------
| The keys — every driver
|--------------------------------------------------------------------------
*/

it('pins the three sequence namespaces and keeps them apart from every other advisory lock', function () {
    expect(DocumentNumberSequenceLock::RME_INVOICE)->toBe(92_002)
        ->and(DocumentNumberSequenceLock::RME_PAYMENT)->toBe(92_003)
        ->and(DocumentNumberSequenceLock::RME_VISIT_CONSENT)->toBe(92_004)
        ->and(DocumentNumberSequenceLock::NAMESPACES)->toBe([92_002, 92_003, 92_004])
        // 92_001 is the visit sequence (rule 225); 91_xxx is the legacy document slot.
        ->and(DocumentNumberSequenceLock::NAMESPACES)->not->toContain(ClinicVisitSequenceLock::NAMESPACE)
        // 92_005 is the patient encounter decision lock (OD-5-5).
        ->and(DocumentNumberSequenceLock::NAMESPACES)->not->toContain(ClinicVisitRepository::PATIENT_ENCOUNTER_LOCK_NAMESPACE)
        ->and(min(DocumentNumberSequenceLock::NAMESPACES))->toBeGreaterThan(91_999);
});

it('maps a month to an exact integer key and refuses anything that is not YYYYMM', function () {
    expect(DocumentNumberSequenceLock::keyFor(DocumentNumberSequenceLock::RME_INVOICE, '202611'))->toBe([92_002, 202611])
        ->and(DocumentNumberSequenceLock::keyFor(DocumentNumberSequenceLock::RME_VISIT_CONSENT, '202701'))->toBe([92_004, 202701]);

    foreach (['202613', '202600', '2026-11', '20261', '2026111', '', 'abcdef', "202611\n"] as $bad) {
        expect(fn () => DocumentNumberSequenceLock::keyFor(DocumentNumberSequenceLock::RME_PAYMENT, $bad))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => DocumentNumberSequenceLock::keyFor(92_001, '202611'))->toThrow(InvalidArgumentException::class);
});

it('refuses to number a document outside a transaction', function (string $generator) {
    // RefreshDatabase keeps the default connection inside a transaction, so the
    // call is made on a second connection that has none — production's state
    // when a caller forgets the transaction. The refusal comes before any query.
    $default = config('database.default');
    config()->set('database.connections.p8bnum_plain', config('database.connections.'.$default));
    config()->set('database.default', 'p8bnum_plain');

    try {
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(fn () => app($generator)->generate())
            ->toThrow(LogicException::class, 'inside a transaction');
    } finally {
        config()->set('database.default', $default);
        DB::purge('p8bnum_plain');
    }
})->with([
    'invoice' => RmeInvoiceNumberGeneratorService::class,
    'payment' => RmePaymentNumberGeneratorService::class,
    'consent' => RmeVisitConsentNumberGeneratorService::class,
]);

/*
|--------------------------------------------------------------------------
| Numbering — every driver
|--------------------------------------------------------------------------
*/

it('starts a month at 000001 for every sequence', function () {
    expect(p8bnumGenerate(RmeInvoiceNumberGeneratorService::class))->toBe('RME-'.p8bnumInvoiceMonth().'-000001')
        ->and(p8bnumGenerate(RmePaymentNumberGeneratorService::class))->toBe('RMEPAY-'.p8bnumInvoiceMonth().'-000001')
        ->and(p8bnumGenerate(RmeVisitConsentNumberGeneratorService::class))->toBe('CONSENT-'.p8bnumConsentMonth().'-000001');
});

it('never reissues the number of a soft-deleted invoice (P8-N4)', function () {
    $month = p8bnumInvoiceMonth();
    RmeInvoice::factory()->create(['invoice_number' => 'RME-'.$month.'-000041']);
    $top = RmeInvoice::factory()->create(['invoice_number' => 'RME-'.$month.'-000042']);
    $top->delete();

    expect(RmeInvoice::withTrashed()->find($top->id)->trashed())->toBeTrue()
        ->and(p8bnumGenerate(RmeInvoiceNumberGeneratorService::class))->toBe('RME-'.$month.'-000043');
});

it('never reissues the number of a soft-deleted payment (P8-N4)', function () {
    $month = p8bnumInvoiceMonth();
    RmePayment::factory()->create(['payment_number' => 'RMEPAY-'.$month.'-000007']);
    $top = RmePayment::factory()->create(['payment_number' => 'RMEPAY-'.$month.'-000008']);

    // A payment is immutable and its model refuses delete(); the only way a row
    // can be trashed is a direct write, which is exactly the latent state P8-N4
    // describes. Simulate it at the query layer, without the model.
    DB::table('trx_rme_payments')->where('id', $top->id)->update(['deleted_at' => now()]);

    expect(RmePayment::query()->find($top->id))->toBeNull()
        ->and(p8bnumGenerate(RmePaymentNumberGeneratorService::class))->toBe('RMEPAY-'.$month.'-000009');
});

it('continues after a voided consent rather than reusing its number', function () {
    $month = p8bnumConsentMonth();
    $voided = RmeVisitConsent::factory()->voided()->create([
        'consent_number' => 'CONSENT-'.$month.'-000003',
    ]);

    expect($voided->isVoided())->toBeTrue();

    expect(p8bnumGenerate(RmeVisitConsentNumberGeneratorService::class))->toBe('CONSENT-'.$month.'-000004');
});

it('leaves every number already issued untouched', function () {
    $month = p8bnumInvoiceMonth();
    $invoices = collect(['000001', '000002', '000005'])
        ->map(fn (string $n) => RmeInvoice::factory()->create(['invoice_number' => 'RME-'.$month.'-'.$n]));
    $before = RmeInvoice::withTrashed()->orderBy('id')->pluck('invoice_number', 'id')->all();

    expect(p8bnumGenerate(RmeInvoiceNumberGeneratorService::class))->toBe('RME-'.$month.'-000006')
        ->and(RmeInvoice::withTrashed()->orderBy('id')->pluck('invoice_number', 'id')->all())->toBe($before)
        ->and($invoices)->toHaveCount(3);
});

it('ignores another month and malformed numbers when choosing the next one', function () {
    $month = p8bnumInvoiceMonth();
    $previous = p8bnumPreviousMonth($month);
    RmeInvoice::factory()->create(['invoice_number' => 'RME-'.$previous.'-000900']);
    RmeInvoice::factory()->create(['invoice_number' => 'RME-'.$month.'-000010']);

    expect(p8bnumGenerate(RmeInvoiceNumberGeneratorService::class))->toBe('RME-'.$month.'-000011');
});
/*
|--------------------------------------------------------------------------
| The clinic's month (P8R-S6) — every driver
|--------------------------------------------------------------------------
|
| UTC 16:00 is 00:00 WITA. Until DMS-RME-COMPREHENSIVE-CLOSURE-1 an invoice or
| payment issued 00:00-07:59 WITA on the 1st carried the PREVIOUS month.
*/

dataset('p8bnum clinic month boundaries', [
    'last UTC second of the clinic month' => ['2026-11-30 15:59:59', '202611'],
    '00:00 WITA on the 1st (UTC still on the 30th)' => ['2026-11-30 16:00:00', '202612'],
    '07:59:59 WITA on the 1st' => ['2026-11-30 23:59:59', '202612'],
    '08:00 WITA on the 1st (UTC caught up)' => ['2026-12-01 00:00:00', '202612'],
    'year rollover, 00:30 WITA on 1 January' => ['2026-12-31 16:30:00', '202701'],
]);

it('numbers invoices, payments and consents in the same clinic month (P8R-S6)', function (string $utcInstant, string $clinicMonth) {
    pinTestClock($utcInstant);

    expect(p8bnumInvoiceMonth())->toBe($clinicMonth)
        ->and(p8bnumGenerate(RmeInvoiceNumberGeneratorService::class))->toBe('RME-'.$clinicMonth.'-000001')
        ->and(p8bnumGenerate(RmePaymentNumberGeneratorService::class))->toBe('RMEPAY-'.$clinicMonth.'-000001')
        ->and(p8bnumGenerate(RmeVisitConsentNumberGeneratorService::class))->toBe('CONSENT-'.$clinicMonth.'-000001');
})->with('p8bnum clinic month boundaries');

it('leaves a number issued under the old UTC label alone and starts the clinic month fresh (P8R-S6)', function () {
    // 2026-12-01 00:30 WITA. Before the fix this instant produced RME-202611-000006.
    pinTestClock('2026-11-30 16:30:00');
    $old = RmeInvoice::factory()->create(['invoice_number' => 'RME-202611-000005']);
    $oldPayment = RmePayment::factory()->create(['payment_number' => 'RMEPAY-202611-000005']);

    expect(p8bnumGenerate(RmeInvoiceNumberGeneratorService::class))->toBe('RME-202612-000001')
        ->and(p8bnumGenerate(RmePaymentNumberGeneratorService::class))->toBe('RMEPAY-202612-000001')
        ->and($old->fresh()->invoice_number)->toBe('RME-202611-000005')
        ->and($oldPayment->fresh()->payment_number)->toBe('RMEPAY-202611-000005');
});

it('computes the previous month without overflowing on the 29th to 31st (CG-20)', function () {
    // 2027-02-01 00:30 WITA: the clinic month is 202702 while UTC is on 31 January.
    pinTestClock('2027-01-31 16:30:00');

    expect(p8bnumInvoiceMonth())->toBe('202702')
        ->and(p8bnumPreviousMonth('202702'))->toBe('202701')
        ->and(p8bnumPreviousMonth('202703'))->toBe('202702')
        ->and(p8bnumPreviousMonth('202701'))->toBe('202612')
        ->and(p8bnumPreviousMonth('202605'))->toBe('202604');
});

/*
|--------------------------------------------------------------------------
| Serialisation — PostgreSQL only
|--------------------------------------------------------------------------
*/

dataset('p8bnum sequences', [
    'invoice' => [RmeInvoiceNumberGeneratorService::class, DocumentNumberSequenceLock::RME_INVOICE, 'trx_rme_invoices', 'invoice'],
    'payment' => [RmePaymentNumberGeneratorService::class, DocumentNumberSequenceLock::RME_PAYMENT, 'trx_rme_payments', 'invoice'],
    'consent' => [RmeVisitConsentNumberGeneratorService::class, DocumentNumberSequenceLock::RME_VISIT_CONSENT, 'trx_rme_visit_consents', 'consent'],
]);

it('holds the month\'s sequence lock while it reads the highest number', function (string $generator, int $namespace, string $table, string $clock) {
    p8bnumUsePgProbe($this);
    $month = $clock === 'consent' ? p8bnumConsentMonth() : p8bnumInvoiceMonth();
    $statements = [];
    $heldDuringRead = null;

    DB::listen(function (QueryExecuted $query) use (&$statements, &$heldDuringRead, $namespace, $month, $table): void {
        if ($query->connectionName === P8BNUM_PROBE) {
            return;
        }

        $sql = strtolower($query->sql);
        $statements[] = $sql;

        if ($heldDuringRead === null && str_contains($sql, '"'.$table.'"')) {
            $heldDuringRead = ! p8bnumLockFree($namespace, $month);
        }
    });

    p8bnumGenerate($generator);

    $lockAt = collect($statements)->search(fn (string $s) => str_contains($s, 'pg_advisory_xact_lock'));
    $readAt = collect($statements)->search(fn (string $s) => str_contains($s, '"'.$table.'"'));

    expect($lockAt)->not->toBeFalse('no sequence lock was taken')
        ->and($readAt)->not->toBeFalse('the highest number was never read')
        ->and($lockAt)->toBeLessThan($readAt)
        ->and($heldDuringRead)->toBeTrue('another session could take the sequence lock while the highest number was read');
})->with('p8bnum sequences');

it('waits for a concurrent writer that holds the same month', function (string $generator, int $namespace, string $table, string $clock) {
    p8bnumUsePgProbe($this);
    $month = $clock === 'consent' ? p8bnumConsentMonth() : p8bnumInvoiceMonth();

    // The probe stands in for a concurrent writer that has taken the month's
    // lock and not yet committed. This writer must WAIT — proven by a short
    // lock_timeout expiring — instead of reading a number the other one is
    // about to use.
    $probe = DB::connection(P8BNUM_PROBE);
    $probe->beginTransaction();
    $probe->statement('select pg_advisory_xact_lock(?, ?)', [$namespace, (int) $month]);

    DB::statement("set local lock_timeout = '400ms'");

    try {
        p8bnumGenerate($generator);
        $this->fail('The generator did not wait for the concurrent writer holding the month lock.');
    } catch (QueryException $timedOut) {
        expect($timedOut->getCode())->toBe('55P03');
    } finally {
        $probe->rollBack();
    }
})->with('p8bnum sequences');

it('never makes one sequence wait for another, nor for another month', function (string $generator, int $namespace, string $table, string $clock) {
    p8bnumUsePgProbe($this);
    $month = $clock === 'consent' ? p8bnumConsentMonth() : p8bnumInvoiceMonth();
    $previous = p8bnumPreviousMonth($month);

    $probe = DB::connection(P8BNUM_PROBE);
    $probe->beginTransaction();

    // Every OTHER sequence, this month, plus this sequence for the previous month.
    foreach (array_diff(DocumentNumberSequenceLock::NAMESPACES, [$namespace]) as $other) {
        $probe->statement('select pg_advisory_xact_lock(?, ?)', [$other, (int) $month]);
    }
    $probe->statement('select pg_advisory_xact_lock(?, ?)', [$namespace, (int) $previous]);

    DB::statement("set local lock_timeout = '400ms'");

    try {
        $number = p8bnumGenerate($generator);
    } finally {
        $probe->rollBack();
    }

    expect($number)->toEndWith('-'.$month.'-000001');
})->with('p8bnum sequences');

it('locks the clinic month, not the UTC month, at 00:30 WITA on the 1st (P8R-S6)', function (string $generator, int $namespace, string $table, string $clock) {
    p8bnumUsePgProbe($this);
    pinTestClock('2026-11-30 16:30:00');

    // A writer still holding the UTC month (202611) must not delay this one,
    // and this one must take 202612 — the month its number carries.
    $probe = DB::connection(P8BNUM_PROBE);
    $probe->beginTransaction();
    $probe->statement('select pg_advisory_xact_lock(?, ?)', [$namespace, 202611]);

    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        if ($query->connectionName !== P8BNUM_PROBE && str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $locked[] = array_map('intval', $query->bindings);
        }
    });

    DB::statement("set local lock_timeout = '400ms'");

    try {
        $number = p8bnumGenerate($generator);
    } finally {
        $probe->rollBack();
    }

    expect($number)->toEndWith('-202612-000001')
        ->and($locked)->toContain([$namespace, 202612])
        ->and($locked)->not->toContain([$namespace, 202611]);
})->with('p8bnum sequences');
