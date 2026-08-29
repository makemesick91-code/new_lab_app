<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

/**
 * REVISION-RME-REPORTS-TODAY-DEFAULT-1
 *
 * Both RME report pages default to the CLINICAL today. Historical rows appear
 * only when the operator explicitly supplies a date filter, and an explicit
 * date filter never widens the authorised branch scope.
 *
 * The canonical report date for BOTH reports is trx_clinic_visits.visit_date —
 * the payment report already reached it through clinicVisit, so this sprint
 * changes the DEFAULT, never the date semantics.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    // A fixed clinical instant: 2026-08-29 09:00 WITA (= 01:00 UTC), safely
    // inside one clinical day so the fixtures are never split by a rollover.
    Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->branch = Branch::factory()->create([
        'code' => 'TDF1',
        'name' => 'Cabang Today Default',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->patientViewer = userWith(['view_rme_patient_reports']);
    $this->paymentViewer = userWith(['view_rme_payment_reports']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function rtdPatient(array $overrides = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'medical_record_number' => 'RM-TDF-'.fake()->unique()->numerify('####'),
        'name' => 'Pasien Today Default',
    ], $overrides));
}

function rtdVisit(Patient $patient, array $overrides = [], ?Branch $branch = null): ClinicVisit
{
    $branch ??= test()->branch;

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create(['is_active' => true])->id,
        'visit_date' => clinicalToday()->toDateString(),
        'status' => ClinicVisit::STATUS_COMPLETED,
    ], $overrides));
}

function rtdPayment(Patient $patient, array $visitOverrides = [], ?Branch $branch = null): RmePayment
{
    $branch ??= test()->branch;
    $doctor = Doctor::factory()->create(['is_active' => true]);
    $visit = rtdVisit($patient, array_merge(['doctor_id' => $doctor->id], $visitOverrides), $branch);

    $invoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'grand_total' => 250000,
    ]);

    RmeInvoiceItem::create([
        'rme_invoice_id' => $invoice->id,
        'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
        'doctor_id' => $doctor->id,
        'description' => 'Tindakan Today Default',
        'qty' => 1,
        'unit_price' => 250000,
        'discount' => 0,
        'subtotal' => 250000,
    ]);

    return RmePayment::factory()->create([
        'branch_id' => $branch->id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'payment_method_id' => PaymentMethod::factory()->create(['is_active' => true])->id,
        'amount' => 250000,
        'paid_at' => now(),
    ]);
}

/** Today + yesterday + last month, on the clinical calendar. */
function rtdSeedThreeDays(): array
{
    $today = rtdPatient(['name' => 'Pasien Hari Ini', 'medical_record_number' => 'RM-TDF-TODAY']);
    $yesterday = rtdPatient(['name' => 'Pasien Kemarin', 'medical_record_number' => 'RM-TDF-YDAY']);
    $older = rtdPatient(['name' => 'Pasien Lampau', 'medical_record_number' => 'RM-TDF-OLD']);

    rtdVisit($today);
    rtdVisit($yesterday, ['visit_date' => clinicalToday()->copy()->subDay()->toDateString()]);
    rtdVisit($older, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);

    return [$today, $yesterday, $older];
}

/* ------------------------------------------------ patient report — default */

it('defaults the patient report to the clinical today and hides older visits', function () {
    rtdSeedThreeDays();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertSee('RM-TDF-TODAY')
        ->assertDontSee('RM-TDF-YDAY')
        ->assertDontSee('RM-TDF-OLD');
});

it('shows the active period on the patient report so today-only is explicit', function () {
    rtdSeedThreeDays();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertSee('Hari Ini')
        ->assertSee('29 Agustus 2026');
});

it('returns the patient report to today when the filter is reset', function () {
    rtdSeedThreeDays();

    // A reset is a request back to the bare report URL.
    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertSee('RM-TDF-TODAY')
        ->assertDontSee('RM-TDF-OLD');
});

/* ----------------------------------------------- patient report — explicit */

it('shows historical patient visits only when a date filter is supplied', function () {
    rtdSeedThreeDays();
    $yesterday = clinicalToday()->copy()->subDay()->toDateString();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', ['date_from' => $yesterday, 'date_to' => $yesterday]))
        ->assertOk()
        ->assertSee('RM-TDF-YDAY')
        ->assertDontSee('RM-TDF-TODAY')
        ->assertDontSee('RM-TDF-OLD');
});

it('honours an explicit multi-day patient range that includes today', function () {
    rtdSeedThreeDays();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', [
            'date_from' => clinicalToday()->copy()->subDay()->toDateString(),
            'date_to' => clinicalToday()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('RM-TDF-TODAY')
        ->assertSee('RM-TDF-YDAY')
        ->assertDontSee('RM-TDF-OLD');
});

