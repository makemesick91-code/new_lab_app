<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1) — handwriting page revision
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-16, "fix with page revision": two devices on one RM
| page used to resolve last-writer-wins — the earlier drawing stayed on disk but
| the page showed only the last save. Every page now carries an opaque revision
| (MedicalRecord::handwritingRevision(), `none` for a page without a drawing);
| the form posts the revision it loaded; the service compares it under the
| record lock, before any object is written, and refuses a stale save with a
| reload message. Nothing is silently replaced.
|
| Selected by the existing `RmeClinicalWriteAuthority` critical-gate token in
| both runner variants and declared in critical_gate_mandatory_suites (CG-19).
| Helper prefix `rhrv` is unique because the critical gate loads every suite into
| one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const RHRV_PROBE = 'rhrv_page_revision_probe';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->deviceA = rmeClinicalAuthor(['manage_clinic_visits']);
    $this->deviceB = rmeClinicalAuthor(['manage_clinic_visits']);

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'clinic_id' => Clinic::factory()->create()->id,
        'patient_id' => Patient::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'queue_number' => 1,
    ]);
    rmeActiveConsentedEncounter($this->visit);

    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $this->visit->patient_id,
        'doctor_id' => $this->visit->doctor_id,
    ]);
});

afterEach(function () {
    if (config()->has('database.connections.'.RHRV_PROBE)) {
        DB::connection(RHRV_PROBE)->rollBack(0);
        DB::purge(RHRV_PROBE);
    }
});

/** A distinct, non-blank 10x10 RGBA page drawing. */
function rhrvDrawing(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 37 + $y) % 256, 10, 10, 255), 10);
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw))
        .$chunk('IEND', ''));
}

function rhrvSave(object $t, $actor, int $page, int $seed, ?string $revision)
{
    $payload = ['handwriting_data' => rhrvDrawing($seed), 'page_number' => $page];
    if ($revision !== null) {
        $payload['page_revision'] = $revision;
    }

    return $t->actingAs($actor)->post(route('rme.visits.medical-record.handwriting.store', [$t->visit, $t->record]), $payload);
}

function rhrvPagePath(object $t, int $page): ?string
{
    return $page === 1
        ? MedicalRecordHandwriting::query()->where('medical_record_id', $t->record->id)->value('handwriting_path')
        : DB::table('trx_medical_record_handwriting_pages')->where('medical_record_id', $t->record->id)->where('page_number', $page)->value('handwriting_path');
}

it('HR-01 refuses the second of two devices that loaded the same page, keeping the first drawing', function () {
    rhrvSave($this, $this->deviceA, 1, 1, null)->assertSessionHasNoErrors();
    $loaded = MedicalRecord::handwritingRevision(rhrvPagePath($this, 1));

    // Both devices opened page 1 at the same revision; A saves first.
    rhrvSave($this, $this->deviceA, 1, 2, $loaded)->assertSessionHasNoErrors();
    $afterA = rhrvPagePath($this, 1);
    $objects = count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());

    rhrvSave($this, $this->deviceB, 1, 3, $loaded)
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_STALE_PAGE]);

    expect(rhrvPagePath($this, 1))->toBe($afterA)
        // Refused before any object was written.
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toHaveCount($objects);

    // B reloads (new revision) and saves on top of A's drawing.
    rhrvSave($this, $this->deviceB, 1, 3, MedicalRecord::handwritingRevision($afterA))->assertSessionHasNoErrors();
    expect(rhrvPagePath($this, 1))->not->toBe($afterA);
});

it('HR-02 refuses the second of two devices adding the same new page', function () {
    rhrvSave($this, $this->deviceA, 1, 1, null)->assertSessionHasNoErrors();

    // Both devices pressed "+ Tambah Halaman RM" for page 2 (revision none).
    rhrvSave($this, $this->deviceA, 2, 4, MedicalRecord::HANDWRITING_REVISION_NONE)->assertSessionHasNoErrors();
    $pageTwo = rhrvPagePath($this, 2);

    rhrvSave($this, $this->deviceB, 2, 5, MedicalRecord::HANDWRITING_REVISION_NONE)
        ->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_STALE_PAGE]);

    expect(rhrvPagePath($this, 2))->toBe($pageTwo)
        ->and(DB::table('trx_medical_record_handwriting_pages')->where('medical_record_id', $this->record->id)->count())->toBe(1);
});

it('HR-03 keeps a save without a revision working exactly as before', function () {
    rhrvSave($this, $this->deviceA, 1, 1, null)->assertSessionHasNoErrors();
    rhrvSave($this, $this->deviceB, 1, 2, null)->assertSessionHasNoErrors();

    expect(rhrvPagePath($this, 1))->not->toBeNull();
});

it('HR-04 renders the active page revision, never the object key', function () {
    rhrvSave($this, $this->deviceA, 1, 1, null)->assertSessionHasNoErrors();
    $path = rhrvPagePath($this, 1);

    $html = $this->actingAs($this->deviceA)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('name="page_revision"')
        ->and($html)->toContain(MedicalRecord::handwritingRevision($path))
        ->and($html)->not->toContain($path);
});

it('HR-05 decides the revision only after taking the record row lock', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks are only emitted on PostgreSQL.');
    }

    rhrvSave($this, $this->deviceA, 1, 1, null)->assertSessionHasNoErrors();
    $loaded = MedicalRecord::handwritingRevision(rhrvPagePath($this, 1));

    $statements = [];
    DB::listen(function (QueryExecuted $q) use (&$statements): void {
        $statements[] = strtolower($q->sql);
    });

    rhrvSave($this, $this->deviceB, 1, 9, $loaded)->assertSessionHasNoErrors();

    $lockAt = collect($statements)->search(fn (string $s) => str_contains($s, 'trx_medical_records') && str_contains($s, 'for no key update'));
    $revisionReadAt = collect($statements)->search(fn (string $s, int $i) => $i > ($lockAt === false ? PHP_INT_MAX : $lockAt)
        && str_contains($s, 'trx_medical_record_handwritings') && str_starts_with(ltrim($s), 'select'));

    // The two devices serialise on the record row: the revision a save compares
    // is the one committed before it, never a value read before the lock.
    expect($lockAt)->not->toBeFalse()
        ->and($revisionReadAt)->not->toBeFalse();
});

it('HR-06 compares page 1 with the very row the page renders when two page-1 rows exist', function () {
    // Two page-1 rows (possible before the record lock existed) whose orders
    // disagree: A was created last, B was saved last. The page renders B
    // (MedicalRecord::latestHandwriting(): saved_at desc, id desc).
    $row = fn (string $path, string $createdAt, string $savedAt) => DB::table('trx_medical_record_handwritings')->insertGetId([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->visit->branch_id,
        'handwriting_path' => $path,
        'handwriting_hash' => str_repeat('a', 64),
        'saved_at' => $savedAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $row('handwritings/hr06/a.png', now()->subMinutes(1)->toDateTimeString(), now()->subMinutes(30)->toDateTimeString());
    $row('handwritings/hr06/b.png', now()->subMinutes(30)->toDateTimeString(), now()->subMinutes(1)->toDateTimeString());

    $rendered = MedicalRecord::handwritingRevision($this->record->fresh()->latestHandwriting()?->handwriting_path);

    expect($rendered)->toBe(MedicalRecord::handwritingRevision('handwritings/hr06/b.png'));

    // A save that loaded the page as rendered is accepted, not refused forever.
    rhrvSave($this, $this->deviceA, 1, 6, $rendered)->assertSessionHasNoErrors();
});
