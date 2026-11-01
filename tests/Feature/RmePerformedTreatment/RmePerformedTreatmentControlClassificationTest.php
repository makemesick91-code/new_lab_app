<?php

declare(strict_types=1);

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — owner decision DPT-Q1
| (revised and approved): an EXPLICIT "Tidak Ada Tindakan" declaration makes the
| visit's OPERATIONAL category KONTROL. It never rewrites visit_type, never
| invents a parent visit, and never makes anything free on its own — Rp0
| eligibility stays rule 217 (DPT-019…036).
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmeOperationalVisitCategory;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentAuditEvent;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentState;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => dptSetup());

/** A completed, examined parent visit of the fixture patient. */
function dptParentVisit(string $status = ClinicVisit::STATUS_COMPLETED): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id, 'clinic_id' => test()->clinic->id, 'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id, 'status' => $status, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-DPT-PARENT-'.uniqid(), 'visit_date' => clinicalToday()->subDays(14)->toDateString(),
    ]);
}

it('DPT-019 lets the treating doctor declare that no treatment was performed', function () {
    $visit = dptEncounter();

    $declaration = dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect($declaration->declaration_type)->toBe(RmeTreatmentDeclaration::TYPE_NO_TREATMENT)
        ->and((int) $declaration->declared_by)->toBe((int) $this->doctorUser->id)
        ->and((int) $declaration->declared_by_doctor_id)->toBe((int) $this->doctor->id)
        ->and(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NO_TREATMENT_DECLARED);
});

it('DPT-020 classifies an explicit no-treatment visit as operational KONTROL', function () {
    $visit = dptEncounter();

    $declaration = dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect($declaration->operational_visit_category)->toBe(RmeOperationalVisitCategory::CONTROL)
        ->and(dptService()->operationalCategoryFor($visit))->toBe(RmeOperationalVisitCategory::CONTROL);
});

it('DPT-021 never treats an empty treatment list without a declaration as KONTROL', function () {
    $visit = dptEncounter();

    expect(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(dptService()->operationalCategoryFor($visit))->toBeNull();

    // A doctor who opened "Ada Tindakan" and then removed every row has not
    // declared "no treatment" either.
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_HAS_TREATMENT);
    $row = dptRecord($visit, $this->scaling);
    dptVoid($visit, $row);

    expect(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(dptService()->operationalCategoryFor($visit))->toBeNull();
});

it('DPT-022 never lets "no treatment" coexist with an active performed treatment', function () {
    $declared = dptEncounter();
    dptDeclare($declared, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect(dptRefusal(fn () => dptRecord($declared, $this->scaling)))->toContain(RmePerformedTreatmentRefusal::DECLARED_NO_TREATMENT)
        ->and(RmePerformedTreatment::query()->where('clinic_visit_id', $declared->id)->count())->toBe(0);

    $recorded = dptEncounter();
    dptRecord($recorded, $this->scaling);

    expect(dptRefusal(fn () => dptDeclare($recorded, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain(RmePerformedTreatmentRefusal::HAS_ACTIVE_TREATMENTS)
        ->and(RmeTreatmentDeclaration::query()->where('clinic_visit_id', $recorded->id)->value('declaration_type'))
        ->toBe(RmeTreatmentDeclaration::TYPE_HAS_TREATMENT);
});

it('DPT-023 changes a draft from NO_TREATMENT to HAS_TREATMENT consistently and audibly', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    $declaration = dptDeclare($visit, RmeTreatmentDeclaration::TYPE_HAS_TREATMENT);
    dptRecord($visit, $this->scaling);

    expect($declaration->declaration_type)->toBe(RmeTreatmentDeclaration::TYPE_HAS_TREATMENT)
        ->and($declaration->operational_visit_category)->toBeNull()
        ->and((int) $declaration->version)->toBe(2)
        ->and(RmeTreatmentDeclaration::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1)
        ->and(dptService()->stateFor($visit))->toBe(RmePerformedTreatmentState::HAS_PERFORMED_TREATMENTS)
        // Only an explicit "Tidak Ada Tindakan" is operational KONTROL; a visit WITH
        // treatments has no operational category (mutation M02).
        ->and(dptService()->operationalCategoryFor($visit))->toBeNull()
        ->and(dptService()->evidenceFor($visit)?->operationalCategory)->toBeNull()
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::DECLARED))->toBe(2);
});

it('DPT-096 treats a repeated declaration of the same answer as a no-op — no version bump, no second audit row', function () {
    $visit = dptEncounter();

    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    $again = dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect((int) $again->version)->toBe(1)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::DECLARED))->toBe(1);
});

it('DPT-024 changes HAS_TREATMENT to NO_TREATMENT only after every row is explicitly voided, each audited', function () {
    $visit = dptEncounter();
    $a = dptRecord($visit, $this->scaling);
    $b = dptRecord($visit, $this->filling, ['tooth_numbers' => ['16']]);

    dptVoid($visit, $a, 'Tidak jadi dilakukan.');
    dptVoid($visit, $b, 'Tidak jadi dilakukan.');
    $declaration = dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect($declaration->operational_visit_category)->toBe(RmeOperationalVisitCategory::CONTROL)
        ->and(dptAudits(RmePerformedTreatmentAuditEvent::VOIDED))->toBe(2)
        ->and(RmePerformedTreatment::query()->where('clinic_visit_id', $visit->id)->count())->toBe(2);
});