it('honours an explicit open-ended patient date_from', function () {
    rtdSeedThreeDays();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', [
            'date_from' => clinicalToday()->copy()->subDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('RM-TDF-TODAY')
        ->assertSee('RM-TDF-YDAY')
        ->assertDontSee('RM-TDF-OLD');
});

/* -------------------------------------------------- search stays scoped */

it('keeps the patient report search inside the default today scope', function () {
    rtdSeedThreeDays();

    // "Pasien Lampau" exists, but only outside today — searching must not
    // silently reach back through the whole archive.
    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', ['q' => 'Pasien Lampau']))
        ->assertOk()
        ->assertDontSee('RM-TDF-OLD');
});

it('finds an older patient by search only when the date filter is widened', function () {
    rtdSeedThreeDays();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', [
            'q' => 'Pasien Lampau',
            'date_from' => clinicalToday()->copy()->subMonth()->toDateString(),
            'date_to' => clinicalToday()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('RM-TDF-OLD');
});

/* ------------------------------------------------------- fail-safe filters */

it('falls back to today when the patient date filter is malformed', function () {
    rtdSeedThreeDays();

    foreach ([
        ['date_from' => 'not-a-date'],
        ['date_from' => '', 'date_to' => ''],
        ['date_from' => '2026-13-45'],
        ['date_from' => '29/08/2026'],
    ] as $query) {
        $this->actingAs($this->patientViewer)
            ->get(route('rme.reports.patients', $query))
            ->assertOk()
            ->assertSee('RM-TDF-TODAY')
            ->assertDontSee('RM-TDF-OLD');
    }
});

it('falls back to today when the patient date filter is not even a string', function () {
    rtdSeedThreeDays();

    // ?date_from[]=... makes Request::input() return an array. A date bound that
    // is not a string is not a date, and must not be allowed to slip past the
    // parser into an unbounded period.
    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients', [
            'date_from' => ['2020-01-01'],
            'date_to' => ['2030-01-01'],
        ]))
        ->assertOk()
        ->assertSee('RM-TDF-TODAY')
        ->assertDontSee('RM-TDF-YDAY')
        ->assertDontSee('RM-TDF-OLD');
});

/* --------------------------------------------------- patient export / print */

it('defaults the patient CSV export to today', function () {
    rtdSeedThreeDays();

    $csv = $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients.export'))
        ->streamedContent();

    expect($csv)->toContain('RM-TDF-TODAY')
        ->not->toContain('RM-TDF-YDAY')
        ->not->toContain('RM-TDF-OLD');
});

it('matches the patient CSV export to an explicit date filter', function () {
    rtdSeedThreeDays();
    $yesterday = clinicalToday()->copy()->subDay()->toDateString();

    $csv = $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients.export', ['date_from' => $yesterday, 'date_to' => $yesterday]))
        ->streamedContent();

    expect($csv)->toContain('RM-TDF-YDAY')
        ->not->toContain('RM-TDF-TODAY')
        ->not->toContain('RM-TDF-OLD');
});

it('defaults the patient print view to today and states the period', function () {
    rtdSeedThreeDays();

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients.print'))
        ->assertOk()
        ->assertSee('RM-TDF-TODAY')
        ->assertDontSee('RM-TDF-OLD')
        ->assertSee('29 Agustus 2026');
});

/* ------------------------------------------------ payment report — default */

it('defaults the payment report to the clinical today and hides older payments', function () {
    $today = rtdPatient(['name' => 'Bayar Hari Ini', 'medical_record_number' => 'RM-PAY-TODAY']);
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);

    rtdPayment($today);
    rtdPayment($old, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments'))
        ->assertOk()
        ->assertSee('RM-PAY-TODAY')
        ->assertDontSee('RM-PAY-OLD')
        ->assertSee('Hari Ini');
});

it('shows historical payments only when a date filter is supplied', function () {
    $today = rtdPatient(['name' => 'Bayar Hari Ini', 'medical_record_number' => 'RM-PAY-TODAY']);
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);
    $lastMonth = clinicalToday()->copy()->subMonth()->toDateString();

    rtdPayment($today);
    rtdPayment($old, ['visit_date' => $lastMonth]);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments', ['date_from' => $lastMonth, 'date_to' => $lastMonth]))
        ->assertOk()
        ->assertSee('RM-PAY-OLD')
        ->assertDontSee('RM-PAY-TODAY');
});

