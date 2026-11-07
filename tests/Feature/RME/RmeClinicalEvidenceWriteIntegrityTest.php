<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-6 — clinical evidence write and read integrity
|--------------------------------------------------------------------------
|
| A saved handwriting page or prescription canvas is two things that must
| agree: a database row (path + sha256) and an object on the private clinical
| disk. Before this programme they could disagree:
|
|   P6-HW-01  an invalid or blank canvas created a canonical medical-record
|             draft before it was validated (R5-3, first half);
|   P6-HW-02  the object was written before the row with no compensation, so a
|             failed row write left an orphaned object (R5-3, second half);
|   P6-HW-03  the object key was second-granular and built from the RAW page
|             number, so a rejected or concurrent save overwrote an object a
|             committed row already pointed at, and two rows could share one
|             object — the stored hash then described bytes that were gone;
|   P6-RX-01  a prescription update wrote the R/ canvas before decoding the
|             signature, and no failure ever removed a written canvas;
|   P6-RX-03  the canvas route bound `{rmePrescription}` to an argument named
|             `$prescription`, so implicit binding never ran and the stored
|             canvases never loaded on the prescription page;
|   P6-PDF-01 a recorded page whose object is missing printed `<img src="">`;
|   P6-PDF-02 an unreadable object threw out of the print (HTTP 500) and a
|             non-PNG object was embedded as if it were a PNG.
|
| Every defect case here was RED on the untouched base (c78f15dc). Three cases
| are regression properties that also held on the base and are kept to pin
| them: the multi-page row/object agreement, a readable PNG still embedded
| inline, and the prescription "Belum ada gambar resep." placeholder. The two
| cases added after the independent review (prescription row failure in the
| same second, and a failure after the transaction body) pin the random key
| suffix and the commit-outcome rule on their own. Properties are asserted on
| the stored bytes themselves, never on a flash message.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Storage::fake('clinical_evidence');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    // DMS-RME-REVISION-PROGRAM-8B / D7 — a clinical role, as a prescription author needs.
    $this->manager = rmeClinicalAuthor(['manage_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create();
    $this->doctor = Doctor::factory()->create();

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    // Writing the RME needs a real authorized encounter (rule 109 §2b).
    rmeActiveConsentedEncounter($this->visit);

    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A small, opaque, non-uniform RGBA PNG built in pure PHP (no GD), so two seeds
 * give two different valid canvases that the blank guard accepts.
 */
function p6PngBytes(int $seed): string
{
    $width = 4;
    $height = 4;
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $width; $x++) {
            $raw .= chr(($seed * 37 + $x * 50) % 256)
                .chr(($seed * 11 + $y * 60) % 256)
                .chr(($seed * 5 + ($x + $y) * 30) % 256)
                .chr(255);
        }
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress($raw))
        .$chunk('IEND', '');
}

function p6PngDataUri(int $seed): string
{
    return 'data:image/png;base64,'.base64_encode(p6PngBytes($seed));
}

/** A fully transparent canvas — what an untouched editor submits. */
function p6BlankCanvas(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAYAAACNiR0NAAAAFUlEQVR4nGNgGAWjYBSMglEwCqgDAAZUAAGDHP/NAAAAAElFTkSuQmCC';
}

function p6SaveHandwriting(object $test, ClinicVisit $visit, MedicalRecord $record, string $canvas, int $page)
{
    return $test->actingAs($test->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $record]), [
            'handwriting_data' => $canvas,
            'page_number' => $page,
        ]);
}

/** @return list<string> every object currently on the private clinical disk */
function p6StoredObjects(): array
{
    $files = Storage::disk('clinical_evidence')->allFiles();
    sort($files);

    return $files;
}

function p6StoredRowFor(MedicalRecord $record, int $page): MedicalRecordHandwriting|MedicalRecordHandwritingPage|null
{
    return $page <= 1
        ? MedicalRecordHandwriting::query()->where('medical_record_id', $record->id)->latest('id')->first()
        : MedicalRecordHandwritingPage::query()->where('medical_record_id', $record->id)->where('page_number', $page)->first();
}

function p6AssertRowMatchesItsObject(MedicalRecordHandwriting|MedicalRecordHandwritingPage $row): void
{
    $disk = Storage::disk('clinical_evidence');

    expect($disk->exists($row->handwriting_path))->toBeTrue()
        ->and(hash('sha256', (string) $disk->get($row->handwriting_path)))->toBe($row->handwriting_hash);
}

// --------------------------------------------------------------------------
// P6-HW-01 — validation happens before anything is written
// --------------------------------------------------------------------------

