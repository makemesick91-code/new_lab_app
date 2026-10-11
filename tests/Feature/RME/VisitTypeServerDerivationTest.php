<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — Revision C
|--------------------------------------------------------------------------
|
| "Jenis Kunjungan" is no longer an operator choice on /rme/visits/create.
| Owner decision (2026-10-12), enforced server-side in StoreClinicVisitRequest:
|
|   - a prior visit EXPLICITLY selected (follow_up_of_visit_id) that passes the
|     existing control-chain validation  => visit_type = control (Kontrol);
|   - no prior visit selected                                => visit_type = new (Baru);
|   - any client-submitted visit_type is ignored;
|   - an explicitly selected but invalid / cross-patient / out-of-scope prior
|     visit is REJECTED — never silently downgraded to Baru;
|   - patient history alone never makes a visit Kontrol.
|
| The control-chain billing path (RmeControlReceivableService carry-over)
| keys on `control` + a parent visit, so these tests drive the REAL store
| endpoint and then ask that service what it sees.
*/

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->rmeBranch = Branch::factory()->create(['code' => 'VTD1', 'is_rme_enabled' => true]);
    $this->otherRmeBranch = Branch::factory()->create(['code' => 'VTD2', 'is_rme_enabled' => true]);
    $this->nonRmeBranch = Branch::factory()->create(['code' => 'VTDX', 'is_rme_enabled' => false]);
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->rmeBranch);

    $this->manager = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
    $this->patient = Patient::factory()->create([
        'medical_record_number' => 'DG-VTD1-2026-0001',
        'branch_id' => $this->rmeBranch->id,
    ]);
});

function vtdPriorVisit(Patient $patient, Branch $branch, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'clinic_id' => Clinic::factory()->create()->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'initial_treatment_id' => test()->treatment->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'queue_number' => 1,
        'visit_number' => 'VIS-VTD-'.Str::upper(Str::random(8)),
        'created_by' => test()->manager->id,
    ], $overrides));
}

/** @param array<string, mixed> $extra */
function vtdStore(array $extra = [], ?Patient $patient = null)
{
    return test()->actingAs(test()->manager)->post(route('rme.visits.store'), array_merge([
        'branch_id' => test()->rmeBranch->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'doctor_id' => test()->doctor->id,
        'initial_treatment_id' => test()->treatment->id,
    ], $extra));
}

function vtdLatestVisitFor(Patient $patient): ?ClinicVisit
{
    return ClinicVisit::query()->where('patient_id', $patient->id)->latest('id')->first();
}

/*
|--------------------------------------------------------------------------
| The create page
|--------------------------------------------------------------------------
*/

it('no longer renders the Jenis Kunjungan field or any visit_type input', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee('Jenis Kunjungan')
        ->assertDontSee('name="visit_type"', false)
        ->assertDontSee('data-visit-type', false)
        ->assertDontSee('Lanjutan Tindakan')
        ->assertDontSee('>Emergency<', false);
});

it('keeps the optional prior-visit (Kontrol) picker for registered patients', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Kunjungan Sebelumnya (Kontrol)')
        ->assertSee('name="follow_up_of_visit_id"', false)
        ->assertSee('Bukan kunjungan kontrol (Kunjungan Baru)');
});

it('still preselects the prior visit when launched from "Buat Kontrol"', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.create', [
            'patient_id' => $this->patient->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $prior->id,
            'branch_id' => $this->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('const prefillFollowUpId = '.$prior->id, false)
        ->assertDontSee('name="visit_type"', false);
});

/*
|--------------------------------------------------------------------------
| Derivation
|--------------------------------------------------------------------------
*/

it('registers Baru when no prior visit is selected', function () {
    vtdStore()->assertRedirect()->assertSessionHasNoErrors();

    $visit = vtdLatestVisitFor($this->patient);
    expect($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and($visit->follow_up_of_visit_id)->toBeNull();
});

it('never infers Kontrol from patient history alone', function () {
    // The patient HAS prior visits, but none was selected.
    vtdPriorVisit($this->patient, $this->rmeBranch);
    vtdPriorVisit($this->patient, $this->rmeBranch, ['queue_number' => 2]);

    vtdStore()->assertRedirect()->assertSessionHasNoErrors();

    expect(vtdLatestVisitFor($this->patient)->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW);
});

it('registers Kontrol when a valid prior visit of the same patient is selected', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);

    vtdStore(['follow_up_of_visit_id' => $prior->id])->assertRedirect()->assertSessionHasNoErrors();

    $visit = vtdLatestVisitFor($this->patient);
    expect($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL)
        ->and($visit->follow_up_of_visit_id)->toBe($prior->id)
        ->and($visit->isControlVisit())->toBeTrue();
});

