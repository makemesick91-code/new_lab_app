<?php

declare(strict_types=1);

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — what must NOT change
| (DPT-077…090) and the doctor workspace rendering.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\PatientMerge\Repositories\PatientMergeOwnershipRepository;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => dptSetup());

function dptMedicalRecordFor(ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id, 'doctor_id' => $visit->doctor_id,
    ]);
}

it('DPT-077 renders the doctor workspace exactly as before while the flag is OFF', function () {
    $visit = dptEncounter();
    dptMedicalRecordFor($visit);
    dptFlag(false);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee('data-dpt-section', false)
        ->assertDontSee('Tindakan Dilakukan');
});

it('renders the "Tindakan Dilakukan" section with authoring controls for the treating doctor when ON', function () {
    $visit = dptEncounter();
    dptMedicalRecordFor($visit);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-dpt-section', false)
        ->assertSee('Tindakan Dilakukan')
        ->assertSee('Ada Tindakan')
        ->assertSee('Tidak Ada Tindakan')
        ->assertSee('data-dpt-state="NOT_RECORDED"', false)
        ->assertSee(route('rme.visits.performed-treatments.declaration', $visit), false)
        ->assertDontSee($this->voucher->name)
        ->assertSee($this->scaling->name);
});

it('renders the section read-only (no forms) once the examination is complete', function () {
    $visit = dptEncounter();
    dptMedicalRecordFor($visit);
    dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
    dptCompleteExamination($visit);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-dpt-state="NO_TREATMENT_DECLARED"', false)
        ->assertSee('KONTROL')
        ->assertDontSee(route('rme.visits.performed-treatments.declaration', $visit), false)
        ->assertDontSee(route('rme.visits.performed-treatments.store', $visit), false);
});

it('DPT-078 lets the examination complete without any declaration (no new mandatory gate)', function () {
    $visit = dptEncounter();

    expect(dptCompleteExamination($visit)->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);

    dptFlag(false);
    $other = dptEncounter();
    expect(dptCompleteExamination($other)->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('DPT-079/080/081 leaves invoices, medical records and odontograms untouched', function () {
    $visit = dptEncounter();
    $record = dptMedicalRecordFor($visit);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
    $before = [$record->fresh()->updated_at?->toJSON(), $chart->fresh()->updated_at?->toJSON(), DB::table('trx_rme_invoices')->count()];

    $this->travel(5)->minutes();
    dptRecord($visit, $this->scaling);
    dptDeclare(dptEncounter(), RmeTreatmentDeclaration::TYPE_NO_TREATMENT);

    expect([$record->fresh()->updated_at?->toJSON(), $chart->fresh()->updated_at?->toJSON(), DB::table('trx_rme_invoices')->count()])->toBe($before);
});

it('DPT-082 ships both clinical and Rp0 capabilities OFF', function () {
    $flags = require base_path('config/feature_flags.php');

    expect($flags['flags'][RmePerformedTreatmentFeatureGuard::FLAG]['default'])->toBeFalse()
        ->and($flags['flags']['rme.zero_value_closure']['default'])->toBeFalse()
        ->and($flags['flags']['rme.invoice_postpayment_correction']['default'])->toBeFalse()
        ->and($flags['flags']['rme.billing_refund_request']['default'])->toBeFalse()
        ->and($flags['flags']['rme.billing_refund_settlement']['default'])->toBeFalse();
});

it('DPT-083/084/085 writes no dispute, correction or refund row', function () {
    $tables = ['trx_rme_billing_disputes', 'trx_rme_invoice_corrections', 'trx_rme_refund_requests', 'trx_rme_refund_settlements'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $visit = dptEncounter();

    dptRecord($visit, $this->scaling);

    expect(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all())->toBe($before);
});

it('DPT-086 keeps the clinical print composition unchanged', function () {
    $partial = file_get_contents(resource_path('views/rme/visits/partials/print-body.blade.php'));

    expect($partial)->not->toContain('performed-treatments')->and($partial)->not->toContain('Tindakan Dilakukan');
});

it('DPT-087 leaves SATUSEHAT untouched', function () {
    $candidates = DB::table('trx_satusehat_candidates')->count();
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);

    $sources = collect(glob(app_path('Modules/PerformedTreatment/*/*.php')))->map(fn ($f) => file_get_contents($f))->implode("\n");

    expect(DB::table('trx_satusehat_candidates')->count())->toBe($candidates)
        ->and($sources)->not->toContain('Satusehat');
});

it('DPT-088 leaves the legacy archive services out of the clinical write path', function () {
    $sources = collect(glob(app_path('Modules/PerformedTreatment/*/*.php')))->map(fn ($f) => file_get_contents($f))->implode("\n");

    expect($sources)->not->toContain('LegacyRme')->and($sources)->not->toContain('LegacyOdontogram');
});

it('DPT-090 keeps patient merge safe: no patient column, and merge watches both tables for activity', function () {
    expect(Schema::hasColumn('trx_rme_performed_treatments', 'patient_id'))->toBeFalse()
        ->and(PatientMergeOwnershipRepository::CHILD_TABLES)->toHaveKey('trx_rme_performed_treatments')
        ->and(PatientMergeOwnershipRepository::CHILD_TABLES['trx_rme_performed_treatments'])->toBe(['clinic_visit_id', 'trx_clinic_visits'])
        ->and(PatientMergeOwnershipRepository::CHILD_TABLES)->toHaveKey('trx_rme_treatment_declarations')
        ->and(PatientMergeOwnershipRepository::CHILD_TABLES['trx_rme_treatment_declarations'])->toBe(['clinic_visit_id', 'trx_clinic_visits']);
});
