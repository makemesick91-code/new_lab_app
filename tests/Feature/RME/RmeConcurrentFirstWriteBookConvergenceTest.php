<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (R7-1) — one handwriting RM book per patient
|--------------------------------------------------------------------------
|
| Rule 225 narrowed R7-1 to two interleavings that it recorded but never
| reproduced: (a) the patient's canonical (earliest non-cancelled) visit changing
| after the book exists, and (b) a patient merge that leaves the book on a later
| visit while the merged patient's earliest visit has none. In both, the book was
| looked up ONLY on the current canonical visit, so the next save found "no book"
| and opened a second one — and an edit of an existing page was relocated into
| that new book. No page was lost, but the book split.
|
| The patient's book is now the self-anchored sheet (clinic_visit_id =
| canonical_visit_id, or a pre-Sprint-64.0 sheet never stamped) when the current
| canonical visit has none; the first save decides the canonical visit on its
| locked row (rule 223 §6), and pages are written on the book's own visit.
| Rule 237 §16.
|
| Selected by the existing `RmeConcurrentFirstWrite` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `rbcv` is unique because the critical gate loads every suite into one
| process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../PatientMerge/helpers.php';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->author = rmeClinicalAuthor(['manage_clinic_visits']);
    $this->doctor = Doctor::factory()->create();
});

function rbcvVisit(Patient $patient, string $status, string $date): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $patient->branch_id ?? test()->branch->id,
        'clinic_id' => Clinic::factory()->create()->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'status' => $status,
        'visit_date' => $date,
    ]);
}

/** A distinct, non-blank 10x10 RGBA drawing. */
function rbcvDrawing(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 41 + $y) % 256, 20, 20, 255), 10);
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw))
        .$chunk('IEND', ''));
}

function rbcvSave(object $t, ClinicVisit $visit, MedicalRecord $record, int $page, int $seed)
{
    return $t->actingAs($t->author)->post(
        route('rme.visits.medical-record.handwriting.store', [$visit, $record]),
        ['handwriting_data' => rbcvDrawing($seed), 'page_number' => $page],
    );
}

function rbcvBooks(int $patientId)
{
    return MedicalRecord::query()->where('patient_id', $patientId)->orderBy('id')->get();
}

function rbcvPageCount(MedicalRecord $record): int
{
    return MedicalRecordHandwriting::query()->where('medical_record_id', $record->id)->count()
        + DB::table('trx_medical_record_handwriting_pages')->where('medical_record_id', $record->id)->count();
}

/*
| (a) The canonical visit changes after the book exists
*/

it('R71-A keeps writing new pages into the existing book after the visit holding it is cancelled', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    // Yesterday's registration was never served; today's examination is running.
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    // The first sheet starts the book on the canonical (earliest) visit — the no-show.
    $this->actingAs($this->author)->post(route('rme.visits.medical-record.store', $encounter))->assertSessionHasNoErrors();
    $book = rbcvBooks($patient->id)->sole();
    expect((int) $book->clinic_visit_id)->toBe((int) $noShow->id);

    rbcvSave($this, $noShow, $book, 1, 1)->assertSessionHasNoErrors();

    // The front desk cancels the no-show (allowed from `registered`).
    app(ClinicVisitService::class)->transitionStatus($noShow, ClinicVisit::STATUS_CANCELLED, $this->author);

    // The doctor adds page 2 from the page the workspace serves (the book's own visit).
    rbcvSave($this, $noShow->fresh(), $book, 2, 2)->assertSessionHasNoErrors();

    expect(rbcvBooks($patient->id))->toHaveCount(1)
        ->and(rbcvPageCount($book))->toBe(2);
});