it('allows a prior visit from another RME branch of the same patient (cross-branch patient)', function () {
    $prior = vtdPriorVisit($this->patient, $this->otherRmeBranch);

    vtdStore(['follow_up_of_visit_id' => $prior->id])->assertRedirect()->assertSessionHasNoErrors();

    $visit = vtdLatestVisitFor($this->patient);
    expect($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL)
        ->and($visit->branch_id)->toBe($this->rmeBranch->id);
});

/*
|--------------------------------------------------------------------------
| Rejection — never a silent downgrade
|--------------------------------------------------------------------------
*/

it('rejects a prior visit id that does not exist', function () {
    $before = ClinicVisit::count();

    vtdStore(['follow_up_of_visit_id' => 999999])->assertSessionHasErrors('follow_up_of_visit_id');

    expect(ClinicVisit::count())->toBe($before);
});

it('rejects a non-numeric prior visit id rather than registering Baru', function () {
    $before = ClinicVisit::count();

    vtdStore(['follow_up_of_visit_id' => 'abc'])->assertSessionHasErrors('follow_up_of_visit_id');

    expect(ClinicVisit::count())->toBe($before);
});

it('rejects a prior visit belonging to another patient and creates nothing', function () {
    $stranger = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $strangersVisit = vtdPriorVisit($stranger, $this->rmeBranch);
    $before = ClinicVisit::count();

    vtdStore(['follow_up_of_visit_id' => $strangersVisit->id])->assertSessionHasErrors('follow_up_of_visit_id');

    expect(ClinicVisit::count())->toBe($before)
        ->and(vtdLatestVisitFor($this->patient))->toBeNull();
});

it('rejects a prior visit outside the RME branches (unauthorized prior visit)', function () {
    $prior = vtdPriorVisit($this->patient, $this->nonRmeBranch);
    $before = ClinicVisit::count();

    vtdStore(['follow_up_of_visit_id' => $prior->id])->assertSessionHasErrors('follow_up_of_visit_id');

    expect(ClinicVisit::count())->toBe($before);
});

