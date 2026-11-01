<?php

declare(strict_types=1);

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the doctor's structured
| record as an input to the rule 218 clinical vs billing cross-check and to the
| cashier projection (DPT-047…062). The cross-check stays DETECTIVE (CB-Q2): no
| owner decision has approved a blocking contract, CONSISTENT or
| CONFIRMED_DISCREPANCY, so none is produced.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentState;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeClinicalBillingConsistency;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => dptSetup());

/** @return array<string, mixed> the clinical_billing_consistency snapshot of a closure */
function dptClosureSnapshot(RmeInvoice $invoice): array
{
    return (array) (AuditLog::query()
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', $invoice->id)
        ->value('new_values')['clinical_billing_consistency'] ?? []);
}

it('DPT-047 matches a performed treatment to the invoice line that carries its identity', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);
    $invoice = rzvInvoice($visit, [rzvLine($this->scaling, 250000, 0, 'Scaling')]);

    $projection = dptService()->cashierProjectionFor($visit->fresh(), $invoice);

    expect($projection['rows'][0]['treatment_id'])->toBe((int) $this->scaling->id)
        ->and($projection['rows'][0]['billed'])->toBeTrue()
        ->and($projection['unbilled_count'])->toBe(0)
        ->and($projection['billed_not_performed'])->toBe([]);
});

it('DPT-048 detects a performed, normally paid treatment omitted from a Rp0 closure — detective, never a refusal', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);

    $closed = rzvClose(rzvConsultationInvoice($visit));
    $snapshot = dptClosureSnapshot($closed);

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($snapshot['status'])->toBe(RmeClinicalBillingConsistency::STATUS_REVIEW_REQUIRED)
        ->and($snapshot['signals'])->toContain(RmeClinicalBillingConsistency::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED)
        ->and($snapshot['doctor_evidence']['unbilled_performed_count'])->toBe(1);
});

it('DPT-049 detects a wrong invoice identity in the cashier projection', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);
    $invoice = rzvInvoice($visit, [rzvLine($this->filling, 150000, 0, 'Tambal')]);

    $projection = dptService()->cashierProjectionFor($visit->fresh(), $invoice);

    expect($projection['rows'][0]['billed'])->toBeFalse()
        ->and($projection['unbilled_count'])->toBe(1)
        ->and($projection['billed_not_performed'])->toBe([(int) $this->filling->id]);
});

it('DPT-050 supports a legitimate free Konsultasi on a no-treatment visit', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $closed = rzvClose(rzvConsultationInvoice($visit));
    $snapshot = dptClosureSnapshot($closed);

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($snapshot['signals'])->not->toContain(RmeClinicalBillingConsistency::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED)
        ->and($snapshot['doctor_evidence']['state'])->toBe(RmePerformedTreatmentState::NO_TREATMENT_DECLARED);
});

it('DPT-051 supports a legitimate free Kontrol with a valid parent', function () {
    $parent = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'status' => 'completed',
        'visit_date' => clinicalToday()->subDays(7)->toDateString(),
    ]);
    $visit = dptEncounter(['visit_type' => 'control', 'follow_up_of_visit_id' => $parent->id]);
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $closed = rzvClose(rzvInvoice($visit, [rzvLine($this->controlService)]));

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)->and($closed->zero_value_category)->toBe('CONTROL');
});

it('DPT-052 bills a performed paid treatment on a Kontrol normally — the mixed invoice is never NO_CHARGE', function () {
    $parent = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'status' => 'completed',
        'visit_date' => clinicalToday()->subDays(7)->toDateString(),
    ]);
    $visit = dptEncounter(['visit_type' => 'control', 'follow_up_of_visit_id' => $parent->id]);
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);

    $invoice = rzvInvoice($visit, [rzvLine($this->controlService), rzvLine($this->scaling, 250000, 0, 'Scaling')]);

    expect((float) $invoice->grand_total)->toBe(250000.0)
        ->and($invoice->isPayable())->toBeTrue()
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO);
});

it('DPT-053 keeps manual pricing (D21): the clinical record imposes no price', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);

    $invoice = rzvInvoice($visit, [rzvLine($this->scaling, 123457, 0, 'Scaling')]);

    expect((float) $invoice->grand_total)->toBe(123457.0);
});

it('DPT-054/055/056 generates no invoice, assigns no price and records no payment', function () {
    $invoices = DB::table('trx_rme_invoices')->count();
    $money = rzvMoney();
    $visit = dptEncounter();

    dptRecord($visit, $this->scaling);

    expect(DB::table('trx_rme_invoices')->count())->toBe($invoices)
        ->and(rzvMoney())->toBe($money)
        ->and(Schema::hasColumn('trx_rme_performed_treatments', 'unit_price'))->toBeFalse()
        ->and(Schema::hasColumn('trx_rme_performed_treatments', 'price'))->toBeFalse()
        ->and(Schema::hasColumn('trx_rme_performed_treatments', 'amount'))->toBeFalse();
});

it('DPT-057 never counts a voided (not performed) row as billable evidence', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    dptVoid($visit, $row, 'Tidak jadi dilakukan.');
    dptCompleteExamination($visit);

    $snapshot = dptClosureSnapshot(rzvClose(rzvConsultationInvoice($visit)));

    expect($snapshot['signals'])->not->toContain(RmeClinicalBillingConsistency::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED)
        ->and($snapshot['doctor_evidence']['performed_count'])->toBe(0);
});

