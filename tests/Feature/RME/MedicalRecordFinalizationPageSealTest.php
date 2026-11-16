<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR2 — handwriting pages sealed on finalize
|--------------------------------------------------------------------------
|
| Rule 240 §4 (owner decision 2026-11-17). Finalizing a sheet seals the pages of
| the patient's handwriting book that exist at that moment (except, when the
| sheet is not the live encounter's own, the pages the live encounter wrote).
| A sealed page is never overwritten — by any account, Super Admin included — and
| the refusal comes after the page-revision compare and before any object is
| written. Appending a NEW page in a live, consented encounter stays allowed
| (OD-5-6, R7-1): it goes onto the one book and stays writable until a sheet is
| finalized again. A page that carries neither a seal nor an authoring stamp
| (written before this rule) is locked when a final sheet of the patient was
| finalized at or after the page was created (ties lock; fails closed).
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m33s` is unique because the critical gate loads every suite into one
| process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->author = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A non-blank 10x10 RGBA drawing, distinct per seed. */
function m33sDrawing(int $seed): string
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

/** A live, consented encounter for the suite's patient, with its own sheet. */
function m33sEncounter(string $visitDate): array
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'visit_date' => $visitDate,
    ]);
    rmeActiveConsentedEncounter($visit);

    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return [$visit->fresh(), $sheet->fresh()];
}

function m33sCloseEncounter(ClinicVisit $visit): void
{
    $visit->forceFill(['status' => ClinicVisit::STATUS_COMPLETED, 'completed_at' => now()])->save();
}

/** Save a page through the real route, posting to the book (the canonical form action). */
function m33sSave($actor, ClinicVisit $bookVisit, MedicalRecord $book, int $page, int $seed, ?string $revision = null)
{
    $payload = ['handwriting_data' => m33sDrawing($seed), 'page_number' => $page];
    if ($revision !== null) {
        $payload['page_revision'] = $revision;
    }

    return test()->actingAs($actor)->post(route('rme.visits.medical-record.handwriting.store', [$bookVisit, $book]), $payload);
}

function m33sRow(MedicalRecord $book, int $page): MedicalRecordHandwriting|MedicalRecordHandwritingPage|null
{
    return $page === 1
        ? MedicalRecordHandwriting::query()->where('medical_record_id', $book->id)->first()
        : MedicalRecordHandwritingPage::query()->where('medical_record_id', $book->id)->where('page_number', $page)->first();
}

function m33sRevision(MedicalRecord $book, int $page): string
{
    return MedicalRecord::handwritingRevision(m33sRow($book, $page)?->handwriting_path);
}

function m33sObjects(): int
{
    return count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());
}

function m33sFinalize(MedicalRecord $sheet, $actor): MedicalRecord
{
    test()->actingAs($actor);

    return app(MedicalRecordService::class)->finalize($sheet->fresh());
}

// ─── Seal on finalize ────────────────────────────────────────────────────────

it('seals every page of the book when the encounter sheet is finalized, and stamps the sheet', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sSave($this->author, $v1, $book, 2, 2)->assertSessionHasNoErrors();

    expect(m33sRow($book, 1)->sealed_at)->toBeNull()
        ->and(m33sRow($book, 1)->authored_clinic_visit_id)->toBe($v1->id)
        ->and(m33sRow($book, 2)->authored_clinic_visit_id)->toBe($v1->id);

    m33sFinalize($book, $this->author);

    foreach ([1, 2] as $page) {
        expect(m33sRow($book, $page)->sealed_at)->not->toBeNull()
            ->and(m33sRow($book, $page)->sealed_by_medical_record_id)->toBe($book->id);
    }
});

it('refuses to overwrite a sealed page over HTTP, before any object is written, for a clinician and a Super Admin', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sSave($this->author, $v1, $book, 2, 2)->assertSessionHasNoErrors();
    m33sFinalize($book, $this->author);

    $paths = [1 => m33sRow($book, 1)->handwriting_path, 2 => m33sRow($book, 2)->handwriting_path];
    $objects = m33sObjects();

    foreach ([$this->author, superAdmin()] as $user) {
        foreach ([1, 2] as $page) {
            m33sSave($user, $v1, $book, $page, 9, m33sRevision($book, $page))
                ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
        }
    }

    expect(m33sRow($book, 1)->handwriting_path)->toBe($paths[1])
        ->and(m33sRow($book, 2)->handwriting_path)->toBe($paths[2])
        ->and(m33sObjects())->toBe($objects);
});

it('refuses to overwrite a sealed page in the service itself, whoever the caller vouched for', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sFinalize($book, $this->author);
    $objects = m33sObjects();
    $png = base64_decode(substr(m33sDrawing(7), strlen('data:image/png;base64,')));

    $refused = null;
    try {
        app(MedicalRecordHandwritingService::class)->save(
            fn (): array => [$book->fresh(), $v1->fresh(), $v1->fresh()],
            1,
            $png,
            superAdmin()->id,
            m33sRevision($book, 1),
        );
    } catch (ValidationException $e) {
        $refused = $e->errors()['handwriting_data'][0] ?? null;
    }

    expect($refused)->toBe(MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE)
        ->and(m33sObjects())->toBe($objects);
});

it('keeps the page-revision compare first: a stale save is refused as stale, sealed or not', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    $loaded = m33sRevision($book, 1);
    m33sSave($this->author, $v1, $book, 1, 2, $loaded)->assertSessionHasNoErrors();

    // Unsealed page, stale revision: the OD-6-1 refusal, unchanged.
    m33sSave($this->author, $v1, $book, 1, 3, $loaded)
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_STALE_PAGE]);

    m33sFinalize($book, $this->author);

    // Sealed page, stale revision: still the stale refusal (compared first).
    m33sSave($this->author, $v1, $book, 1, 4, $loaded)
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_STALE_PAGE]);
});

// ─── A later encounter appends to the one book ───────────────────────────────

it('lets a later encounter append a new page to the one book, writable until that encounter\'s sheet is final', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sFinalize($book, $this->author);
    m33sCloseEncounter($v1);

    [$v2, $sheet2] = m33sEncounter('2026-11-10');
    $sheets = MedicalRecord::query()->where('patient_id', $this->patient->id)->count();

    // R7-1 — the page goes onto the book, stored on the book's visit.
    m33sSave($this->author, $v1, $book, 2, 2, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();
    $page2 = m33sRow($book, 2);

    expect($page2)->not->toBeNull()
        ->and($page2->clinic_visit_id)->toBe($v1->id)
        ->and($page2->authored_clinic_visit_id)->toBe($v2->id)
        ->and($page2->sealed_at)->toBeNull()
        ->and(MedicalRecord::query()->where('patient_id', $this->patient->id)->count())->toBe($sheets);

    // Still writable in its own encounter.
    m33sSave($this->author, $v1, $book, 2, 3, m33sRevision($book, 2))->assertSessionHasNoErrors();
    // The sealed page 1 stays sealed.
    m33sSave($this->author, $v1, $book, 1, 4, m33sRevision($book, 1))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);

    m33sFinalize($sheet2, $this->author);

    expect(m33sRow($book, 2)->sealed_at)->not->toBeNull()
        ->and(m33sRow($book, 2)->sealed_by_medical_record_id)->toBe($sheet2->id)
        // An existing seal is never re-stamped.
        ->and(m33sRow($book, 1)->sealed_by_medical_record_id)->toBe($book->id);

    m33sSave($this->author, $v1, $book, 2, 5, m33sRevision($book, 2))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
});

// Review HIGH-1 — flipped from "does not seal the live encounter's pages when an
// older sheet is finalized during it": that spare left a returning patient's book
// unsealed (the book lives on the canonical visit). Finalize now seals every page.
it('seals the live encounter\'s pages too when an older sheet is finalized during it; the encounter keeps appending', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    // The first visit closed with its sheet still a draft.
    m33sCloseEncounter($v1);

    [$v2] = m33sEncounter('2026-11-10');
    m33sSave($this->author, $v1, $book, 2, 2, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();

    m33sFinalize($book, $this->author);

    expect(m33sRow($book, 1)->sealed_by_medical_record_id)->toBe($book->id)
        ->and(m33sRow($book, 2)->sealed_by_medical_record_id)->toBe($book->id);

    m33sSave($this->author, $v1, $book, 2, 3, m33sRevision($book, 2))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
    // Appending stays open in the live encounter.
    m33sSave($this->author, $v1, $book, 3, 4, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();
    expect(m33sRow($book, 3)->authored_clinic_visit_id)->toBe($v2->id);
});

// ─── Review HIGH-1: a returning patient's book, through the real routes ──────

it('seals a returning patient\'s whole book when the book is finalized, refusing overwrites in that and later encounters', function () {
    // An earlier visit with no sheet: the book is created on it (the canonical
    // visit, R7-1-FS) during the live encounter.
    $v0 = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => '2026-10-01',
    ]);
    $e = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'visit_date' => '2026-11-10']);
    rmeActiveConsentedEncounter($e);

    $this->actingAs($this->author)->post(route('rme.visits.medical-record.store', $e))->assertSessionHasNoErrors();
    $book = MedicalRecord::query()->where('patient_id', $this->patient->id)->sole();
    expect($book->clinic_visit_id)->toBe($v0->id);

    m33sSave($this->author, $v0, $book, 1, 1)->assertSessionHasNoErrors();
    m33sSave($this->author, $v0, $book, 2, 2, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();

    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.finalize', [$v0, $book]))
        ->assertSessionHasNoErrors();

    expect($book->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and(m33sRow($book, 1)->sealed_at)->not->toBeNull()
        ->and(m33sRow($book, 2)->sealed_at)->not->toBeNull();

    $objects = m33sObjects();
    foreach ([1, 2] as $page) {
        m33sSave($this->author, $v0, $book, $page, 5, m33sRevision($book, $page))
            ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
    }

    m33sCloseEncounter($e);
    $e2 = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'visit_date' => '2026-11-15']);
    rmeActiveConsentedEncounter($e2);

    m33sSave($this->author, $v0, $book, 2, 6, m33sRevision($book, 2))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
    expect(m33sObjects())->toBe($objects);
});

// ─── Review MEDIUM-1: only the live encounter overwrites its own pages ───────

it('refuses a later encounter overwriting an unsealed page an earlier encounter wrote, and shows it read-only', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    // No sheet is finalized: the page stays unsealed.
    m33sCloseEncounter($v1);
    [$v2] = m33sEncounter('2026-11-10');

    $path = m33sRow($book, 1)->handwriting_path;
    $objects = m33sObjects();

    m33sSave($this->author, $v1, $book, 1, 2, m33sRevision($book, 1))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_EARLIER_ENCOUNTER_PAGE]);
    m33sSave(superAdmin(), $v1, $book, 1, 3, m33sRevision($book, 1))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_EARLIER_ENCOUNTER_PAGE]);

    expect(m33sRow($book, 1)->handwriting_path)->toBe($path)
        ->and(m33sRow($book, 1)->sealed_at)->toBeNull()
        ->and(m33sObjects())->toBe($objects);

    // The live encounter appends instead, and overwrites its own page.
    m33sSave($this->author, $v1, $book, 2, 4, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();
    m33sSave($this->author, $v1, $book, 2, 5, m33sRevision($book, 2))->assertSessionHasNoErrors();
    expect(m33sRow($book, 2)->authored_clinic_visit_id)->toBe($v2->id);

    $this->actingAs($this->author)
        ->get(route('rme.visits.medical-record.show', ['clinicVisit' => $v1, 'rm_page' => 1]))
        ->assertOk()
        ->assertSee(MedicalRecordHandwritingService::MESSAGE_EARLIER_ENCOUNTER_PAGE)
        ->assertSee('data-sealed="1"', false)
        ->assertDontSee('Klik untuk menulis');
});

// ─── Review LOW-3 guards ─────────────────────────────────────────────────────

it('never touches another patient\'s pages when a sheet is finalized', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();

    $otherVisit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id]);
    $otherBook = MedicalRecord::factory()->create([
        'clinic_visit_id' => $otherVisit->id,
        'branch_id' => $otherVisit->branch_id,
        'patient_id' => $otherVisit->patient_id,
    ]);
    $otherPage = MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $otherBook->id,
        'clinic_visit_id' => $otherVisit->id,
        'branch_id' => $otherVisit->branch_id,
    ]);

    m33sFinalize($book, $this->author);

    expect(m33sRow($book, 1)->sealed_at)->not->toBeNull()
        ->and($otherPage->fresh()->sealed_at)->toBeNull()
        ->and($otherPage->fresh()->sealed_by_medical_record_id)->toBeNull();
});

it('refuses a sealed page of a non-canonical sheet through the edit-in-place path', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sCloseEncounter($v1);
    [$v2, $sheet2] = m33sEncounter('2026-11-10');
    // A later sheet carrying its own page 1 (the pre-Sprint-64.0.2 shape), written now.
    $legacy = MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet2->id,
        'clinic_visit_id' => $v2->id,
        'branch_id' => $v2->branch_id,
        'authored_clinic_visit_id' => $v2->id,
    ]);
    m33sFinalize($sheet2, $this->author);
    expect($legacy->fresh()->sealed_by_medical_record_id)->toBe($sheet2->id);

    $path = $legacy->handwriting_path;
    m33sSave($this->author, $v2, $sheet2, 1, 2, MedicalRecord::handwritingRevision($path))
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
    expect($legacy->fresh()->handwriting_path)->toBe($path);
});

it('refuses a sealed page when the canonical-draft path adopts an existing book', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sFinalize($book, $this->author);
    $objects = m33sObjects();
    $png = base64_decode(substr(m33sDrawing(9), strlen('data:image/png;base64,')));
    $this->actingAs($this->author);

    $refused = null;
    try {
        app(MedicalRecordHandwritingService::class)->save(
            function () use ($v1): array {
                // What the controller's first-save path does: get or ADOPT the book.
                $draft = app(MedicalRecordService::class)->getOrCreateCanonicalMedicalRecord($v1, $this->author->id, $v1->id);

                return [$draft, $draft->clinicVisit, $v1];
            },
            1,
            $png,
            $this->author->id,
            m33sRevision($book, 1),
        );
    } catch (ValidationException $e) {
        $refused = $e->errors()['handwriting_data'][0] ?? null;
    }

    expect($refused)->toBe(MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE)
        ->and(m33sObjects())->toBe($objects)
        ->and(MedicalRecord::query()->where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('still locks a pre-rule page when the only final sheet that covers it is soft-deleted', function () {
    [$v2, $book] = m33sEncounter('2026-11-10');
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $book->id,
        'clinic_visit_id' => $v2->id,
        'branch_id' => $v2->branch_id,
        'created_at' => Carbon::parse('2026-11-01 02:00:00'),
    ]);
    $older = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => '2026-11-12',
    ]);
    $final = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $older->id,
        'branch_id' => $older->branch_id,
        'patient_id' => $this->patient->id,
        'finalized_at' => Carbon::parse('2026-11-02 02:00:00'),
    ]);
    $final->delete();

    expect(MedicalRecord::query()->whereKey($final->id)->exists())->toBeFalse()
        ->and(app(MedicalRecordHandwritingService::class)->isPageSealed($book->fresh(), 1))->toBeTrue();
});

// ─── Pages written before this rule (no seal, no authoring stamp) ────────────

it('locks an unstamped page when a final sheet was finalized at or after its creation (ties lock)', function (string $finalizedAt, bool $locked) {
    [$v2, $book] = m33sEncounter('2026-11-10');
    $created = Carbon::parse('2026-11-01 02:00:00');
    $legacy = MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $book->id,
        'clinic_visit_id' => $v2->id,
        'branch_id' => $v2->branch_id,
        'created_at' => $created,
        'updated_at' => $created,
    ]);
    // Another sheet of the patient, final. Its visit is dated after the book's,
    // so the book stays the encounter sheet created above.
    $older = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => '2026-11-12',
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $older->id,
        'branch_id' => $older->branch_id,
        'patient_id' => $this->patient->id,
        'finalized_at' => Carbon::parse($finalizedAt),
    ]);

    expect(app(MedicalRecordHandwritingService::class)->isPageSealed($book->fresh(), 1))->toBe($locked);

    $path = $legacy->handwriting_path;
    $response = m33sSave($this->author, $v2, $book, 1, 5, m33sRevision($book, 1));

    if ($locked) {
        $response->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE]);
        expect($legacy->fresh()->handwriting_path)->toBe($path);
    } else {
        $response->assertSessionHasNoErrors();
        expect($legacy->fresh()->handwriting_path)->not->toBe($path)
            ->and($legacy->fresh()->authored_clinic_visit_id)->toBe($v2->id);
    }
})->with([
    'finalized one second before the page' => ['2026-11-01 01:59:59', false],
    'finalized in the same second (tie)' => ['2026-11-01 02:00:00', true],
    'finalized one second after the page' => ['2026-11-01 02:00:01', true],
]);

it('fails closed on an unstamped page: a final sheet with no finalized_at locks it; no final sheet leaves it writable', function () {
    [$v2, $book] = m33sEncounter('2026-11-10');
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $book->id,
        'clinic_visit_id' => $v2->id,
        'branch_id' => $v2->branch_id,
        'page_number' => 2,
        'created_at' => Carbon::parse('2026-11-01 02:00:00'),
    ]);
    $service = app(MedicalRecordHandwritingService::class);

    expect($service->isPageSealed($book->fresh(), 2))->toBeFalse();

    // Another sheet of the patient, final. Its visit is dated after the book's,
    // so the book stays the encounter sheet created above.
    $older = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => '2026-11-12',
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $older->id,
        'branch_id' => $older->branch_id,
        'patient_id' => $this->patient->id,
        'finalized_at' => null,
    ]);

    expect($service->isPageSealed($book->fresh(), 2))->toBeTrue()
        // A page slot nobody wrote is never sealed: appending stays possible.
        ->and($service->isPageSealed($book->fresh(), 3))->toBeFalse();
});

// ─── The page mirrors the server (rule 135) ──────────────────────────────────

it('renders a sealed page read-only and still offers the add-page control', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    m33sFinalize($book, $this->author);
    m33sCloseEncounter($v1);
    m33sEncounter('2026-11-10');

    foreach ([$this->author, superAdmin()] as $user) {
        $this->actingAs($user)
            ->get(route('rme.visits.medical-record.show', ['clinicVisit' => $v1, 'rm_page' => 1]))
            ->assertOk()
            ->assertSee(MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE)
            ->assertSee('data-sealed="1"', false)
            ->assertDontSee('Klik untuk menulis')
            // The editor's only submittable target is the next, new page.
            ->assertSee('id="handwriting-page-input" value="2"', false)
            ->assertSee('+ Tambah Halaman RM');
    }
});

it('renders an unsealed page as writable (positive control)', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();

    $this->actingAs($this->author)
        ->get(route('rme.visits.medical-record.show', ['clinicVisit' => $v1, 'rm_page' => 1]))
        ->assertOk()
        ->assertSee('Klik untuk menulis')
        ->assertSee('id="handwriting-page-input" value="1"', false)
        ->assertDontSee('data-sealed="1"', false)
        ->assertDontSee(MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE);
});

it('names the seal-column foreign keys explicitly, within PostgreSQL\'s 63 characters (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Constraint names are a PostgreSQL catalogue fact.');
    }

    $names = collect(DB::select("SELECT conname FROM pg_constraint WHERE conrelid IN ('trx_medical_record_handwritings'::regclass, 'trx_medical_record_handwriting_pages'::regclass) AND contype = 'f'"))
        ->pluck('conname');

    expect($names)->toContain('trx_mrh_authored_visit_fk', 'trx_mrh_sealed_by_record_fk', 'trx_mrhp_authored_visit_fk', 'trx_mrhp_sealed_by_record_fk')
        ->and($names->every(fn (string $name): bool => strlen($name) <= 63))->toBeTrue();
});

// ─── Lock order (rule 223 §1 item 6) ────────────────────────────────────────────

it('seals under the visit lock, then the sheet lock, before any page row is written (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();

    $events = [];
    DB::listen(function ($query) use (&$events) {
        $sql = strtolower(ltrim($query->sql));
        if (preg_match('/for (no key )?update/', $sql)) {
            $events[] = str_contains($sql, 'trx_clinic_visits') ? 'visit' : (str_contains($sql, 'trx_medical_records') ? 'sheet' : 'other');
        } elseif (str_starts_with($sql, 'update "trx_medical_record_handwriting')) {
            $events[] = 'seal';
        }
    });

    m33sFinalize($book, $this->author);

    $visitAt = array_search('visit', $events, true);
    $sheetAt = array_search('sheet', $events, true);
    $sealAt = array_search('seal', $events, true);

    expect($visitAt)->not->toBeFalse()
        ->and($sheetAt)->not->toBeFalse()
        ->and($sealAt)->not->toBeFalse()
        ->and($visitAt)->toBeLessThan($sheetAt)
        ->and($sheetAt)->toBeLessThan($sealAt);
});