it('R71-A2 edits an existing page in place after the visit holding the book is cancelled, never relocating it', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    $this->actingAs($this->author)->post(route('rme.visits.medical-record.store', $encounter))->assertSessionHasNoErrors();
    $book = rbcvBooks($patient->id)->sole();
    rbcvSave($this, $noShow, $book, 1, 1)->assertSessionHasNoErrors();
    $firstPath = MedicalRecordHandwriting::query()->where('medical_record_id', $book->id)->value('handwriting_path');

    app(ClinicVisitService::class)->transitionStatus($noShow, ClinicVisit::STATUS_CANCELLED, $this->author);

    // Re-save page 1 (an edit of the existing page).
    rbcvSave($this, $noShow->fresh(), $book, 1, 3)->assertSessionHasNoErrors();

    expect(rbcvBooks($patient->id))->toHaveCount(1)
        ->and(MedicalRecordHandwriting::query()->where('medical_record_id', $book->id)->value('handwriting_path'))->not->toBe($firstPath)
        ->and(rbcvPageCount($book))->toBe(1);
});

it('R71-A3 converges a first-save adoption onto the existing book when the canonical visit moved', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);
    $this->actingAs($this->author);

    // Request A creates the book on the no-show; the no-show is cancelled; request B,
    // which resolved "no book" for the new canonical visit, now asks for the book.
    $bookA = DB::transaction(fn () => app(MedicalRecordService::class)->getOrCreateCanonicalMedicalRecord($encounter, $this->author->id));
    app(ClinicVisitService::class)->transitionStatus($noShow, ClinicVisit::STATUS_CANCELLED, $this->author);
    $bookB = DB::transaction(fn () => app(MedicalRecordService::class)->getOrCreateCanonicalMedicalRecord($encounter->fresh(), $this->author->id));

    expect((int) $bookB->id)->toBe((int) $bookA->id)
        ->and(rbcvBooks($patient->id))->toHaveCount(1);
});

it('R71-D converges two first saves when the canonical visit is cancelled between one request\'s lookup and its insert', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);
    $this->actingAs($this->author);
    $service = app(MedicalRecordService::class);

    // Request A has just read the no-show as the canonical visit. Before A writes,
    // the front desk cancels the no-show and request B, which therefore resolves
    // today's encounter as canonical, creates the book there.
    $interleaved = false;
    $bookB = null;
    DB::listen(function ($query) use (&$interleaved, &$bookB, $noShow, $encounter, $service): void {
        $sql = strtolower($query->sql);
        if ($interleaved || ! str_contains($sql, 'trx_clinic_visits') || ! str_contains($sql, 'order by "visit_date"')) {
            return;
        }
        $interleaved = true;
        app(ClinicVisitService::class)->transitionStatus($noShow->fresh(), ClinicVisit::STATUS_CANCELLED, test()->author);
        $bookB = DB::transaction(fn () => $service->getOrCreateCanonicalMedicalRecord($encounter->fresh(), test()->author->id));
    });

    $bookA = DB::transaction(fn () => $service->getOrCreateCanonicalMedicalRecord($encounter, $this->author->id));

    expect($interleaved)->toBeTrue()
        ->and((int) $bookA->id)->toBe((int) $bookB->id)
        ->and(rbcvBooks($patient->id))->toHaveCount(1);
});

it('R71-E locks the canonical visit row before the first book is inserted', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks are only emitted on PostgreSQL.');
    }

    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $first = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(3)->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);
    $this->actingAs($this->author);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
    });

    DB::transaction(fn () => app(MedicalRecordService::class)->getOrCreateCanonicalMedicalRecord($encounter, $this->author->id));

    $lockAt = collect($statements)->search(fn (array $s) => str_contains($s['sql'], 'trx_clinic_visits')
        && str_contains($s['sql'], 'for no key update')
        && in_array($first->id, $s['bindings'], false));
    $insertAt = collect($statements)->search(fn (array $s) => str_starts_with(ltrim($s['sql']), 'insert into "trx_medical_records"'));

    // The cancel path takes the same lock (lockForStatusDecision), so a cancel and a
    // first save on the same canonical visit serialise; the book is decided after it.
    expect($lockAt)->not->toBeFalse()
        ->and($insertAt)->not->toBeFalse()
        ->and($lockAt)->toBeLessThan($insertAt);
});

