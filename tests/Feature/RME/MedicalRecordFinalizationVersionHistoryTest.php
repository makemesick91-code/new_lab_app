<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR3 — version history of a final sheet
|--------------------------------------------------------------------------
|
| Rule 240 §5 (owner decision 2026-11-17). Finalizing a sheet writes version 1 of
| it — an immutable, hash-chained JSON snapshot (SOAP fields and notes,
| structured diagnoses, the locked handwriting pages with their object keys and
| SHA-256) — inside the finalize transaction, after the visit and sheet locks.
| Re-finalizing writes nothing. A sheet finalized before this rule gets a
| `baseline` version 1 the first time an amendment needs one. Versions are
| never updated or deleted: the model refuses, and on PostgreSQL a trigger
| refuses a raw UPDATE or DELETE. Reading versions is authorized like reading
| the record. No log or audit row carries the snapshot.
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m33v` is unique because the critical gate loads every suite into one
| process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

function m33vDrawing(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 53 + $y) % 256, 30, 30, 255), 10);
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw))
        .$chunk('IEND', ''));
}

/** A live, consented encounter with a sheet carrying SOAP text, two pages and two diagnoses. */
function m33vSheet(): array
{
    $visit = ClinicVisit::factory()->create(['branch_id' => test()->branch->id, 'patient_id' => test()->patient->id]);
    rmeActiveConsentedEncounter($visit);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'subjective' => 'm33v keluhan nyeri gigi 36',
        'objective' => 'm33v karies profunda',
        'assessment' => 'm33v pulpitis',
        'plan' => 'm33v perawatan saluran akar',
        'notes' => 'm33v catatan dokter',
    ]);

    foreach ([1, 2] as $page) {
        test()->actingAs(test()->author)
            ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $sheet]), [
                'handwriting_data' => m33vDrawing($page),
                'page_number' => $page,
            ])->assertSessionHasNoErrors();
    }

    MedicalRecordDiagnosis::create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create(['code' => 'K04.0'])->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
        'diagnosed_at' => now(),
    ]);
    MedicalRecordDiagnosis::create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create(['code' => 'K02.1'])->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
        'diagnosed_at' => now(),
    ]);

    return [$visit->fresh(), $sheet->fresh()];
}

function m33vFinalize(MedicalRecord $sheet): MedicalRecord
{
    test()->actingAs(test()->author);

    return app(MedicalRecordService::class)->finalize($sheet->fresh());
}

/** @return Collection<int, RmeRecordVersion> */
function m33vVersions(MedicalRecord $sheet)
{
    return RmeRecordVersion::query()
        ->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)
        ->where('subject_id', $sheet->id)
        ->orderBy('version')
        ->get();
}

// ─── Version 1 on finalize ───────────────────────────────────────────────────

it('writes version 1 of a sheet on finalize: the fields, the diagnoses and the sealed pages with their SHA-256', function () {
    [, $sheet] = m33vSheet();
    $final = m33vFinalize($sheet);

    $versions = m33vVersions($sheet);
    expect($versions)->toHaveCount(1);

    $v1 = $versions->first();
    expect($v1->version)->toBe(1)
        ->and($v1->kind)->toBe(RmeRecordVersion::KIND_FINAL)
        ->and($v1->previous_hash)->toBeNull()
        ->and($v1->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($v1->created_by)->toBe($this->author->id);

    $snapshot = $v1->snapshot;
    expect($snapshot['medical_record_id'])->toBe($sheet->id)
        ->and($snapshot['status'])->toBe(MedicalRecord::STATUS_FINAL)
        ->and($snapshot['finalized_at'])->toBe($final->finalized_at->toIso8601String())
        // jsonb keeps no key order; the hash is over the canonical (sorted) form.
        ->and($snapshot['fields'])->toEqual([
            'assessment' => 'm33v pulpitis',
            'notes' => 'm33v catatan dokter',
            'objective' => 'm33v karies profunda',
            'plan' => 'm33v perawatan saluran akar',
            'subjective' => 'm33v keluhan nyeri gigi 36',
        ])
        ->and(collect($snapshot['diagnoses'])->pluck('code')->all())->toBe(['K04.0', 'K02.1'])
        ->and(collect($snapshot['diagnoses'])->pluck('diagnosis_role')->all())->toBe(['primary', 'secondary']);

    $pages = collect($snapshot['pages'])->keyBy('page_number');
    expect($pages->keys()->sort()->values()->all())->toBe([1, 2]);

    $rows = [
        1 => MedicalRecordHandwriting::query()->where('medical_record_id', $sheet->id)->firstOrFail(),
        2 => MedicalRecordHandwritingPage::query()->where('medical_record_id', $sheet->id)->where('page_number', 2)->firstOrFail(),
    ];
    foreach ($rows as $number => $row) {
        $bytes = Storage::disk(ClinicalEvidenceStorage::diskName())->get($row->handwriting_path);
        expect($pages[$number]['object_key'])->toBe($row->handwriting_path)
            ->and($pages[$number]['sha256'])->toBe(hash('sha256', $bytes))
            ->and($pages[$number]['sealed_by_medical_record_id'])->toBe($sheet->id);
    }

    expect(app(RmeRecordVersionService::class)->verifyChain($sheet->fresh()))->toBeTrue();
});

it('writes nothing new when a final sheet is finalized again, even from a stale draft copy', function () {
    [, $sheet] = m33vSheet();
    $stale = $sheet->fresh();
    m33vFinalize($sheet);
    $v1 = m33vVersions($sheet)->first();

    m33vFinalize($sheet);
    $this->actingAs($this->author);
    app(MedicalRecordService::class)->finalize($stale);

    expect(m33vVersions($sheet))->toHaveCount(1)
        ->and(m33vVersions($sheet)->first()->hash)->toBe($v1->hash);
});

it('writes no version when the finalize is refused', function () {
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id]);
    rmeActiveConsentedEncounter($visit);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);

    // No handwriting: finalization is refused and rolls back.
    expect(fn () => m33vFinalize($sheet))->toThrow(ValidationException::class);
    expect(m33vVersions($sheet))->toHaveCount(0);
});

// ─── Hash chain ──────────────────────────────────────────────────────────────

it('detects a broken hash chain', function () {
    [, $sheet] = m33vSheet();
    m33vFinalize($sheet);
    $service = app(RmeRecordVersionService::class);
    $v1 = m33vVersions($sheet)->first();

    expect($service->verifyChain($sheet->fresh()))->toBeTrue()
        ->and($v1->hash)->toBe($service->hashFor($v1->subject_type, $v1->subject_id, $v1->version, $v1->kind, $v1->previous_hash, $v1->snapshot));

    // A forged version 2 whose previous hash is not version 1's hash.
    DB::table('trx_rme_record_versions')->insert([
        'subject_type' => RmeRecordVersion::SUBJECT_MEDICAL_RECORD,
        'subject_id' => $sheet->id,
        'version' => 2,
        'kind' => RmeRecordVersion::KIND_AMENDMENT,
        'snapshot_schema_version' => 1,
        'snapshot' => json_encode(['forged' => true]),
        'previous_hash' => str_repeat('0', 64),
        'hash' => str_repeat('1', 64),
        'created_at' => now(),
    ]);

    expect($service->verifyChain($sheet->fresh()))->toBeFalse();
});

// ─── Immutability ────────────────────────────────────────────────────────────

it('refuses to update or delete a version through the model', function () {
    [, $sheet] = m33vSheet();
    m33vFinalize($sheet);
    $v1 = m33vVersions($sheet)->first();

    expect(fn () => $v1->update(['kind' => RmeRecordVersion::KIND_BASELINE]))->toThrow(LogicException::class)
        ->and(fn () => $v1->delete())->toThrow(LogicException::class)
        ->and(m33vVersions($sheet)->first()->kind)->toBe(RmeRecordVersion::KIND_FINAL);
});

it('refuses a raw UPDATE and a raw DELETE of a version in PostgreSQL', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The immutability trigger exists on PostgreSQL only; the model guard covers SQLite.');
    }

    [, $sheet] = m33vSheet();
    m33vFinalize($sheet);
    $id = m33vVersions($sheet)->first()->id;

    expect(fn () => DB::transaction(fn () => DB::table('trx_rme_record_versions')->where('id', $id)->update(['kind' => 'baseline'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('trx_rme_record_versions')->where('id', $id)->delete()))
        ->toThrow(QueryException::class);

    expect(DB::table('trx_rme_record_versions')->where('id', $id)->value('kind'))->toBe('final');
});

// ─── Baseline for a record finalized before rule 240 ─────────────────────────

it('writes a baseline version 1, once, for a sheet finalized before this rule, only inside a transaction', function () {
    // A sheet finalized under the old code: status only, no version, and pages
    // without a seal or an authoring stamp, written before the finalize.
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id]);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'notes' => 'm33v catatan dokter',
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now(),
    ]);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'created_at' => now()->subHour(),
    ]);
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'page_number' => 2,
        'created_at' => now()->subHour(),
    ]);
    $service = app(RmeRecordVersionService::class);

    // Outside a transaction: refused before anything is read. RefreshDatabase wraps
    // the test in a transaction on the default connection; a second connection has
    // none, which is what a caller outside the amendment transaction presents.
    $fresh = $sheet->fresh();
    $default = config('database.default');
    config()->set('database.connections.m33v_plain', config('database.connections.'.$default));
    config()->set('database.default', 'm33v_plain');
    DB::purge('m33v_plain');
    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => $service->ensureBaseline($fresh, $this->author->id))->toThrow(LogicException::class);
    } finally {
        config()->set('database.default', $default);
        DB::purge('m33v_plain');
    }

    $baseline = DB::transaction(fn () => $service->ensureBaseline($sheet->fresh(), $this->author->id));
    $again = DB::transaction(fn () => $service->ensureBaseline($sheet->fresh(), $this->author->id));

    expect($baseline->version)->toBe(1)
        ->and($baseline->kind)->toBe(RmeRecordVersion::KIND_BASELINE)
        ->and($again->id)->toBe($baseline->id)
        ->and(m33vVersions($sheet))->toHaveCount(1)
        ->and($baseline->snapshot['fields']['notes'])->toBe('m33v catatan dokter')
        // Pages from before rule 240 that the finalize covers are recorded too.
        ->and(collect($baseline->snapshot['pages'])->pluck('page_number')->sort()->values()->all())->toBe([1, 2])
        ->and($service->verifyChain($sheet->fresh()))->toBeTrue();
});

it('records in a baseline the pages another encounter wrote, and not the amending encounter\'s own unsealed page (rule 240 §4.4)', function () {
    $earlier = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id]);
    rmeActiveConsentedEncounter($visit);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $earlier->id,
        'branch_id' => $earlier->branch_id,
        'patient_id' => $this->patient->id,
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subDay(),
    ]);
    // Page 1 written by the earlier encounter, never sealed; page 2 by the live one.
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $earlier->id,
        'branch_id' => $earlier->branch_id,
        'authored_clinic_visit_id' => $earlier->id,
    ]);
    MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $earlier->id,
        'branch_id' => $earlier->branch_id,
        'page_number' => 2,
        'authored_clinic_visit_id' => $visit->id,
    ]);

    $baseline = DB::transaction(fn () => app(RmeRecordVersionService::class)
        ->ensureBaseline($sheet->fresh(), $this->author->id, $visit->id));

    expect(collect($baseline->snapshot['pages'])->pluck('page_number')->all())->toBe([1]);
});

it('refuses a baseline for a sheet that is not final', function () {
    [, $sheet] = m33vSheet();

    expect(fn () => DB::transaction(fn () => app(RmeRecordVersionService::class)->ensureBaseline($sheet->fresh(), $this->author->id)))
        ->toThrow(LogicException::class);
    expect(m33vVersions($sheet))->toHaveCount(0);
});

// ─── Reading versions ────────────────────────────────────────────────────────

it('lists and fetches versions for an account that may read the record, and refuses one that may not', function () {
    [, $sheet] = m33vSheet();
    m33vFinalize($sheet);
    $service = app(RmeRecordVersionService::class);

    expect($service->versionsFor($this->author, $sheet->fresh())->pluck('version')->all())->toBe([1])
        ->and($service->version($this->author, $sheet->fresh(), 1)?->kind)->toBe(RmeRecordVersion::KIND_FINAL)
        ->and($service->version($this->author, $sheet->fresh(), 2))->toBeNull()
        // The print marker data (PR5 renders "Diamandemen n×"): no amendment yet.
        ->and($service->amendmentCount($sheet->fresh()))->toBe(0);

    $outsider = doctorWithOnlineContext($this->branch);
    expect(fn () => $service->versionsFor($outsider, $sheet->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->version($outsider, $sheet->fresh(), 1))->toThrow(AuthorizationException::class);
});

// ─── No clinical content in logs or audit ────────────────────────────────────

it('writes no snapshot content into any log message or audit row', function () {
    [, $sheet] = m33vSheet();
    $key = MedicalRecordHandwriting::query()->where('medical_record_id', $sheet->id)->value('handwriting_path');

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    m33vFinalize($sheet);

    foreach (['m33v catatan dokter', 'm33v keluhan nyeri gigi 36', $key] as $needle) {
        expect(collect($logged)->contains(fn (string $line) => str_contains($line, $needle)))->toBeFalse();
        expect(DB::table('sys_audit_logs')->get()->contains(fn ($row) => str_contains(json_encode($row), $needle)))->toBeFalse();
    }
});

// ─── Lock order (rule 223 §1 item 6) ─────────────────────────────────────────

it('writes the version after the visit and sheet locks, inside the finalize transaction (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    [, $sheet] = m33vSheet();
    $events = [];
    DB::listen(function ($query) use (&$events) {
        $sql = strtolower(ltrim($query->sql));
        if (preg_match('/for (no key )?update/', $sql)) {
            $events[] = str_contains($sql, 'trx_clinic_visits') ? 'visit' : (str_contains($sql, 'trx_medical_records') ? 'sheet' : 'other');
        } elseif (str_starts_with($sql, 'insert into "trx_rme_record_versions"')) {
            $events[] = 'version';
        }
    });

    m33vFinalize($sheet);

    $visitAt = array_search('visit', $events, true);
    $sheetAt = array_search('sheet', $events, true);
    $versionAt = array_search('version', $events, true);

    expect($visitAt)->not->toBeFalse()
        ->and($sheetAt)->not->toBeFalse()
        ->and($versionAt)->not->toBeFalse()
        ->and($visitAt)->toBeLessThan($sheetAt)
        ->and($sheetAt)->toBeLessThan($versionAt);
});