it('keeps the payment report search inside the default today scope', function () {
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);
    rtdPayment($old, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments', ['q' => 'Bayar Lampau']))
        ->assertOk()
        ->assertDontSee('RM-PAY-OLD');
});

it('falls back to today when the payment date filter is malformed', function () {
    $today = rtdPatient(['name' => 'Bayar Hari Ini', 'medical_record_number' => 'RM-PAY-TODAY']);
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);

    rtdPayment($today);
    rtdPayment($old, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments', ['date_from' => 'rubbish']))
        ->assertOk()
        ->assertSee('RM-PAY-TODAY')
        ->assertDontSee('RM-PAY-OLD');
});

/* --------------------------------------------------- payment export / print */

it('defaults the payment CSV export to today', function () {
    $today = rtdPatient(['name' => 'Bayar Hari Ini', 'medical_record_number' => 'RM-PAY-TODAY']);
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);

    rtdPayment($today);
    rtdPayment($old, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);

    $csv = $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments.export'))
        ->streamedContent();

    expect($csv)->toContain('RM-PAY-TODAY')->not->toContain('RM-PAY-OLD');
});

it('matches the payment CSV export to an explicit date filter', function () {
    $today = rtdPatient(['name' => 'Bayar Hari Ini', 'medical_record_number' => 'RM-PAY-TODAY']);
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);
    $lastMonth = clinicalToday()->copy()->subMonth()->toDateString();

    rtdPayment($today);
    rtdPayment($old, ['visit_date' => $lastMonth]);

    $csv = $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments.export', ['date_from' => $lastMonth, 'date_to' => $lastMonth]))
        ->streamedContent();

    expect($csv)->toContain('RM-PAY-OLD')->not->toContain('RM-PAY-TODAY');
});

it('defaults the payment print view to today', function () {
    $today = rtdPatient(['name' => 'Bayar Hari Ini', 'medical_record_number' => 'RM-PAY-TODAY']);
    $old = rtdPatient(['name' => 'Bayar Lampau', 'medical_record_number' => 'RM-PAY-OLD']);

    rtdPayment($today);
    rtdPayment($old, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);

    $this->actingAs($this->paymentViewer)
        ->get(route('rme.reports.payments.print'))
        ->assertOk()
        ->assertSee('RM-PAY-TODAY')
        ->assertDontSee('RM-PAY-OLD');
});

/* ------------------------------------------------------- branch × date */