it('DPT-025 evaluates a no-treatment Kontrol with a valid parent under rule 217 unchanged', function () {
    $parent = dptParentVisit();
    $visit = dptEncounter(['visit_type' => ClinicVisit::VISIT_TYPE_CONTROL, 'follow_up_of_visit_id' => $parent->id]);
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $invoice = rzvInvoice($visit, [rzvLine($this->controlService)]);
    $closed = rzvClose($invoice);

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($closed->zero_value_category)->toBe('CONTROL');
});

it('DPT-026 never invents a parent visit for a no-treatment visit without one', function () {
    $visits = ClinicVisit::query()->count();
    $visit = dptEncounter();
    $visits++;

    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect(ClinicVisit::query()->count())->toBe($visits)
        ->and($visit->fresh()->follow_up_of_visit_id)->toBeNull()
        ->and($visit->fresh()->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and(dptService()->operationalCategoryFor($visit))->toBe(RmeOperationalVisitCategory::CONTROL);
});

it('DPT-027 never makes a parentless no-treatment visit eligible for a Kontrol NO_CHARGE', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $invoice = rzvInvoice($visit, [rzvLine($this->controlService)]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('DPT-028 never sets an invoice total to zero because no treatment was declared', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $invoice = rzvInvoice($visit, [rzvLine($this->scaling, 250000, 0, 'Scaling')]);

    expect((float) $invoice->grand_total)->toBe(250000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('DPT-029/030 creates no payment and erases no receivable', function () {
    $money = rzvMoney();
    $invoices = DB::table('trx_rme_invoices')->orderBy('id')->get(['id', 'status', 'grand_total'])->map(fn ($r) => (array) $r)->all();
    $visit = dptEncounter();

    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect(rzvMoney())->toBe($money)
        ->and(DB::table('trx_rme_invoices')->orderBy('id')->get(['id', 'status', 'grand_total'])->map(fn ($r) => (array) $r)->all())->toBe($invoices);
});

it('DPT-031 never rewrites the stored visit_type', function () {
    $parent = dptParentVisit();
    $control = dptEncounter(['visit_type' => ClinicVisit::VISIT_TYPE_CONTROL, 'follow_up_of_visit_id' => $parent->id]);
    dptRecord($control, $this->scaling);
    expect($control->fresh()->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL);

    $new = dptEncounter();
    dptDeclare($new, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    expect($new->fresh()->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and($new->fresh()->follow_up_of_visit_id)->toBeNull();
});

it('DPT-032 never reclassifies a historical visit that carries no structured data', function () {
    $historical = dptParentVisit();

    expect(dptService()->stateFor($historical))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(dptService()->operationalCategoryFor($historical))->toBeNull()
        ->and(RmeTreatmentDeclaration::query()->count())->toBe(0);
});

it('DPT-033 exposes the operational category to authorized reporting through the closure snapshot', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);
    $closed = rzvClose(rzvConsultationInvoice($visit));

    $snapshot = AuditLog::query()
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', $closed->id)->value('new_values');

    expect($snapshot['clinical_billing_consistency']['doctor_evidence']['state'])->toBe(RmePerformedTreatmentState::NO_TREATMENT_DECLARED)
        ->and($snapshot['clinical_billing_consistency']['doctor_evidence']['operational_visit_category'])->toBe(RmeOperationalVisitCategory::CONTROL);
});

it('DPT-034 never lets the KONTROL classification bypass S6-Q1 (invalid parent)', function () {
    $cancelledParent = dptParentVisit(ClinicVisit::STATUS_CANCELLED);
    $visit = dptEncounter(['visit_type' => ClinicVisit::VISIT_TYPE_CONTROL, 'follow_up_of_visit_id' => $cancelledParent->id]);
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    expect(fn () => rzvInvoice($visit, [rzvLine($this->controlService)]))
        ->toThrow(ValidationException::class, RmeZeroValueEligibility::CONTROL_PARENT_INVALID);
});

it('DPT-035 never lets the KONTROL classification bypass D28', function () {
    $visit = dptEncounter();
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);
    $invoice = rzvConsultationInvoice($visit);

    ricDesignateMaker($this->maker);
    expect(dptRefusal(fn () => ricSubmit($invoice, $this->maker, [rzvLine($this->scaling, 250000, 0, 'Scaling')])))->toContain('CORRECTION_')
        ->and($invoice->fresh()->correction_version)->toBe(0);
});

it('DPT-036 with the flag OFF refuses every declaration and reports no classification', function () {
    $visit = dptEncounter();
    dptFlag(false);

    expect(dptRefusal(fn () => dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain(RmePerformedTreatmentRefusal::NOT_ENABLED)
        ->and(dptRefusal(fn () => dptRecord($visit, $this->scaling)))->toContain(RmePerformedTreatmentRefusal::NOT_ENABLED)
        ->and(dptService()->operationalCategoryFor($visit))->toBeNull()
        ->and(dptService()->workspaceFor($visit, $this->doctorUser))->toBeNull()
        ->and(dptService()->evidenceFor($visit))->toBeNull()
        ->and(RmeTreatmentDeclaration::query()->count() + RmePerformedTreatment::query()->count())->toBe(0);
});
