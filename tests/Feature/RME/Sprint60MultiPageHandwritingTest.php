<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Storage::fake('public');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create([
        'ktp_number' => '3201234567890123',
    ]);
    $this->doctor = Doctor::factory()->create();

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);
});

// Minimal valid PNG base64 (10x10, has drawn content — treated as real handwriting)
function s60ValidPng(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

// Fully transparent 20x20 RGBA PNG — the output of a blank/cleared canvas
function s60BlankPng(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAYAAACNiR0NAAAAFUlEQVR4nGNgGAWjYBSMglEwCqgDAAZUAAGDHP/NAAAAAElFTkSuQmCC';
}

function s60SaveLegacyPageOne(MedicalRecord $record): MedicalRecordHandwriting
{
    Storage::disk('public')->put('handwritings/legacy/page1.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        true
    ));

    return MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $record->clinic_visit_id,
        'branch_id' => $record->branch_id,
        'handwriting_path' => 'handwritings/legacy/page1.png',
    ]);
}

it('renders an existing single-PNG record as Page 1 (backward compatible)', function () {
    s60SaveLegacyPageOne($this->record);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('Halaman 1')
        ->assertSee('Tersimpan pada')
        ->assertDontSee('Halaman 2');

    // The unified ordered page list exposes the legacy row as Page 1.
    $pages = $this->record->fresh()->orderedHandwritingPages();
    expect($pages)->toHaveCount(1)
        ->and($pages->first()['page_number'])->toBe(1)
        ->and($pages->first()['is_legacy'])->toBeTrue()
        ->and($pages->first()['has_content'])->toBeTrue();
});

it('an empty record still renders Page 1 with the empty handwriting message', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('Halaman 1')
        ->assertSee('Belum ada handwriting RM. Silakan isi dan simpan tulisan tangan sebelum finalisasi.');
});

it('adds Page 2 inside the same Medical Record without a new visit or record', function () {
    s60SaveLegacyPageOne($this->record);

    $visitsBefore = ClinicVisit::count();
    $recordsBefore = MedicalRecord::count();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60ValidPng(),
            'page_number' => 2,
        ])
        ->assertRedirect(route('rme.visits.medical-record.show', $this->visit))
        ->assertSessionHasNoErrors();

    expect(ClinicVisit::count())->toBe($visitsBefore)
        ->and(MedicalRecord::count())->toBe($recordsBefore);

    $page2 = MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)
        ->where('page_number', 2)
        ->first();

    expect($page2)->not->toBeNull()
        ->and($page2->clinic_visit_id)->toBe($this->visit->id)
        ->and($page2->branch_id)->toBe($this->branch->id);
});

it('saving Page 2 does not overwrite Page 1', function () {
    $legacy = s60SaveLegacyPageOne($this->record);
    $legacyPath = $legacy->handwriting_path;
    $legacyHash = $legacy->handwriting_hash;

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60ValidPng(),
            'page_number' => 2,
        ])
        ->assertSessionHasNoErrors();

    // Legacy Page 1 row is untouched.
    $freshLegacy = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();
    expect($freshLegacy->handwriting_path)->toBe($legacyPath)
        ->and($freshLegacy->handwriting_hash)->toBe($legacyHash);

    // Page 2 is a distinct row / path.
    $page2 = MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 2)->firstOrFail();
    expect($page2->handwriting_path)->not->toBe($legacyPath);
});

it('saving Page 3 does not overwrite Page 1 or Page 2', function () {
    $legacy = s60SaveLegacyPageOne($this->record);
    $legacyPath = $legacy->handwriting_path;

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60ValidPng(),
            'page_number' => 2,
        ])->assertSessionHasNoErrors();

    $page2Path = MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 2)->firstOrFail()->handwriting_path;

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60ValidPng(),
            'page_number' => 3,
        ])->assertSessionHasNoErrors();

    expect(MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail()->handwriting_path)->toBe($legacyPath)
        ->and(MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 2)->firstOrFail()->handwriting_path)->toBe($page2Path);

    $page3 = MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 3)->first();
    expect($page3)->not->toBeNull()
        ->and($page3->handwriting_path)->not->toBe($page2Path);
});

