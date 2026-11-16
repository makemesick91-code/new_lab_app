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
| The patient's book is now the earliest self-anchored sheet (clinic_visit_id =
| canonical_visit_id, or a pre-Sprint-64.0 sheet never stamped, which stamping
| anchors to itself); a record on the canonical visit is the book only when no
| such sheet exists. A first save — handwriting or "Buat Rekam Medis" — decides the
| canonical visit on its locked row (rule 223 §6) and looks the book up with the
| same selector; pages are written on the book's own visit. Rule 237 §16.
|
| R71-D and R71-G interleave a cancel and a concurrent first save on one
| connection at the exact statements the lock guards; R71-F proves the lock itself
| with a NOWAIT probe over a second connection on committed rows (rule 224 trap).
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
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../PatientMerge/helpers.php';

const RBCV_WRITER = 'rbcv_book_lock_writer';
const RBCV_PROBE = 'rbcv_book_lock_probe';

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

/** A pre-Sprint-64.0 sheet: the workspace columns were never stamped. */
function rbcvUnstampedSheet(ClinicVisit $visit): MedicalRecord
{
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'branch_id' => $visit->branch_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    $sheet->forceFill(['canonical_visit_id' => null, 'source_visit_id' => null, 'sheet_number' => null])->saveQuietly();

    return $sheet->fresh();
}

function rbcvEdit(object $t, ClinicVisit $visit, MedicalRecord $record, string $text)
{
    return $t->actingAs($t->author)->patch(
        route('rme.visits.medical-record.update', [$visit, $record]),
        ['subjective' => $text],
    );
}

/**
 * Two statements of a first save, observed on the one test connection:
 *  - the canonical-visit resolve (`status != cancelled … order by visit_date asc`), and
 *  - the first book lookup after it (a `trx_medical_records` select joined to visits).
 * $atResolve runs right after the first, $atLookup right after the second; both run
 * once, and nothing they do re-enters the listener.
 */
function rbcvInterleave(callable $atResolve, callable $atLookup): object
{
    $state = (object) ['phase' => 0, 'busy' => false];

    DB::listen(function ($query) use ($state, $atResolve, $atLookup): void {
        if ($state->busy) {
            return;
        }
        $sql = strtolower($query->sql);

        if ($state->phase === 0 && str_contains($sql, 'from "trx_clinic_visits"')
            && str_contains($sql, '"status" != ') && str_contains($sql, 'order by "visit_date" asc')) {
            $state->busy = true;
            $state->phase = 1;
            $atResolve();
            $state->busy = false;

            return;
        }

        if ($state->phase === 1 && str_starts_with(ltrim($sql), 'select')
            && str_contains($sql, 'from "trx_medical_records"') && str_contains($sql, 'join "trx_clinic_visits"')) {
            $state->busy = true;
            $state->phase = 2;
            $atLookup();
            $state->busy = false;
        }
    });

    return $state;
}

function rbcvProbeBlocked(string $table, int $id): bool
{
    DB::purge(RBCV_PROBE);

    try {
        DB::connection(RBCV_PROBE)->transaction(fn () => DB::connection(RBCV_PROBE)
            ->select("SELECT id FROM {$table} WHERE id = ? FOR NO KEY UPDATE NOWAIT", [$id]));
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'could not obtain lock');
    }

    return false;
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

