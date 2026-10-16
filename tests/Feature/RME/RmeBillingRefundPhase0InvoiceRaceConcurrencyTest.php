<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BILLING-REFUND-1 / Phase 0 (audit BCF-08) — the duplicate-invoice
| race is closed under real concurrency.
|--------------------------------------------------------------------------
|
| THE RACE. RmeInvoiceService::create() asks "does this visit already have an
| active invoice?" and then inserts one. Before Phase 0 nothing was locked, so
| request A could be mid-insert (its invoice uncommitted, therefore invisible to
| B under READ COMMITTED) while request B asked the same question, got "no", and
| inserted a second invoice. Both commit: one visit, two active invoices.
|
| THE PROOF. Session PROBE plays request A: it locks the visit row exactly as the
| fixed service does and inserts its invoice WITHOUT committing. The service then
| runs on session WRITER as request B, with a short lock_timeout:
|   - fixed code  → B waits on the visit lock and times out (55P03); after A
|                   commits there is exactly ONE invoice;
|   - broken code → B never waits, sees no committed invoice, inserts its own;
|                   after A commits there are TWO. The final count assertion fails.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing, so the
| property is unobservable there. The skip is stated, not silently passed.
|
| WHY NO RefreshDatabase. A second session cannot see — or lock — rows inside a
| transaction that is never committed. This suite commits for real and deletes
| every row it created (by id watermark) afterwards. Pattern lifted from
| tests/Feature/AccessControl/DailyBranchContextConcurrencyTest.php.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55434 DB_DATABASE=rf0 \
|   DB_USERNAME=rf0 DB_PASSWORD=rf0 \
|   php artisan test tests/Feature/RME/RmeBillingRefundPhase0InvoiceRaceConcurrencyTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const RF0_WRITER = 'rf0_concurrency_writer';
const RF0_PROBE = 'rf0_concurrency_probe';

/** Tables this suite writes to, in child-before-parent delete order. */
const RF0_TABLES = [
    'sys_audit_logs',
    'trx_rme_invoice_items',
    'trx_rme_invoices',
    'trx_medical_records',
    'trx_clinic_visits',
    'mst_clinic_rooms',
    'mst_patients',
    'mst_doctors',
    'mst_clinics',
    'mst_branches',
    'users',
];

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RF0_WRITER, $base);
    config()->set('database.connections.'.RF0_PROBE, $base);
    DB::purge(RF0_WRITER);
    DB::purge(RF0_PROBE);

    // The service and DB::transaction now resolve to a connection that really
    // commits — exactly as they do in production.
    config()->set('database.default', RF0_WRITER);

    seedAccessControl();

    $this->watermarks = [];
    foreach (RF0_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RF0_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RF0_WRITER);
    $writer->statement('RESET lock_timeout');

    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RF0_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }
});

/**
 * A billable visit, COMMITTED so a second session can see and lock it. Model
 * events are off so no observer writes a side table this suite would then have
 * to clean up; the service call under test is NOT wrapped, so it runs with
 * every production listener attached.
 *
 * @return array{0: ClinicVisit, 1: User, 2: MedicalRecord}
 */
function rf0CommittedBillableVisit(string $prefix): array
{
    return Model::withoutEvents(function () use ($prefix) {
        $branch = Branch::factory()->create(['code' => $prefix.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        $clinic = Clinic::factory()->create();
        $doctor = Doctor::factory()->create(['branch_id' => $branch->id]);
        $patient = Patient::factory()->create(['branch_id' => $branch->id]);
        $cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $branch->id,
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-'.$prefix.'-'.uniqid(),
        ]);
        $record = MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ]);

        return [$visit, $cashier, $record];
    });
}