it('keeps the Admin Klinik patient report on its working branch and today', function () {
    $other = Branch::factory()->create([
        'code' => 'TDF2', 'name' => 'Cabang Lain', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $mine = rtdPatient(['name' => 'Pasien Cabang Saya', 'medical_record_number' => 'RM-TDF-MINE']);
    $mineOld = rtdPatient(['name' => 'Pasien Cabang Saya Lama', 'medical_record_number' => 'RM-TDF-MINEOLD']);
    $theirs = rtdPatient(['branch_id' => $other->id, 'name' => 'Pasien Cabang Lain', 'medical_record_number' => 'RM-TDF-THEIRS']);

    rtdVisit($mine);
    rtdVisit($mineOld, ['visit_date' => clinicalToday()->copy()->subDay()->toDateString()]);
    rtdVisit($theirs, [], $other);

    $admin = rmeAdminClinicUser($this->branch);

    $this->actingAs($admin)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertSee('RM-TDF-MINE')
        ->assertDontSee('RM-TDF-MINEOLD')
        ->assertDontSee('RM-TDF-THEIRS');
});

it('never lets a historical date filter widen the Admin Klinik branch scope', function () {
    $other = Branch::factory()->create([
        'code' => 'TDF3', 'name' => 'Cabang Lain Tiga', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $mineOld = rtdPatient(['name' => 'Pasien Saya Lama', 'medical_record_number' => 'RM-TDF-MINEOLD']);
    $theirsOld = rtdPatient(['branch_id' => $other->id, 'name' => 'Pasien Lain Lama', 'medical_record_number' => 'RM-TDF-THEIRSOLD']);

    $yesterday = clinicalToday()->copy()->subDay()->toDateString();
    rtdVisit($mineOld, ['visit_date' => $yesterday]);
    rtdVisit($theirsOld, ['visit_date' => $yesterday], $other);

    $admin = rmeAdminClinicUser($this->branch);

    // Historical range AND a crafted foreign branch_id: the date widens the
    // period, never the branch authority.
    $this->actingAs($admin)
        ->get(route('rme.reports.patients', [
            'date_from' => $yesterday,
            'date_to' => $yesterday,
            'branch_id' => $other->id,
        ]))
        ->assertOk()
        ->assertSee('RM-TDF-MINEOLD')
        ->assertDontSee('RM-TDF-THEIRSOLD');
});

it('never echoes a foreign branch name into the printed filter summary', function () {
    // The row data is protected twice over: RmeWorkingBranchScope::narrow()
    // re-checks membership even if the controller's allows() guard is removed.
    // The filter summary is the ONE place a crafted branch_id is not re-checked
    // downstream, so the guard is load-bearing there and is pinned here.
    $other = Branch::factory()->create([
        'code' => 'TDF6', 'name' => 'Cabang Rahasia Enam', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $mine = rtdPatient(['name' => 'Pasien Cetak', 'medical_record_number' => 'RM-TDF-PRINT']);
    rtdVisit($mine);

    $admin = rmeAdminClinicUser($this->branch);

    $this->actingAs($admin)
        ->get(route('rme.reports.patients.print', ['branch_id' => $other->id]))
        ->assertOk()
        ->assertSee('RM-TDF-PRINT')
        ->assertDontSee('Cabang Rahasia Enam');
});

it('keeps the Kasir payment report on its working branch and today', function () {
    $other = Branch::factory()->create([
        'code' => 'TDF4', 'name' => 'Cabang Kasir Lain', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $mine = rtdPatient(['name' => 'Bayar Cabang Saya', 'medical_record_number' => 'RM-PAY-MINE']);
    $mineOld = rtdPatient(['name' => 'Bayar Cabang Saya Lama', 'medical_record_number' => 'RM-PAY-MINEOLD']);
    $theirs = rtdPatient(['branch_id' => $other->id, 'name' => 'Bayar Cabang Lain', 'medical_record_number' => 'RM-PAY-THEIRS']);

    rtdPayment($mine);
    rtdPayment($mineOld, ['visit_date' => clinicalToday()->copy()->subDay()->toDateString()]);
    rtdPayment($theirs, [], $other);

    $kasir = userInRole('Kasir');
    $kasir->givePermissionTo('view_rme_payment_reports');
    rmeMakeKasirActive($kasir, $this->branch);

    $this->actingAs($kasir)
        ->get(route('rme.reports.payments'))
        ->assertOk()
        ->assertSee('RM-PAY-MINE')
        ->assertDontSee('RM-PAY-MINEOLD')
        ->assertDontSee('RM-PAY-THEIRS');
});

it('keeps a crafted export URL with blank dates inside today and the branch', function () {
    $other = Branch::factory()->create([
        'code' => 'TDF5', 'name' => 'Cabang Export Lain', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $mine = rtdPatient(['name' => 'Export Saya', 'medical_record_number' => 'RM-TDF-EXPMINE']);
    $mineOld = rtdPatient(['name' => 'Export Saya Lama', 'medical_record_number' => 'RM-TDF-EXPOLD']);
    $theirs = rtdPatient(['branch_id' => $other->id, 'name' => 'Export Lain', 'medical_record_number' => 'RM-TDF-EXPTHEIRS']);

    rtdVisit($mine);
    rtdVisit($mineOld, ['visit_date' => clinicalToday()->copy()->subMonth()->toDateString()]);
    rtdVisit($theirs, [], $other);

    $admin = rmeAdminClinicUser($this->branch);

    $csv = $this->actingAs($admin)
        ->get(route('rme.reports.patients.export', ['date_from' => '', 'date_to' => '', 'branch_id' => $other->id]))
        ->streamedContent();

    expect($csv)->toContain('RM-TDF-EXPMINE')
        ->not->toContain('RM-TDF-EXPOLD')
        ->not->toContain('RM-TDF-EXPTHEIRS');
});

/* --------------------------------------------------------------- midnight */

it('rolls the default period over at the clinical midnight, not the UTC one', function () {
    $patient = rtdPatient(['name' => 'Pasien Rollover', 'medical_record_number' => 'RM-TDF-ROLL']);

    // 2026-08-29 23:59:59 WITA — still the 29th in the clinic.
    Carbon::setTestNow(Carbon::parse('2026-08-29 15:59:59', 'UTC'));
    rtdVisit($patient, ['visit_date' => '2026-08-29']);

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertSee('RM-TDF-ROLL')
        ->assertSee('29 Agustus 2026');

    // 2026-08-30 00:00:00 WITA — a new clinical day; the 29th is now history.
    Carbon::setTestNow(Carbon::parse('2026-08-29 16:00:00', 'UTC'));

    $this->actingAs($this->patientViewer)
        ->get(route('rme.reports.patients'))
        ->assertOk()
        ->assertDontSee('RM-TDF-ROLL')
        ->assertSee('30 Agustus 2026');
});
