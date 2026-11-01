<?php

declare(strict_types=1);

/*
| HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1 (rule 218) — the read-only
| "Tanpa Biaya (Rp0)" oversight shows, per closure, the clinical vs billing
| cross-check AS RECORDED AT CLOSURE (the immutable audit snapshot), counts it, and
| filters by it — inside the same gate, permission and branch scope, with no patient
| identity, no tooth number, no clinical note and no way to write anything.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

const RCCO_SECRET_NOTE = 'CATATAN-OVERSIGHT-RAHASIA-RCCO';

beforeEach(function () {
    rzvSetup();

    $preventive = TreatmentCategory::query()->firstOrCreate(['code' => 'preventive'], ['name' => 'Preventif']);
    test()->scaling = Treatment::factory()->create([
        'code' => 'TRT-SCAL', 'name' => 'Scaling Gigi', 'treatment_category_id' => $preventive->id,
        'is_active' => true, 'requires_lab' => false,
    ]);

    // One closure with a signal (Tindakan Awal Scaling + an odontogram change)…
    $earlier = ClinicVisit::factory()->completed()->create([
        'branch_id' => test()->branch->id, 'clinic_id' => test()->clinic->id, 'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-RCCO-'.uniqid(), 'visit_date' => clinicalToday()->subDays(7)->toDateString(),
    ]);
    $chart = fn (ClinicVisit $visit, string $status) => Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'status' => Odontogram::STATUS_FINALIZED,
        'tooth_map_payload' => ['teeth' => ['16' => ['status' => $status, 'additional_note' => RCCO_SECRET_NOTE]]],
    ]);
    $chart($earlier, 'caries');

    $flagged = rzvNewVisit();
    DB::table('trx_clinic_visits')->where('id', $flagged->id)->update(['initial_treatment_id' => test()->scaling->id]);
    $chart($flagged, 'filling');
    test()->reviewClosed = rzvClose(rzvInvoice($flagged->fresh(), [rzvLine(test()->consultation)]));

    // …and one with none.
    test()->plainClosed = rzvClose(rzvConsultationInvoice());
});

function rccoPage(string $role, array $query = [])
{
    return test()->actingAs(userInRole($role))->get(route('oversight.billing-corrections.zero-value-closures.index', $query));
}

it('shows the cross-check recorded at closure for every row and counts it', function (string $role) {
    $html = rccoPage($role)->assertOk()
        ->assertSee('data-zv-cbc-status="REVIEW_REQUIRED"', false)
        ->assertSee('data-zv-cbc-status="INSUFFICIENT_STRUCTURED_EVIDENCE"', false)
        ->assertSee('Perlu ditinjau')
        ->getContent();

    expect($html)->toMatch('/data-zv-cbc-review>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-cbc-insufficient>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-cbc-not-evaluated>\s*0\s*</')
        ->and($html)->toMatch('/data-zv-cbc-signal-registered>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-cbc-signal-odontogram>\s*1\s*</')
        // No tooth number, no clinical note, no patient identity.
        ->and($html)->not->toContain(RCCO_SECRET_NOTE)
        ->and($html)->not->toContain(test()->patient->name);
})->with(['Owner', 'Finance Klinik', 'Data Analyst']);

it('narrows by cross-check status', function () {
    rccoPage('Owner', ['consistency_status' => 'REVIEW_REQUIRED'])->assertOk()
        ->assertSee(test()->reviewClosed->invoice_number)
        ->assertDontSee(test()->plainClosed->invoice_number);

    rccoPage('Owner', ['consistency_status' => 'INSUFFICIENT_STRUCTURED_EVIDENCE'])->assertOk()
        ->assertSee(test()->plainClosed->invoice_number)
        ->assertDontSee(test()->reviewClosed->invoice_number);
});

it('narrows by signal', function () {
    rccoPage('Owner', ['consistency_signal' => 'CBC_ODONTOGRAM_PROCEDURE_CHANGE'])->assertOk()
        ->assertSee(test()->reviewClosed->invoice_number)
        ->assertDontSee(test()->plainClosed->invoice_number);
});

it('rejects a status or signal outside the vocabulary — CONSISTENT and CONFIRMED_DISCREPANCY are not filterable because they are never recorded', function (string $key, string $value) {
    rccoPage('Owner', [$key => $value])->assertSessionHasErrors($key);
})->with([
    ['consistency_status', 'CONSISTENT'],
    ['consistency_status', 'CONFIRMED_DISCREPANCY'],
    ['consistency_signal', 'ANYTHING'],
]);

it('shows a closure recorded before the cross-check existed as not evaluated — never as clear', function () {
    $row = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', test()->plainClosed->id)->sole();
    $values = $row->new_values;
    unset($values['clinical_billing_consistency']);
    DB::table('sys_audit_logs')->where('id', $row->id)->update(['new_values' => json_encode($values)]);

    $html = rccoPage('Owner')->assertOk()->assertSee('data-zv-cbc-status="NOT_EVALUATED"', false)->getContent();

    expect($html)->toMatch('/data-zv-cbc-not-evaluated>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-cbc-insufficient>\s*0\s*</');

    rccoPage('Owner', ['consistency_status' => 'NOT_EVALUATED'])->assertOk()
        ->assertSee(test()->plainClosed->invoice_number)
        ->assertDontSee(test()->reviewClosed->invoice_number);
});

it('a cross-check filter never widens the branch scope', function () {
    $outside = Branch::factory()->create(['code' => 'RCCOX', 'is_rme_enabled' => true, 'is_active' => true]);

    rccoPage('Owner', ['consistency_status' => 'REVIEW_REQUIRED', 'branch_id' => $outside->id])->assertOk()
        ->assertDontSee(test()->reviewClosed->invoice_number);
});

it('stays GET-only', function () {
    $methods = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'oversight.billing-corrections.zero-value-closures'))
        ->flatMap(fn ($r) => $r->methods())->unique()->sort()->values()->all();

    expect($methods)->toBe(['GET', 'HEAD']);
});