it('DPT-058 never reports insufficient evidence as consistent', function () {
    $visit = dptEncounter();
    dptCompleteExamination($visit);

    $snapshot = dptClosureSnapshot(rzvClose(rzvConsultationInvoice($visit)));

    expect($snapshot['status'])->toBe(RmeClinicalBillingConsistency::STATUS_INSUFFICIENT_STRUCTURED_EVIDENCE)
        ->and($snapshot['doctor_evidence']['state'])->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(json_encode($snapshot))->not->toContain('CONSISTENT"');
});

it('DPT-059 never produces CONSISTENT or CONFIRMED_DISCREPANCY without an approved matching contract', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);

    $snapshot = dptClosureSnapshot(rzvClose(rzvConsultationInvoice($visit)));

    expect($snapshot['status'])->not->toBe('CONSISTENT')
        ->and($snapshot['status'])->not->toBe('CONFIRMED_DISCREPANCY')
        ->and(defined(RmeClinicalBillingConsistency::class.'::STATUS_CONSISTENT'))->toBeFalse()
        ->and(defined(RmeClinicalBillingConsistency::class.'::STATUS_CONFIRMED_DISCREPANCY'))->toBeFalse();
});

it('DPT-060 keeps the unapproved blocking contract inactive — the closure is not refused', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptRecord($visit, $this->filling, ['tooth_numbers' => ['16']]);
    dptCompleteExamination($visit);

    expect(rzvRefusal(rzvConsultationInvoice($visit)))->toBeNull();
});

it('DPT-061 keeps D28 enforced after a performed treatment is found on a Rp0 original', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);
    $invoice = rzvConsultationInvoice($visit);

    ricDesignateMaker($this->maker);

    expect(dptRefusal(fn () => ricSubmit($invoice, $this->maker, [rzvLine($this->scaling, 250000, 0, 'Scaling')])))->toContain('CORRECTION_')
        ->and($invoice->fresh()->correction_version)->toBe(0)
        ->and((float) $invoice->fresh()->grand_total)->toBe(0.0);
});

it('DPT-062 keeps the rule 218 snapshot byte-compatible while the capability flag is OFF', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);
    dptFlag(false);

    $snapshot = dptClosureSnapshot(rzvClose(rzvConsultationInvoice($visit)));

    expect($snapshot['version'])->toBe(1)
        ->and($snapshot)->not->toHaveKey('doctor_evidence')
        ->and($snapshot['signals'])->not->toContain(RmeClinicalBillingConsistency::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED);
});

it('records the doctor evidence in a version 2 snapshot when the capability is ON', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $snapshot = dptClosureSnapshot(rzvClose(rzvConsultationInvoice($visit)));

    expect($snapshot['version'])->toBe(2)
        ->and(array_keys($snapshot['doctor_evidence']))->toEqualCanonicalizing([
            'state', 'operational_visit_category', 'declaration_version', 'performed_count',
            'performed_treatment_ids', 'unbilled_performed_count', 'unbilled_performed_treatment_ids',
        ]);
});

it('renders the doctor treatments on the cashier pages only while the capability is ON', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);
    dptCompleteExamination($visit);
    $invoice = rzvInvoice($visit, [rzvLine($this->scaling, 250000, 0, 'Scaling')]);

    rmeMakeKasirActive($this->cashier, $this->branch);
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('data-dpt-cashier-projection', false)
        ->assertSee($this->scaling->name);

    dptFlag(false);
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertDontSee('data-dpt-cashier-projection', false);
});

it('DPT-033 shows the doctor declaration and the performed-not-billed signal on the authorized oversight report', function () {
    $declared = dptEncounter();
    dptDeclare($declared, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($declared);
    rzvClose(rzvConsultationInvoice($declared));

    $performed = dptEncounter();
    dptRecord($performed, $this->scaling);
    dptCompleteExamination($performed);
    rzvClose(rzvConsultationInvoice($performed));

    $html = $this->actingAs(userInRole('Owner'))
        ->get(route('oversight.billing-corrections.zero-value-closures.index'))
        ->assertOk()
        ->assertSee('data-zv-dpt-state="NO_TREATMENT_DECLARED"', false)
        ->assertSee('data-zv-dpt-state="HAS_PERFORMED_TREATMENTS"', false)
        ->assertSee('data-zv-cbc-signal="CBC_PERFORMED_TREATMENT_NOT_BILLED"', false)
        ->getContent();

    expect($html)->toMatch('/data-zv-dpt-no-treatment>\s*1\s*</')
        ->and($html)->toMatch('/data-zv-cbc-signal-performed>\s*1\s*</')
        ->and($html)->not->toContain($this->patient->name);
});

it('DPT-094 decides "unbilled paid treatment" by the SAME line predicate as the closure — a treatment now in an approved free category is not reported', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);

    expect(dptService()->evidenceFor($visit)->unbilledTreatmentIds([], false))->toBe([(int) $this->scaling->id]);

    // Master data moves the treatment into the approved-free Konsultasi category:
    // a Rp0 line for it would now be eligible under rule 217, so the detective
    // signal must not contradict the closure (mutation M17).
    $this->scaling->update(['treatment_category_id' => $this->consultation->treatment_category_id]);

    expect(dptService()->evidenceFor($visit)->unbilledTreatmentIds([], false))->toBe([])
        ->and(RmePerformedTreatment::query()->sole()->treatment_category_code_snapshot)->toBe('restorative');
});