it('never creates a medical-record draft for a canvas that fails validation', function (string $canvas) {
    $patient = Patient::factory()->create();
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 2,
    ]);
    rmeActiveConsentedEncounter($visit);

    $before = MedicalRecord::query()->count();

    // The patient has no record yet, so this save would have to create the
    // canonical book. A canvas that is then refused must not leave one behind.
    p6SaveHandwriting($this, $visit, $this->record, $canvas, 1)
        ->assertSessionHasErrors('handwriting_data');

    expect(MedicalRecord::query()->count())->toBe($before)
        ->and(MedicalRecord::query()->where('patient_id', $patient->id)->exists())->toBeFalse()
        ->and(p6StoredObjects())->toBe([]);
})->with([
    'not a PNG' => ['data:image/png;base64,bm90LWEtcG5n'],
    'blank canvas' => [p6BlankCanvas()],
]);

// --------------------------------------------------------------------------
// P6-HW-02 — a failed row write leaves no object behind
// --------------------------------------------------------------------------

it('leaves no orphaned object when the page row cannot be written', function (int $page, string $model) {
    $model::creating(function (): void {
        throw new RuntimeException('p6: injected row-write failure');
    });

    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(1), $page)
        ->assertStatus(500);

    expect(p6StoredObjects())->toBe([])
        ->and(p6StoredRowFor($this->record, $page))->toBeNull();
})->with([
    'page 1 (legacy row)' => [1, MedicalRecordHandwriting::class],
    'page 2 (pages table)' => [2, MedicalRecordHandwritingPage::class],
]);

// --------------------------------------------------------------------------
// P6-HW-03 — a committed page's bytes are never replaced by a save that did
// not commit, and two rows never share one object
// --------------------------------------------------------------------------

it('never lets a rejected replacement overwrite the committed page in the same second', function (int $page, string $model) {
    Carbon::setTestNow(Carbon::parse('2026-11-06 03:00:00', 'UTC'));

    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(1), $page)->assertRedirect();
    $committed = p6StoredRowFor($this->record, $page);
    p6AssertRowMatchesItsObject($committed);

    $model::updating(function (): void {
        throw new RuntimeException('p6: injected row-update failure');
    });

    // Same clock second: the base built the identical object key.
    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(2), $page)->assertStatus(500);

    $after = p6StoredRowFor($this->record, $page);
    expect($after->handwriting_path)->toBe($committed->handwriting_path)
        ->and($after->handwriting_hash)->toBe($committed->handwriting_hash);
    p6AssertRowMatchesItsObject($after);
    expect(p6StoredObjects())->toBe([$committed->handwriting_path]);
})->with([
    'page 1 (legacy row)' => [1, MedicalRecordHandwriting::class],
    'page 2 (pages table)' => [2, MedicalRecordHandwritingPage::class],
]);

it('writes a distinct object for each save, keeping the earlier evidence intact', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-06 03:00:00', 'UTC'));

    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(1), 2)->assertRedirect();
    $first = p6StoredRowFor($this->record, 2);

    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(2), 2)->assertRedirect();
    $second = p6StoredRowFor($this->record, 2);

    expect($second->handwriting_path)->not->toBe($first->handwriting_path)
        ->and(hash('sha256', (string) Storage::disk('clinical_evidence')->get($first->handwriting_path)))
        ->toBe($first->handwriting_hash);
    p6AssertRowMatchesItsObject($second);
});

it('never points two pages at one object when a page number above the next free page is posted twice', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-06 03:00:00', 'UTC'));

    // The server clamps a too-high page to the next free one (2, then 3); the
    // base still named the object after the RAW number, so both shared it.
    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(1), 9)->assertRedirect();
    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(2), 9)->assertRedirect();

    $pageTwo = p6StoredRowFor($this->record, 2);
    $pageThree = p6StoredRowFor($this->record, 3);

    expect($pageTwo)->not->toBeNull()
        ->and($pageThree)->not->toBeNull()
        ->and($pageTwo->handwriting_path)->not->toBe($pageThree->handwriting_path);
    p6AssertRowMatchesItsObject($pageTwo);
    p6AssertRowMatchesItsObject($pageThree);
});

it('keeps every page row in agreement with its object across a multi-page book', function () {
    foreach ([1, 2, 3] as $page) {
        p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri($page), $page)->assertRedirect();
    }

    foreach ([1, 2, 3] as $page) {
        p6AssertRowMatchesItsObject(p6StoredRowFor($this->record, $page));
    }

    expect(MedicalRecordHandwriting::query()->where('medical_record_id', $this->record->id)->count())->toBe(1)
        ->and(MedicalRecordHandwritingPage::query()->where('medical_record_id', $this->record->id)->pluck('page_number')->all())
        ->toBe([2, 3]);
});

// --------------------------------------------------------------------------
// P6-RX-01 — a prescription write that does not commit leaves no canvas
// --------------------------------------------------------------------------

