<?php

// Sprint 64.0.2 — Canonical handwriting RM pages per patient + canvas swipe.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'HW2', 'is_rme_enabled' => true]);
    $this->manager = userWith(['manage_clinic_visits', 'complete_rme_examination']);
    Storage::fake('public');
});

function hw2Visit(Branch $branch, Patient $patient, string $date): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'visit_date' => $date,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — writing a visit's RME
    // now requires a signed Persetujuan Tindakan Medis. These tests are not about
    // consent, so the fixture simply gives the visit one; the gate itself is under
    // test in RmeExamConsentOdontogramHistoryTest and RmeVisitConsentGateTest.
    rmeSignedConsentFor($visit);

    return $visit;
}

function hw2Sheet(Branch $branch, ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

function hw2ValidPng(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

it('redirects RM opened from visit 2 to canonical visit 1', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    hw2Sheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit2))
        ->assertRedirect(route('rme.visits.medical-record.show', [$visit1, 'source_visit_id' => $visit2->id]));
});

it('does not create a separate medical record for visit 2 when opening RM', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    hw2Sheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $visit2));

    expect(MedicalRecord::where('clinic_visit_id', $visit2->id)->exists())->toBeFalse();
});

it('creates the first RM from visit 2 on canonical visit 1', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit2))
        ->assertRedirect(route('rme.visits.medical-record.show', [$visit1, 'source_visit_id' => $visit2->id]));

    $sheet = MedicalRecord::where('clinic_visit_id', $visit1->id)->firstOrFail();

    expect($sheet->source_visit_id)->toBe($visit2->id)
        ->and(MedicalRecord::where('clinic_visit_id', $visit2->id)->exists())->toBeFalse();
});

it('adds a new handwriting page to the canonical medical record', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet1->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/page1.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit1, $sheet1]), [
            'handwriting_data' => hw2ValidPng(),
            'page_number' => 2,
            'source_visit_id' => $visit2->id,
        ])
        ->assertRedirect();

    expect(MedicalRecordHandwritingPage::where('medical_record_id', $sheet1->id)->count())->toBe(1)
        ->and(MedicalRecordHandwritingPage::where('medical_record_id', $sheet1->id)->value('page_number'))->toBe(2);
});

it('does not attach a new handwriting page to visit 2 medical record', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet2->id,
        'clinic_visit_id' => $visit2->id,
        'branch_id' => $visit2->branch_id,
        'handwriting_path' => 'handwritings/test/v2p1.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit2, $sheet2]), [
            'handwriting_data' => hw2ValidPng(),
            'page_number' => 2,
        ])
        ->assertRedirect();

    expect(MedicalRecordHandwritingPage::where('medical_record_id', $sheet2->id)->count())->toBe(0)
        ->and(MedicalRecordHandwritingPage::where('medical_record_id', $sheet1->id)->count())->toBe(1);
});

it('virtually merges legacy non-canonical handwriting pages into canonical navigation', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet1->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/v1.png',
    ]);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet2->id,
        'clinic_visit_id' => $visit2->id,
        'branch_id' => $visit2->branch_id,
        'handwriting_path' => 'handwritings/test/v2.png',
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 2]))
        ->assertOk()
        ->assertSee('Halaman 2 dari 2');
});

it('does not change visit count when creating canonical handwriting RM', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $countBefore = ClinicVisit::where('patient_id', $patient->id)->count();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit2));

    expect(ClinicVisit::where('patient_id', $patient->id)->count())->toBe($countBefore);
});

it('still supports rm_page navigation in the handwriting section', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    hw2Sheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 1]))
        ->assertOk()
        ->assertSee('Halaman 1');
});

it('renders the handwriting swipe container and hint', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $sheet = hw2Sheet($this->branch, $visit1);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/p1.png',
    ]);
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'page_number' => 2,
        'handwriting_path' => 'handwritings/test/p2.png',
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 1]))
        ->assertOk()
        ->assertSee('id="rm-handwriting-swipe"', false)
        ->assertSee('data-rm-swipe-zone', false)
        ->assertSee('touch-pan-y', false)
        ->assertSee('Geser kiri/kanan pada area halaman untuk berpindah halaman.')
        ->assertSee('data-next-url', false);
});