it('R71-A4 keeps the book on the cancelled visit when a later sheet on the new canonical visit is anchored to it', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    // The first sheet starts the book on the no-show; page 1 is drawn.
    $this->actingAs($this->author)->post(route('rme.visits.medical-record.store', $encounter))->assertSessionHasNoErrors();
    $book = rbcvBooks($patient->id)->sole();
    rbcvSave($this, $noShow, $book, 1, 1)->assertSessionHasNoErrors();

    // "Tambah Lembar RM" on today's encounter: a per-visit sheet anchored to the no-show.
    $this->actingAs($this->author)->post(route('rme.visits.medical-record.store', $encounter))->assertSessionHasNoErrors();
    $sheet = MedicalRecord::query()->where('clinic_visit_id', $encounter->id)->sole();
    expect((int) $sheet->canonical_visit_id)->toBe((int) $noShow->id);

    // The no-show is cancelled: today's encounter becomes the canonical visit and holds a sheet.
    app(ClinicVisitService::class)->transitionStatus($noShow, ClinicVisit::STATUS_CANCELLED, $this->author);

    expect((int) app(PatientRmWorkspaceResolver::class)->canonicalMedicalRecord($patient->id)?->id)->toBe((int) $book->id);

    rbcvSave($this, $noShow->fresh(), $book, 2, 2)->assertSessionHasNoErrors();

    expect(rbcvBooks($patient->id))->toHaveCount(2)
        ->and(rbcvPageCount($book))->toBe(2)
        ->and(rbcvPageCount($sheet))->toBe(0);
});

it('R71-D re-resolves a canonical visit cancelled before its lock, so a concurrent first save\'s book is adopted, not split', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);
    $this->actingAs($this->author);
    $service = app(MedicalRecordService::class);
    $bookB = null;

    // Request A has just resolved the no-show as its canonical visit. Before A locks
    // it, the front desk cancels the no-show (in production that cancel and A's lock
    // serialise on the same row). After A's book lookup and before A's insert,
    // request B — resolving today's encounter — creates its book there. A must lock
    // the CURRENT canonical visit, find B's book on it and adopt it; a lock on the
    // stale no-show would let A insert a second book.
    $state = rbcvInterleave(
        fn () => app(ClinicVisitService::class)->transitionStatus($noShow->fresh(), ClinicVisit::STATUS_CANCELLED, test()->author),
        function () use (&$bookB, $service, $encounter): void {
            $bookB = $service->getOrCreateCanonicalMedicalRecord($encounter->fresh(), test()->author->id);
        },
    );

    $bookA = $service->getOrCreateCanonicalMedicalRecord($encounter, $this->author->id);

    expect($state->phase)->toBe(2)
        ->and($bookB)->not->toBeNull()
        ->and((int) $bookA->id)->toBe((int) $bookB->id)
        ->and((int) $bookA->clinic_visit_id)->toBe((int) $encounter->id)
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