it('serializes invoice creation per visit: an in-flight invoice blocks a second one', function () {
    [$visit, $cashier, $record] = rf0CommittedBillableVisit('RFC');

    $probe = DB::connection(RF0_PROBE);

    // Request A: the FIXED service mid-flight — it holds the visit lock the
    // service takes (FOR NO KEY UPDATE) and has inserted, not committed, its
    // invoice.
    //
    // The lock strength here is load-bearing; an earlier version of this test
    // got it wrong. Inserting a row whose FK references the visit takes FOR KEY
    // SHARE on the visit. A probe holding FOR UPDATE blocks that, so it blocked
    // the BROKEN service's own INSERT and the test passed with the fix removed
    // (mutation-verified). FOR NO KEY UPDATE does not conflict with FOR KEY
    // SHARE, so:
    //   fixed  B: FOR NO KEY UPDATE conflicts with A's FOR NO KEY UPDATE → waits;
    //   broken B: no lock; its FK insert needs only FOR KEY SHARE, which is
    //             compatible → it inserts a second invoice and the count fails.
    $probe->beginTransaction();
    $probe->select('SELECT id FROM trx_clinic_visits WHERE id = ? FOR NO KEY UPDATE', [$visit->id]);
    $probe->table('trx_rme_invoices')->insert([
        'branch_id' => $visit->branch_id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'medical_record_id' => $record->id,
        'cashier_id' => $cashier->id,
        'invoice_number' => 'RME-RACE-'.uniqid(),
        'status' => RmeInvoice::STATUS_UNPAID,
        'subtotal' => 100000,
        'discount_total' => 0,
        'grand_total' => 100000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Request B: the real service, on its own session.
    DB::connection(RF0_WRITER)->statement("SET lock_timeout = '1500ms'");

    $blocked = false;
    try {
        app(RmeInvoiceService::class)->create($visit, $cashier, [
            'items' => [['description' => 'Tindakan', 'qty' => 1, 'unit_price' => 100000, 'discount' => 0]],
        ]);
    } catch (QueryException $e) {
        // 55P03 lock_not_available — B waited on A's visit lock instead of racing past it.
        $blocked = $e->getCode() === '55P03' || str_contains($e->getMessage(), 'lock timeout');
    } finally {
        $probe->commit();
    }

    expect($blocked)->toBeTrue('The second create did not wait on the visit lock.')
        ->and(DB::connection(RF0_WRITER)->table('trx_rme_invoices')->where('clinic_visit_id', $visit->id)->count())->toBe(1)
        ->and(DB::connection(RF0_WRITER)->table('sys_audit_logs')
            ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)
            ->where('id', '>', $this->watermarks['sys_audit_logs'])
            ->count())->toBe(0);
});

it('does not block foreign-key writers while it holds the visit (no lock-order deadlock)', function () {
    [$visit, $cashier] = rf0CommittedBillableVisit('RFE');

    // Session PROBE holds exactly what ANY insert referencing this visit holds
    // through its foreign key: FOR KEY SHARE (e.g. a diagnosis row written by a
    // transaction that has already locked the visit's medical record).
    $probe = DB::connection(RF0_PROBE);
    $probe->beginTransaction();
    $probe->select('SELECT id FROM trx_clinic_visits WHERE id = ? FOR KEY SHARE', [$visit->id]);

    DB::connection(RF0_WRITER)->statement("SET lock_timeout = '1500ms'");

    // FOR NO KEY UPDATE is compatible with FOR KEY SHARE, so creation proceeds.
    // With FOR UPDATE it would wait here — and, against a writer that also holds
    // the medical record, deadlock.
    try {
        $invoice = app(RmeInvoiceService::class)->create($visit, $cashier, [
            'items' => [['description' => 'Tindakan', 'qty' => 1, 'unit_price' => 100000, 'discount' => 0]],
        ]);
    } finally {
        $probe->rollBack();
    }

    expect($invoice->exists)->toBeTrue();
});

it('creates normally once no other session holds the visit (positive control)', function () {
    [$visit, $cashier] = rf0CommittedBillableVisit('RFD');

    DB::connection(RF0_WRITER)->statement("SET lock_timeout = '1500ms'");

    $invoice = app(RmeInvoiceService::class)->create($visit, $cashier, [
        'items' => [['description' => 'Tindakan', 'qty' => 1, 'unit_price' => 100000, 'discount' => 0]],
    ]);

    expect($invoice->exists)->toBeTrue()
        ->and(DB::connection(RF0_WRITER)->table('sys_audit_logs')
            ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)
            ->where('entity_id', $invoice->id)
            ->count())->toBe(1);
});