/*
| (b) A patient merge
*/

it('R71-B keeps writing into the merged patient\'s existing book when the earliest merged visit has none', function () {
    $canonical = pmPatient();
    $source = pmPatient();

    // The canonical patient's book lives on a visit LATER than the source's first visit.
    $bookVisit = rbcvVisit($canonical, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(10)->toDateString());
    $book = MedicalRecord::factory()->create([
        'clinic_visit_id' => $bookVisit->id,
        'canonical_visit_id' => $bookVisit->id,
        'source_visit_id' => $bookVisit->id,
        'sheet_number' => 1,
        'patient_id' => $canonical->id,
        'branch_id' => $bookVisit->branch_id,
        'doctor_id' => $bookVisit->doctor_id,
    ]);
    rbcvVisit($source, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(30)->toDateString());

    pmMerge(pmSubmittedCase($source, $canonical, pmUser('Supervisor RME'), $canonical), pmUser('Supervisor RME'));

    $encounter = rbcvVisit($canonical, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    rbcvSave($this, $bookVisit, $book, 1, 5)->assertSessionHasNoErrors();

    expect(rbcvBooks($canonical->id))->toHaveCount(1)
        ->and(rbcvPageCount($book))->toBe(1);
});

it('R71-B2 keeps writing into a book created before Sprint 64.0 (workspace columns never stamped) after a merge', function () {
    $canonical = pmPatient();
    $source = pmPatient();

    $bookVisit = rbcvVisit($canonical, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(10)->toDateString());
    $book = MedicalRecord::factory()->create([
        'clinic_visit_id' => $bookVisit->id,
        'patient_id' => $canonical->id,
        'branch_id' => $bookVisit->branch_id,
        'doctor_id' => $bookVisit->doctor_id,
    ]);
    $book->forceFill(['canonical_visit_id' => null, 'source_visit_id' => null, 'sheet_number' => null])->saveQuietly();
    rbcvVisit($source, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(30)->toDateString());

    pmMerge(pmSubmittedCase($source, $canonical, pmUser('Supervisor RME'), $canonical), pmUser('Supervisor RME'));

    $encounter = rbcvVisit($canonical, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    rbcvSave($this, $bookVisit, $book, 1, 6)->assertSessionHasNoErrors();

    expect(rbcvBooks($canonical->id))->toHaveCount(1)
        ->and(rbcvPageCount($book))->toBe(1);
});

it('R71-B3 never takes a later sheet anchored to an earlier visit for the book', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $first = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(2)->toDateString());
    $later = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDay()->toDateString());
    // A later sheet anchored to the first visit: it was never created as the book.
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $later->id,
        'canonical_visit_id' => $first->id,
        'source_visit_id' => $later->id,
        'sheet_number' => 1,
        'patient_id' => $patient->id,
        'branch_id' => $later->branch_id,
        'doctor_id' => $later->doctor_id,
    ]);

    expect(app(PatientRmWorkspaceResolver::class)->selfAnchoredBook($patient->id))->toBeNull();
});

/*
| Controls — unchanged behaviour
*/

it('R71-C starts a patient\'s first book on the earliest visit, and writes into it while that visit stays', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $earliest = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(5)->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    $this->actingAs($this->author)->post(route('rme.visits.medical-record.store', $encounter))->assertSessionHasNoErrors();
    $book = rbcvBooks($patient->id)->sole();

    rbcvSave($this, $earliest, $book, 1, 1)->assertSessionHasNoErrors();
    rbcvSave($this, $earliest, $book, 2, 2)->assertSessionHasNoErrors();

    expect((int) $book->clinic_visit_id)->toBe((int) $earliest->id)
        ->and(rbcvBooks($patient->id))->toHaveCount(1)
        ->and(rbcvPageCount($book))->toBe(2);
});