it('renders handwriting page navigation markers and scroll restore script', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $sheet = hw2Sheet($this->branch, $visit1);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/p1.png',
    ]);
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'page_number' => 2,
        'handwriting_path' => 'handwritings/test/p2.png',
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 1]))
        ->assertOk()
        ->assertSee('data-rm-page-nav', false)
        ->assertSee('data-rm-scroll-restore', false)
        ->assertSee('rememberRmHandwritingScroll', false)
        ->assertSee('rm_handwriting_scroll_restore', false);
});

it('exposes previous swipe url only when a previous handwriting page exists', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $sheet = hw2Sheet($this->branch, $visit1);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/p1.png',
    ]);
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'page_number' => 2,
        'handwriting_path' => 'handwritings/test/p2.png',
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 1]))
        ->assertOk()
        ->assertDontSee('data-prev-url', false);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 2]))
        ->assertOk()
        ->assertSee('data-prev-url', false)
        ->assertDontSee('data-next-url', false);
});

it('marks the drawing canvas to ignore swipe gestures', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    hw2Sheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('data-ignore-swipe', false)
        ->assertSee('id="rme-canvas"', false);
});

it('keeps handwriting navigation buttons and add-page control', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    hw2Sheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('Sebelumnya')
        ->assertSee('Berikutnya')
        ->assertSee('Tambah Halaman RM');
});

it('never renders KTP in the handwriting workspace', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'ktp_number' => '9876543210987654',
    ]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    hw2Sheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertDontSee('9876543210987654');
});

// --- Sprint 64.0.2 hotfix — canonical handwriting satisfies finalize gate ---

it('allows finalizing visit 2 when handwriting exists only on canonical visit 1', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet1->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/canonical-only.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit2, $sheet2]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($sheet2->refresh()->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('finalizing visit 2 transitions no visit at all', function () {
    // SUPERSEDED by FIX-01 — finalization no longer advances the workflow. The
    // preserved property: finalizing one sheet never touches another visit.
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet1->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/canonical-only.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit2, $sheet2]))
        ->assertRedirect();

    expect($visit2->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and($visit1->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('does not transition canonical visit 1 when finalizing visit 2', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet1->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/canonical-only.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit2, $sheet2]));

    expect($visit1->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and($sheet1->refresh()->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('rejects finalize when neither active nor canonical book has handwriting', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit2, $sheet2]))
        ->assertSessionHasErrors('handwriting');

    expect($sheet2->refresh()->status)->toBe(MedicalRecord::STATUS_DRAFT)
        ->and($visit2->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('still allows finalize when the active sheet has its own handwriting', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    hw2Sheet($this->branch, $visit1);
    $sheet2 = hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet2->id,
        'clinic_visit_id' => $visit2->id,
        'branch_id' => $visit2->branch_id,
        'handwriting_path' => 'handwritings/test/visit2-own.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit2, $sheet2]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($sheet2->refresh()->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('does not accept another patient canonical handwriting for finalize', function () {
    $patientA = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $patientB = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visitA1 = hw2Visit($this->branch, $patientA, now()->subDays(10)->toDateString());
    $visitB2 = hw2Visit($this->branch, $patientB, now()->subDays(2)->toDateString());
    $sheetA1 = hw2Sheet($this->branch, $visitA1);
    $sheetB2 = hw2Sheet($this->branch, $visitB2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheetA1->id,
        'clinic_visit_id' => $visitA1->id,
        'branch_id' => $visitA1->branch_id,
        'handwriting_path' => 'handwritings/test/other-patient.png',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visitB2, $sheetB2]))
        ->assertSessionHasErrors('handwriting');
});

it('shows finalize action when canonical book satisfies handwriting on visit 2 sheet', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'ktp_number' => '1122334455667788',
    ]);
    $visit1 = hw2Visit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = hw2Visit($this->branch, $patient, now()->subDays(2)->toDateString());
    $sheet1 = hw2Sheet($this->branch, $visit1);
    hw2Sheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet1->id,
        'clinic_visit_id' => $visit1->id,
        'branch_id' => $visit1->branch_id,
        'handwriting_path' => 'handwritings/test/canonical-ui.png',
    ]);

    $this->actingAs($this->manager)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $visit2))
        ->assertOk()
        ->assertSee('Finalisasi', false)
        ->assertDontSee('1122334455667788');
});