it('rejects a soft-deleted prior visit', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);
    $prior->delete();
    $before = ClinicVisit::count();

    vtdStore(['follow_up_of_visit_id' => $prior->id])->assertSessionHasErrors('follow_up_of_visit_id');

    expect(ClinicVisit::count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Client visit_type tampering
|--------------------------------------------------------------------------
*/

it('ignores a client visit_type=control without a prior visit (stays Baru)', function () {
    vtdStore(['visit_type' => ClinicVisit::VISIT_TYPE_CONTROL])->assertRedirect()->assertSessionHasNoErrors();

    expect(vtdLatestVisitFor($this->patient)->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW);
});

it('ignores client visit types the form no longer offers', function (string $tampered) {
    vtdStore(['visit_type' => $tampered])->assertRedirect()->assertSessionHasNoErrors();

    expect(vtdLatestVisitFor($this->patient)->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW);
})->with([
    'emergency' => [ClinicVisit::VISIT_TYPE_EMERGENCY],
    'continued treatment' => [ClinicVisit::VISIT_TYPE_CONTINUED_TREATMENT],
    'garbage' => ['vip-express'],
]);

it('derives Kontrol even if the client claims visit_type=new with a selected prior visit', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);

    vtdStore([
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'follow_up_of_visit_id' => $prior->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(vtdLatestVisitFor($this->patient)->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL);
});

/*
|--------------------------------------------------------------------------
| Registration paths
|--------------------------------------------------------------------------
*/

it('registers a new patient as Baru without any visit type input', function () {
    $this->actingAs($this->manager)->post(route('rme.visits.store'), [
        'patient_mode' => 'new',
        'branch_id' => $this->rmeBranch->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => [
            'name' => 'Pasien Baru Derivasi',
            'branch_id' => $this->rmeBranch->id,
            'registered_at' => now()->toDateString(),
            'manual_rm_number' => '0700',
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $patient = Patient::firstWhere('name', 'Pasien Baru Derivasi');
    expect($patient)->not->toBeNull()
        ->and($patient->ktp_number)->toBeNull()
        ->and(vtdLatestVisitFor($patient)->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW);
});

it('refuses a prior visit submitted with a new-patient registration instead of downgrading it', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);
    $patientsBefore = Patient::count();

    $this->actingAs($this->manager)->post(route('rme.visits.store'), [
        'patient_mode' => 'new',
        'branch_id' => $this->rmeBranch->id,
        'follow_up_of_visit_id' => $prior->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => [
            'name' => 'Pasien Baru Dengan Kontrol',
            'branch_id' => $this->rmeBranch->id,
            'manual_rm_number' => '0701',
        ],
    ])->assertSessionHasErrors('visit_type');

    expect(Patient::count())->toBe($patientsBefore);
});

it('registers an existing legacy-imported patient as Baru with no KTP required', function () {
    $batch = LegacyPatientImportBatch::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'original_filename' => 'synthetic-legacy.csv',
        'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
    ]);
    $legacy = Patient::factory()->create([
        'medical_record_number' => 'DG-VTD1-2024-0042',
        'branch_id' => $this->rmeBranch->id,
        'import_batch_id' => $batch->id,
        'ktp_number' => null,
    ]);

    vtdStore([], $legacy)->assertRedirect()->assertSessionHasNoErrors();

    $visit = vtdLatestVisitFor($legacy);
    expect($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and($legacy->fresh()->ktp_number)->toBeNull();
});

it('keeps queue numbering and the registered status for derived visits', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);

    vtdStore()->assertRedirect();
    vtdStore(['follow_up_of_visit_id' => $prior->id])->assertRedirect();

    $visits = ClinicVisit::query()->where('patient_id', $this->patient->id)
        ->where('id', '!=', $prior->id)->orderBy('id')->get();

    expect($visits)->toHaveCount(2)
        ->and($visits->pluck('visit_type')->all())->toBe([ClinicVisit::VISIT_TYPE_NEW, ClinicVisit::VISIT_TYPE_CONTROL])
        ->and($visits->every(fn (ClinicVisit $v) => $v->queue_number > 0))->toBeTrue()
        ->and($visits->pluck('queue_number')->unique()->count())->toBe(2)
        ->and($visits->every(fn (ClinicVisit $v) => $v->status === ClinicVisit::STATUS_REGISTERED))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Control-chain billing / piutang carry-over is preserved
|--------------------------------------------------------------------------
*/

it('enters the control-chain carry-over when the prior visit is explicitly selected', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);
    $outstanding = RmeInvoice::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_visit_id' => $prior->id,
        'patient_id' => $this->patient->id,
        'status' => RmeInvoice::STATUS_UNPAID,
        'subtotal' => 400000,
        'discount_total' => 0,
        'grand_total' => 400000,
    ]);

    vtdStore(['follow_up_of_visit_id' => $prior->id])->assertRedirect()->assertSessionHasNoErrors();

    $control = vtdLatestVisitFor($this->patient);
    $receivables = app(RmeControlReceivableService::class);

    expect($control->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTROL)
        ->and($receivables->getCarryOverInvoicesForControlVisit($control)->pluck('id')->all())->toBe([$outstanding->id])
        ->and($receivables->getCarryOverBalanceForControlVisit($control))->toBe(400000.0);
});

it('does not attach piutang carry-over to a visit registered without a prior visit', function () {
    $prior = vtdPriorVisit($this->patient, $this->rmeBranch);
    RmeInvoice::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_visit_id' => $prior->id,
        'patient_id' => $this->patient->id,
        'status' => RmeInvoice::STATUS_UNPAID,
        'subtotal' => 400000,
        'discount_total' => 0,
        'grand_total' => 400000,
    ]);

    vtdStore()->assertRedirect()->assertSessionHasNoErrors();

    $visit = vtdLatestVisitFor($this->patient);

    expect($visit->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and(app(RmeControlReceivableService::class)->getCarryOverInvoicesForControlVisit($visit))->toHaveCount(0);
});

it('leaves historical visit types untouched', function () {
    $emergency = vtdPriorVisit($this->patient, $this->rmeBranch, ['visit_type' => ClinicVisit::VISIT_TYPE_EMERGENCY]);
    $continued = vtdPriorVisit($this->patient, $this->rmeBranch, [
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTINUED_TREATMENT,
        'follow_up_of_visit_id' => $emergency->id,
        'queue_number' => 2,
    ]);

    vtdStore()->assertRedirect();

    expect($emergency->fresh()->visit_type)->toBe(ClinicVisit::VISIT_TYPE_EMERGENCY)
        ->and($continued->fresh()->visit_type)->toBe(ClinicVisit::VISIT_TYPE_CONTINUED_TREATMENT)
        ->and($continued->fresh()->visitTypeLabel())->toBe('Lanjutan Tindakan')
        ->and(ClinicVisit::VISIT_TYPES)->toContain(ClinicVisit::VISIT_TYPE_EMERGENCY, ClinicVisit::VISIT_TYPE_CONTINUED_TREATMENT);
});