it('R71-F holds the canonical visit row while the first book is created, so a cancel of that visit waits (two connections)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-lock behaviour is only observable on PostgreSQL; SQLite compiles row locks to nothing.');
    }

    // Rows are committed over a separate connection, so a NOWAIT probe on a third
    // connection sees the lock (RefreshDatabase hides uncommitted rows from it).
    // FOR NO KEY UPDATE, the lock the cancel path takes, is the probe: the book's
    // foreign-key KEY SHARE on the visit does not conflict with it, so the probe is
    // blocked only by the lock under test (rule 224 trap).
    $originalDefault = config('database.default');
    $base = config('database.connections.'.$originalDefault);
    config()->set('database.connections.'.RBCV_WRITER, $base);
    config()->set('database.connections.'.RBCV_PROBE, $base);
    DB::purge(RBCV_WRITER);
    DB::purge(RBCV_PROBE);
    config()->set('database.default', RBCV_WRITER);

    $w = DB::connection(RBCV_WRITER);
    $suffix = substr(uniqid(), -6);
    $ids = [];

    try {
        $ids['branch'] = (int) $w->table('mst_branches')->insertGetId([
            'code' => 'R7'.$suffix, 'name' => 'Cabang Buku '.$suffix, 'is_active' => true, 'is_rme_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['user'] = (int) $w->table('users')->insertGetId([
            'name' => 'Petugas '.$suffix, 'email' => 'rbcv-'.$suffix.'@example.test', 'password' => bcrypt('password'),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['doctor'] = (int) $w->table('mst_doctors')->insertGetId([
            'code' => 'R7D'.$suffix, 'name' => 'drg. Buku', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['patient'] = (int) $w->table('mst_patients')->insertGetId([
            'name' => 'Pasien Buku', 'medical_record_number' => 'R7F-'.$suffix, 'branch_id' => $ids['branch'],
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $visit = fn (string $status, string $date, int $queue): int => (int) $w->table('trx_clinic_visits')->insertGetId([
            'visit_number' => 'VIS-R7F-'.$suffix.'-'.$queue, 'branch_id' => $ids['branch'], 'patient_id' => $ids['patient'],
            'doctor_id' => $ids['doctor'], 'visit_date' => $date, 'queue_number' => $queue, 'status' => $status,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW, 'created_by' => $ids['user'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['noShow'] = $visit(ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString(), 1);
        $ids['encounter'] = $visit(ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString(), 2);

        // The authoring gate needs seeded roles and a signed consent, which a
        // committing connection would leave behind; R71-A…D prove the gate through
        // HTTP. Here only the lock is under test, so the gate answers the encounter.
        $encounter = ClinicVisit::query()->findOrFail($ids['encounter']);
        $consents = Mockery::mock(RmeVisitConsentService::class);
        $consents->shouldReceive('assertRmeAuthoringAllowedForPatient')->andReturn($encounter);
        app()->instance(RmeVisitConsentService::class, $consents);

        // Sanity: nothing holds the canonical visit, so the probe is not always blocked.
        expect(rbcvProbeBlocked('trx_clinic_visits', $ids['noShow']))->toBeFalse();

        $blockedWhileCreating = null;
        $w->transaction(function () use (&$blockedWhileCreating, $encounter, $ids): void {
            app(MedicalRecordService::class)->getOrCreateCanonicalMedicalRecord($encounter, null);
            $blockedWhileCreating = rbcvProbeBlocked('trx_clinic_visits', $ids['noShow']);
        });

        expect($blockedWhileCreating)->toBeTrue()
            ->and(rbcvProbeBlocked('trx_clinic_visits', $ids['noShow']))->toBeFalse()
            ->and($w->table('trx_medical_records')->where('patient_id', $ids['patient'])->pluck('clinic_visit_id')->map(fn ($id) => (int) $id)->all())
            ->toBe([$ids['noShow']]);
    } finally {
        $w->table('trx_medical_records')->where('patient_id', $ids['patient'] ?? 0)->delete();
        $w->table('trx_clinic_visits')->where('patient_id', $ids['patient'] ?? 0)->delete();
        $w->table('mst_patients')->where('id', $ids['patient'] ?? 0)->delete();
        $w->table('mst_doctors')->where('id', $ids['doctor'] ?? 0)->delete();
        $w->table('users')->where('id', $ids['user'] ?? 0)->delete();
        $w->table('mst_branches')->where('id', $ids['branch'] ?? 0)->delete();
        DB::purge(RBCV_WRITER);
        DB::purge(RBCV_PROBE);
        // RefreshDatabase rolls back the DEFAULT connection's transaction at
        // teardown; left on the writer, it would leave the seeded test transaction
        // open and force a migrate:fresh that waits on it.
        config()->set('database.default', $originalDefault);
    }
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

    // Editing and finalizing the book stamps its workspace columns. Stamping must
    // anchor the book to its own visit, never to the earlier merged visit, or the
    // next new page would open a second book there.
    rbcvEdit($this, $bookVisit, $book, 'Anamnesis setelah penggabungan.')->assertSessionHasNoErrors();
    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.finalize', [$bookVisit, $book]))
        ->assertSessionHasNoErrors();

    expect((int) $book->fresh()->canonical_visit_id)->toBe((int) $bookVisit->id);

    rbcvSave($this, $bookVisit, $book, 2, 7)->assertSessionHasNoErrors();

    expect(rbcvBooks($canonical->id))->toHaveCount(1)
        ->and(rbcvPageCount($book))->toBe(2);
});

it('R71-B4 keeps one book for a legacy patient whose earliest visit has no sheet, across edits of the sheets', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(5)->toDateString());
    $v1 = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(4)->toDateString());
    $v2 = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(3)->toDateString());
    $sheet1 = rbcvUnstampedSheet($v1);
    $sheet2 = rbcvUnstampedSheet($v2);
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);
    $resolver = app(PatientRmWorkspaceResolver::class);

    // The earliest pre-64.0 sheet is the book.
    expect((int) $resolver->canonicalMedicalRecord($patient->id)?->id)->toBe((int) $sheet1->id);
    rbcvSave($this, $v1, $sheet1, 1, 1)->assertSessionHasNoErrors();

    // Editing the book stamps it to itself; editing the later sheet anchors it to
    // the earliest visit, as before.
    rbcvEdit($this, $v1, $sheet1, 'Kontrol pasca pencabutan.')->assertSessionHasNoErrors();
    rbcvEdit($this, $v2, $sheet2, 'Catatan lama.')->assertSessionHasNoErrors();

    expect((int) $sheet1->fresh()->canonical_visit_id)->toBe((int) $v1->id)
        ->and((int) $resolver->canonicalMedicalRecord($patient->id)?->id)->toBe((int) $sheet1->id);

    rbcvSave($this, $v1, $sheet1, 2, 2)->assertSessionHasNoErrors();

    expect(rbcvBooks($patient->id))->toHaveCount(2)
        ->and(rbcvPageCount($sheet1))->toBe(2)
        ->and(rbcvPageCount($sheet2))->toBe(0);
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
| R7-1-FS — the "Buat Rekam Medis" first sheet starts the book under the same lock
*/

it('R71-G starts a first sheet under the canonical-visit lock: a cancel and a concurrent first handwriting save converge on one book', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $noShow = rbcvVisit($patient, ClinicVisit::STATUS_REGISTERED, clinicalToday()->subDay()->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);
    $service = app(MedicalRecordService::class);

    // "Buat Rekam Medis" has just resolved the no-show as the canonical visit; the
    // front desk cancels it; after the first-sheet path looks for the book and
    // before it inserts, a first handwriting save — resolving today's encounter —
    // creates the book there. One book must result, not one on each visit.
    $state = rbcvInterleave(
        fn () => app(ClinicVisitService::class)->transitionStatus($noShow->fresh(), ClinicVisit::STATUS_CANCELLED, test()->author),
        fn () => $service->getOrCreateCanonicalMedicalRecord($encounter->fresh(), test()->author->id),
    );

    // The path adopts that book and answers with the existing message.
    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.store', $encounter))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Rekam medis sudah ada untuk kunjungan ini.');

    expect($state->phase)->toBe(2)
        ->and(rbcvBooks($patient->id))->toHaveCount(1)
        ->and((int) rbcvBooks($patient->id)->sole()->clinic_visit_id)->toBe((int) $encounter->id);
});

it('R71-G2 still starts a first sheet on the earliest visit, self-anchored, with its SOAP fields', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $earliest = rbcvVisit($patient, ClinicVisit::STATUS_COMPLETED, clinicalToday()->subDays(5)->toDateString());
    $encounter = rbcvVisit($patient, ClinicVisit::STATUS_IN_PROGRESS, clinicalToday()->toDateString());
    rmeActiveConsentedEncounter($encounter);

    $this->actingAs($this->author)
        ->post(route('rme.visits.medical-record.store', $encounter), ['subjective' => 'Keluhan awal.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Rekam medis berhasil dibuat.');

    $book = rbcvBooks($patient->id)->sole();

    expect((int) $book->clinic_visit_id)->toBe((int) $earliest->id)
        ->and((int) $book->canonical_visit_id)->toBe((int) $earliest->id)
        ->and($book->subjective)->toBe('Keluhan awal.');
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