function p6PrescriptionVisit(object $test): ClinicVisit
{
    Branch::query()->whereKey($test->branch->id)->update(['is_rme_enabled' => true]);
    $doctor = Doctor::factory()->create(['name' => 'drg. Integritas']);
    rmeMakeDoctorOnline($doctor, Branch::query()->findOrFail($test->branch->id));

    return ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $test->branch->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $test->branch->id])->id,
        'doctor_id' => $doctor->id,
    ]);
}

function p6PrescriptionForm(ClinicVisit $visit, array $canvases): array
{
    return array_merge([
        'prescribed_by_name' => 'drg. Integritas',
        'prescription_date' => '2026-11-06',
        'patient_name_snapshot' => 'Pasien Uji',
        'patient_age_snapshot' => '32 tahun',
    ], $canvases);
}

it('leaves no orphaned canvas when a prescription update is refused after the R/ canvas decoded', function () {
    $visit = p6PrescriptionVisit($this);
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    $objectsBefore = p6StoredObjects();
    $pathsBefore = [$prescription->prescription_canvas_path, $prescription->doctor_signature_canvas_path];

    $this->actingAs($this->manager)
        ->patch(route('rme.prescriptions.update', $prescription), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(3),
            'doctor_signature_canvas_data' => p6BlankCanvas(),
        ]))
        ->assertSessionHasErrors('doctor_signature_canvas_data');

    $prescription->refresh();
    expect(p6StoredObjects())->toBe($objectsBefore)
        ->and([$prescription->prescription_canvas_path, $prescription->doctor_signature_canvas_path])->toBe($pathsBefore);
});

it('leaves no orphaned canvases when the prescription row cannot be written', function () {
    $visit = p6PrescriptionVisit($this);

    RmePrescription::creating(function (): void {
        throw new RuntimeException('p6: injected prescription-row failure');
    });

    $this->actingAs($this->manager)
        ->post(route('rme.visits.prescription.store', $visit), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(4),
            'doctor_signature_canvas_data' => p6PngDataUri(5),
        ]))
        ->assertStatus(500);

    expect(p6StoredObjects())->toBe([])
        ->and(RmePrescription::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('never lets a refused prescription update overwrite the committed canvas in the same second', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-06 03:00:00', 'UTC'));
    $visit = p6PrescriptionVisit($this);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.prescription.store', $visit), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(6),
            'doctor_signature_canvas_data' => p6PngDataUri(7),
        ]))
        ->assertRedirect();

    $prescription = RmePrescription::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    $committed = (string) Storage::disk('clinical_evidence')->get($prescription->prescription_canvas_path);
    expect($committed)->toBe(p6PngBytes(6));

    $this->actingAs($this->manager)
        ->patch(route('rme.prescriptions.update', $prescription), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(8),
            'doctor_signature_canvas_data' => p6BlankCanvas(),
        ]))
        ->assertSessionHasErrors('doctor_signature_canvas_data');

    $prescription->refresh();
    expect((string) Storage::disk('clinical_evidence')->get($prescription->prescription_canvas_path))->toBe(p6PngBytes(6));
});

it('never lets a prescription update whose row write fails overwrite the committed canvas in the same second', function () {
    // Both canvases decode fine here, so decode-before-write does not help: only
    // the fresh random key keeps the committed object intact.
    Carbon::setTestNow(Carbon::parse('2026-11-06 03:00:00', 'UTC'));
    $visit = p6PrescriptionVisit($this);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.prescription.store', $visit), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(12),
            'doctor_signature_canvas_data' => p6PngDataUri(13),
        ]))
        ->assertRedirect();

    $prescription = RmePrescription::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    $objectsBefore = p6StoredObjects();

    RmePrescription::updating(function (): void {
        throw new RuntimeException('p6: injected prescription-update failure');
    });

    $this->actingAs($this->manager)
        ->patch(route('rme.prescriptions.update', $prescription), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(14),
            'doctor_signature_canvas_data' => p6PngDataUri(15),
        ]))
        ->assertStatus(500);

    $prescription->refresh();
    expect(p6StoredObjects())->toBe($objectsBefore)
        ->and((string) Storage::disk('clinical_evidence')->get($prescription->prescription_canvas_path))->toBe(p6PngBytes(12))
        ->and((string) Storage::disk('clinical_evidence')->get($prescription->doctor_signature_canvas_path))->toBe(p6PngBytes(13));
});

// --------------------------------------------------------------------------
// Review LOW-1 — a failure AFTER the transaction body never deletes the object
// --------------------------------------------------------------------------

