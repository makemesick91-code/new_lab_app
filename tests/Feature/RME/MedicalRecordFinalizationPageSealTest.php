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

it('does not seal the live encounter\'s pages when an older sheet is finalized during it', function () {
    [$v1, $book] = m33sEncounter('2026-11-01');
    m33sSave($this->author, $v1, $book, 1, 1)->assertSessionHasNoErrors();
    // The first visit closed with its sheet still a draft.
    m33sCloseEncounter($v1);

    [$v2, $sheet2] = m33sEncounter('2026-11-10');
    m33sSave($this->author, $v1, $book, 2, 2, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();

    // Finalizing the older sheet during the live encounter seals what existed
    // before it, never the page the live encounter is still writing.
    m33sFinalize($book, $this->author);

    expect(m33sRow($book, 1)->sealed_at)->not->toBeNull()
        ->and(m33sRow($book, 2)->sealed_at)->toBeNull();

    m33sSave($this->author, $v1, $book, 2, 3, m33sRevision($book, 2))->assertSessionHasNoErrors();

    m33sFinalize($sheet2, $this->author);
    expect(m33sRow($book, 2)->sealed_by_medical_record_id)->toBe($sheet2->id);
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