it('a new page number cannot skip ahead (clamped to the next addable page)', function () {
    s60SaveLegacyPageOne($this->record);

    // Request page 9 while only Page 1 exists — must be clamped to Page 2.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60ValidPng(),
            'page_number' => 9,
        ])->assertSessionHasNoErrors();

    expect(MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 9)->exists())->toBeFalse()
        ->and(MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 2)->exists())->toBeTrue();
});

it('blank submit for a page does not erase that page existing handwriting', function () {
    s60SaveLegacyPageOne($this->record);

    // Seed a real Page 2.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60ValidPng(),
            'page_number' => 2,
        ])->assertSessionHasNoErrors();

    $page2Path = MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 2)->firstOrFail()->handwriting_path;

    // A blank submit to Page 2 must be rejected and must not change the row.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => s60BlankPng(),
            'page_number' => 2,
        ])->assertSessionHasErrors(['handwriting_data']);

    expect(MedicalRecordHandwritingPage::where('medical_record_id', $this->record->id)->where('page_number', 2)->firstOrFail()->handwriting_path)->toBe($page2Path);
});

it('the show page exposes per-page editor data attributes for the selected page', function () {
    s60SaveLegacyPageOne($this->record);

    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'page_number' => 2,
        'handwriting_path' => 'handwritings/legacy/page1.png',
    ]);

    $response = $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('data-page-number="1"', false)
        ->assertSee('data-page-number="2"', false)
        // The overlay editor + page_number routing exist.
        ->assertSee('id="rme-canvas"', false)
        ->assertSee('name="page_number"', false)
        ->assertSee('Tambah Halaman RM')
        ->assertSee('Reset ke Tulisan Tersimpan');

    // Canvas uses the A4-portrait page size (1 canvas = 1 RM page).
    $response->assertSee('width="900" height="1273"', false);
});

it('viewer sees read-only page previews without the edit canvas', function () {
    s60SaveLegacyPageOne($this->record);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('Halaman 1')
        ->assertDontSee('id="rme-canvas"', false)
        ->assertDontSee('Simpan Tulisan Tangan')
        ->assertDontSee('Tambah Halaman RM');
});

it('does not render the patient KTP number anywhere on the medical record page', function () {
    s60SaveLegacyPageOne($this->record);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertDontSee('3201234567890123');
});

it('enforces UNIQUE(medical_record_id, page_number) on the pages table', function () {
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'page_number' => 2,
    ]);

    expect(fn () => MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'page_number' => 2,
    ]))->toThrow(QueryException::class);
});

it('keeps the Informasi Kunjungan biodata table and visit navigation', function () {
    s60SaveLegacyPageOne($this->record);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('Informasi Kunjungan')
        ->assertSee('Navigasi kunjungan pasien', false);
});

it('finalize still requires at least one non-blank saved page', function () {
    $service = app(MedicalRecordService::class);

    // No handwriting yet → cannot finalize.
    expect($service->canFinalizeRme($this->record))->toBeFalse();

    s60SaveLegacyPageOne($this->record);

    expect($service->canFinalizeRme($this->record->fresh()))->toBeTrue();
});

it('print body renders all RM pages, not just Page 1', function () {
    s60SaveLegacyPageOne($this->record);

    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'page_number' => 2,
        'handwriting_path' => 'handwritings/legacy/page1.png',
    ]);

    $rendered = view('rme.visits.partials.print-body', [
        'visit' => $this->visit->fresh(['patient', 'doctor', 'branch', 'clinic', 'medicalRecord']),
        'paidInvoice' => null,
        'payment' => null,
        'labCaseCandidates' => collect(),
    ])->render();

    expect($rendered)->toContain('Halaman 1')
        ->and($rendered)->toContain('Halaman 2');
});