it('keeps the written object when the failure comes after the transaction body', function () {
    // An after-commit callback that throws stands in for any failure whose
    // commit outcome is unknown (a dropped connection at COMMIT): the row may
    // already be committed, so the object it names must survive.
    MedicalRecordHandwritingPage::created(function (): void {
        DB::afterCommit(function (): void {
            throw new RuntimeException('p6: injected post-commit failure');
        });
    });

    p6SaveHandwriting($this, $this->visit, $this->record, p6PngDataUri(16), 2)->assertStatus(500);

    $row = p6StoredRowFor($this->record, 2);
    expect($row)->not->toBeNull();
    p6AssertRowMatchesItsObject($row);
});

// --------------------------------------------------------------------------
// P6-RX-03 — the stored canvases actually load on the prescription page
// --------------------------------------------------------------------------

it('streams the stored prescription and signature canvases to an authorized user', function (string $kind) {
    $visit = p6PrescriptionVisit($this);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.prescription.store', $visit), p6PrescriptionForm($visit, [
            'prescription_canvas_data' => p6PngDataUri(9),
            'doctor_signature_canvas_data' => p6PngDataUri(10),
        ]))
        ->assertRedirect();

    $prescription = RmePrescription::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    $expected = $kind === 'signature' ? p6PngBytes(10) : p6PngBytes(9);

    $response = $this->actingAs($this->manager)
        ->get(route('rme.prescriptions.canvas', [$prescription, $kind]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($expected);
})->with(['prescription', 'signature']);

it('still refuses an unknown canvas kind and an unauthorized reader', function () {
    $visit = p6PrescriptionVisit($this);
    $prescription = RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.prescriptions.canvas', [$prescription, 'foo']))
        ->assertNotFound();

    $this->actingAs(userWith([]))
        ->get(route('rme.prescriptions.canvas', [$prescription, 'prescription']))
        ->assertForbidden();
});

// --------------------------------------------------------------------------
// P6-PDF-01/02 — a missing, unreadable or non-PNG object prints honestly
// --------------------------------------------------------------------------

function p6RecordPageOne(object $test, string $path): MedicalRecordHandwriting
{
    return MedicalRecordHandwriting::create([
        'medical_record_id' => $test->record->id,
        'clinic_visit_id' => $test->visit->id,
        'branch_id' => $test->branch->id,
        'doctor_id' => $test->doctor->id,
        'handwriting_path' => $path,
        'handwriting_hash' => hash('sha256', 'p6'),
        'saved_at' => now(),
        'created_by' => $test->manager->id,
    ]);
}

it('prints an honest placeholder, never an empty image, for a recorded page whose object is missing', function () {
    p6RecordPageOne($this, 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/missing.png');

    $html = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $this->visit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Halaman 1')
        ->and($html)->toContain('Gambar halaman ini tidak tersedia')
        ->and($html)->not->toContain('src=""');
});

it('never embeds bytes that are not a PNG as a PNG image', function () {
    $path = 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/corrupt.png';
    Storage::disk('clinical_evidence')->put($path, 'this is not an image');

    expect(ClinicalEvidenceStorage::dataUri($path))->toBeNull();

    p6RecordPageOne($this, $path);
    $html = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $this->visit->fresh()))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Gambar halaman ini tidak tersedia')
        ->and($html)->not->toContain(base64_encode('this is not an image'));
});

it('answers the print with a placeholder, not an HTTP 500, when a stored object cannot be read', function () {
    $path = 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/unreadable.png';
    p6RecordPageOne($this, $path);

    // The production disk is configured with 'throw' => true, so a read fault
    // after exists() surfaces as UnableToReadFile.
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('get')->andThrow(UnableToReadFile::fromLocation($path, 'p6: permission denied'));
    Storage::set('clinical_evidence', $disk);

    expect(ClinicalEvidenceStorage::dataUri($path))->toBeNull();

    $html = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $this->visit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Gambar halaman ini tidak tersedia');
});

it('still embeds a readable PNG page inline in the print', function () {
    $path = 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/readable.png';
    Storage::disk('clinical_evidence')->put($path, p6PngBytes(11));
    p6RecordPageOne($this, $path);

    $html = $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $this->visit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data:image/png;base64,'.base64_encode(p6PngBytes(11)))
        ->and($html)->not->toContain('Gambar halaman ini tidak tersedia');
});

it('prints the honest R/ placeholder when the stored prescription canvas is missing', function () {
    $visit = p6PrescriptionVisit($this);
    $prescription = RmePrescription::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'prescription_canvas_path' => 'prescriptions/'.$visit->branch_id.'/'.$visit->id.'/gone.png',
        'doctor_signature_canvas_path' => 'prescriptions/'.$visit->branch_id.'/'.$visit->id.'/gone-signature.png',
    ]);

    $html = $this->actingAs($this->manager)
        ->get(route('rme.prescriptions.print', $prescription))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Belum ada gambar resep.')
        ->and($html)->not->toContain('src=""');
});
