<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-7 — a concurrent first write adopts the winner
|--------------------------------------------------------------------------
|
| R5-1  PatientDoctorAssignmentService caught the unique violation of a racing
|       duplicate assignment and then queried again INSIDE the same PostgreSQL
|       transaction. PostgreSQL aborts the whole transaction on any failed
|       statement, so that query answered 25P02 and the room assignment that
|       triggered it answered HTTP 500.
| R6-1  Two simultaneous FIRST handwriting saves for a patient with no book both
|       found "no record" and both inserted a draft on the canonical visit. The
|       loser hit UNIQUE(clinic_visit_id) and answered HTTP 500; if the winner
|       committed a moment earlier, the loser was refused instead ("Rekam medis
|       sudah ada") and the doctor's page was not saved.
|
| HOW THE RACE IS REPRODUCED. A real concurrent request is two transactions; the
| loser's insert waits on the winner's uncommitted key and then fails with 23505
| the moment the winner commits. Exactly that database state — "a conflicting
| live row now exists that my earlier check did not see" — is produced here by
| inserting the winner's row at the precise point between the loser's check and
| its insert. That is deterministic (no timing), and it lets every fixture use
| the ordinary seeded test data.
|
| The R5-1 race needs PostgreSQL twice over: the active-assignment unique index
| is PARTIAL (WHERE unassigned_at IS NULL) and its migration creates it only on
| pgsql, and only PostgreSQL aborts a transaction on a failed statement (25P02).
| On SQLite the loser's insert simply succeeds, so those three tests skip there
| (p7RequiresPartialAssignmentIndex). PostgreSQL is the driver of CI's critical
| gate and of production. Every other property here holds on both drivers.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Repositories\MedicalRecordRepository;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RME\Services\PatientDoctorAssignmentService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Storage::fake('clinical_evidence');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->rmeBranch = Branch::factory()->create([
        'code' => 'ATG3',
        'name' => 'Cabang Antang',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
    $this->manager = rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']);
    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['name' => 'drg. Serentak']);
});

// --------------------------------------------------------------------------
// Fixtures
// --------------------------------------------------------------------------

/** The R5-1 race exists only where the partial unique index exists (pgsql). */
function p7RequiresPartialAssignmentIndex(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('The active-assignment unique index is created only on PostgreSQL; without it there is no R5-1 race to adopt.');
    }
}

/**
 * Insert a competing ACTIVE assignment for this patient and doctor the moment
 * the service's existence check returns, i.e. after it decided "none yet" and
 * before it inserts. That is the state a concurrent room assignment leaves.
 *
 * @return object{id: ?int} the rival row's id, filled once it is inserted
 */
function p7RivalAssignmentAfterCheck(int $patientId, int $doctorId, int $branchId): object
{
    $rival = (object) ['id' => null];

    DB::listen(function (QueryExecuted $query) use ($rival, $patientId, $doctorId, $branchId): void {
        $sql = strtolower(ltrim($query->sql));

        if ($rival->id !== null
            || ! str_starts_with($sql, 'select')
            || ! str_contains($sql, 'trx_rme_patient_doctor_assignments')
            || ! str_contains($sql, 'unassigned_at')) {
            return;
        }

        // Mark first: the insert below fires this listener again.
        $rival->id = 0;
        $rival->id = (int) DB::table('trx_rme_patient_doctor_assignments')->insertGetId([
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'branch_id' => $branchId,
            'assigned_at' => now(),
            'assignment_type' => PatientDoctorAssignment::TYPE_AUTO_VISIT,
            'notes' => 'rival concurrent assignment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $rival;
}

function p7ActiveAssignmentCount(int $patientId, int $doctorId): int
{
    return PatientDoctorAssignment::query()
        ->where('patient_id', $patientId)
        ->where('doctor_id', $doctorId)
        ->whereNull('unassigned_at')
        ->count();
}

/**
 * Replace the medical-record repository with one that inserts a competing
 * draft for `$visitId` right after the `$onCall`-th lookup for that visit has
 * returned "none". Call 1 is the book lookup, call 2 is the draft's own
 * existence check; the insert comes after call 2.
 *
 * @return object{id: ?int} the rival draft's id, filled once it is inserted
 */
function p7RivalDraftAfterLookup(ClinicVisit $visit, int $onCall): object
{
    $rival = (object) ['id' => null];

    app()->instance(MedicalRecordRepositoryInterface::class, new class($visit, $onCall, $rival) extends MedicalRecordRepository
    {
        private int $calls = 0;

        public function __construct(private ClinicVisit $visit, private int $onCall, private object $rival) {}

        public function findByVisitId(int $clinicVisitId): ?MedicalRecord
        {
            $found = parent::findByVisitId($clinicVisitId);

            if ($clinicVisitId === (int) $this->visit->id
                && ++$this->calls === $this->onCall
                && $found === null) {
                $this->rival->id = (int) DB::table('trx_medical_records')->insertGetId([
                    'clinic_visit_id' => $this->visit->id,
                    'canonical_visit_id' => $this->visit->id,
                    'source_visit_id' => $this->visit->id,
                    'sheet_number' => 1,
                    'branch_id' => $this->visit->branch_id,
                    'patient_id' => $this->visit->patient_id,
                    'doctor_id' => $this->visit->doctor_id,
                    'status' => MedicalRecord::STATUS_DRAFT,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $found;
        }
    });

    return $rival;
}

/** A patient with no medical record yet, examined right now under consent. */
function p7FirstSaveVisit(object $test): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $test->branch->id,
        'clinic_id' => $test->clinic->id,
        'patient_id' => Patient::factory()->create()->id,
        'doctor_id' => $test->doctor->id,
        'created_by' => $test->manager->id,
        'queue_number' => 1,
    ]);

    return rmeActiveConsentedEncounter($visit);
}

/** A record of ANOTHER patient: the route only needs a model to bind. */
function p7UnrelatedRecord(object $test): MedicalRecord
{
    $other = ClinicVisit::factory()->create([
        'branch_id' => $test->branch->id,
        'clinic_id' => $test->clinic->id,
        'patient_id' => Patient::factory()->create()->id,
        'doctor_id' => $test->doctor->id,
        'created_by' => $test->manager->id,
        'queue_number' => 2,
    ]);

    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $other->id,
        'branch_id' => $test->branch->id,
        'patient_id' => $other->patient_id,
        'doctor_id' => $test->doctor->id,
    ]);
}

/** A small, opaque, non-uniform RGBA PNG built in pure PHP (no GD). */
function p7Canvas(int $seed): string
{
    $raw = '';

    for ($y = 0; $y < 4; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < 4; $x++) {
            $raw .= chr(($seed * 37 + $x * 50) % 256)
                .chr(($seed * 11 + $y * 60) % 256)
                .chr(($seed * 5 + ($x + $y) * 30) % 256)
                .chr(255);
        }
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 4, 4, 8, 6, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress($raw))
        .$chunk('IEND', ''));
}

// --------------------------------------------------------------------------
// R5-1 — a concurrent duplicate doctor assignment is adopted, not fatal
// --------------------------------------------------------------------------

it('adopts a concurrent auto-assignment instead of aborting the transaction that triggered it (R5-1)', function () {
    p7RequiresPartialAssignmentIndex();

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $this->rmeBranch->id])->id,
        'doctor_id' => $this->doctor->id,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $rival = p7RivalAssignmentAfterCheck($visit->patient_id, $this->doctor->id, $this->rmeBranch->id);

    $result = DB::transaction(function () use ($visit) {
        $assignment = app(PatientDoctorAssignmentService::class)->ensureAssignedFromVisit($visit, $this->manager);

        // The surrounding transaction must still be usable: on PostgreSQL the
        // old catch-and-requery left it aborted (25P02) at exactly this point.
        expect(DB::table('trx_clinic_visits')->where('id', $visit->id)->exists())->toBeTrue();

        return $assignment;
    });

    expect($rival->id)->toBeGreaterThan(0)
        ->and($result?->getKey())->toBe($rival->id)
        ->and(p7ActiveAssignmentCount($visit->patient_id, $this->doctor->id))->toBe(1);
});

it('adopts a concurrent assignment on the manual assign path too (R5-1, createActiveAssignment)', function () {
    p7RequiresPartialAssignmentIndex();

    $patient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);

    $rival = p7RivalAssignmentAfterCheck($patient->id, $this->doctor->id, $this->rmeBranch->id);

    $assignment = app(PatientDoctorAssignmentService::class)
        ->assignPatientToDoctor($patient, $this->doctor, $this->manager, $this->rmeBranch);

    expect($assignment->getKey())->toBe($rival->id)
        ->and(p7ActiveAssignmentCount($patient->id, $this->doctor->id))->toBe(1)
        ->and(DB::table('mst_patients')->where('id', $patient->id)->exists())->toBeTrue();
});

it('still assigns the room when a concurrent assignment of the same doctor commits first (R5-1, end to end)', function () {
    p7RequiresPartialAssignmentIndex();

    $room = ClinicRoom::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    rmeMakeDoctorOnline($this->doctor, $this->rmeBranch, $room);

    $patient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $patient->id,
        'doctor_id' => null,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $rival = p7RivalAssignmentAfterCheck($patient->id, $this->doctor->id, $this->rmeBranch->id);

    $this->actingAs(rmeAdminClinicUser($this->rmeBranch))
        ->from(route('rme.visits.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $room->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The rival must really have been injected (else this proves nothing), and
    // the one active assignment must be the rival's, not a second insert.
    expect($rival->id)->toBeGreaterThan(0)
        ->and($visit->refresh()->clinic_room_id)->toBe($room->id)
        ->and($visit->doctor_id)->toBe($this->doctor->id)
        ->and(p7ActiveAssignmentCount($patient->id, $this->doctor->id))->toBe(1)
        ->and(PatientDoctorAssignment::query()->where('patient_id', $patient->id)->whereNull('unassigned_at')->value('id'))->toBe($rival->id);
});

it('never reports a failure that is not the duplicate race as a successful assignment (R5-1)', function () {
    // A doctor id that does not exist: the insert fails on its foreign key, not
    // on the active-assignment index. That is not a race to adopt, so it must
    // surface; the old catch-all returned NULL as if nothing had gone wrong.
    $visit = new ClinicVisit([
        'patient_id' => Patient::factory()->create()->id,
        'doctor_id' => 99_999_999,
        'branch_id' => $this->rmeBranch->id,
    ]);
    $visit->id = 99_999_998;

    expect(fn () => app(PatientDoctorAssignmentService::class)->ensureAssignedFromVisit($visit, $this->manager))
        ->toThrow(QueryException::class);
});

// --------------------------------------------------------------------------
// R6-1 — a concurrent FIRST handwriting save writes into the winner's book
// --------------------------------------------------------------------------

it('writes the page into the book a concurrent first save just created (R6-1)', function (int $injectOnLookup) {
    $visit = p7FirstSaveVisit($this);
    $routeRecord = p7UnrelatedRecord($this);

    $rival = p7RivalDraftAfterLookup($visit, $injectOnLookup);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $routeRecord]), [
            'handwriting_data' => p7Canvas(7),
            'page_number' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $books = MedicalRecord::query()->where('clinic_visit_id', $visit->id)->get();
    $page = MedicalRecordHandwriting::query()->where('medical_record_id', $rival->id)->first();

    expect($rival->id)->toBeGreaterThan(0)
        ->and($books)->toHaveCount(1)
        ->and($books->first()->id)->toBe($rival->id)
        ->and($page)->not->toBeNull()
        ->and(ClinicalEvidenceStorage::disk()->exists($page->handwriting_path))->toBeTrue();
})->with([
    'the winner committed between the book lookup and the draft check' => [1],
    'the winner committed between the draft check and the insert' => [2],
]);

it('answers a racing duplicate sheet with the existing refusal, not an HTTP 500 (R6-1, createDraft)', function () {
    $visit = p7FirstSaveVisit($this);
    $this->actingAs($this->manager);

    p7RivalDraftAfterLookup($visit, 1);

    try {
        app(MedicalRecordService::class)->createDraft($visit, $this->manager->id);
        $this->fail('A second draft for the same visit must be refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toHaveKey('clinic_visit_id')
            ->and($refusal->errors()['clinic_visit_id'][0])->toBe('Rekam medis sudah ada untuk kunjungan ini.');
    } catch (UniqueConstraintViolationException $raced) {
        $this->fail('The race surfaced as a raw unique violation (HTTP 500): '.$raced->getMessage());
    }
});

// --------------------------------------------------------------------------
// Sequential behaviour the fixes must not change
// --------------------------------------------------------------------------

it('numbers consecutive registrations at one branch 1, 2 with matching visit numbers', function () {
    rmeMakeDoctorOnline($this->doctor, $this->rmeBranch);
    $this->actingAs($this->manager);

    $register = fn (): ClinicVisit => app(ClinicVisitService::class)->create([
        'patient_mode' => 'existing',
        'patient_id' => Patient::factory()->create(['branch_id' => $this->rmeBranch->id])->id,
        'branch_id' => $this->rmeBranch->id,
        'doctor_id' => $this->doctor->id,
        'clinic_id' => null,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
    ], $this->manager);

    $first = $register();
    $second = $register();

    expect($first->queue_number)->toBe(1)
        ->and($second->queue_number)->toBe(2)
        ->and($first->visit_number)->toEndWith('-001')
        ->and($second->visit_number)->toEndWith('-002');
});

it('numbers past a soft-deleted visit, because the unique indexes still hold its numbers (R5-2)', function () {
    // Both unique indexes on trx_clinic_visits (visit_number; branch_id +
    // visit_date + queue_number) are full indexes: a soft-deleted visit keeps
    // its numbers. A MAX that skips it hands the next registration a number
    // the index refuses, an HTTP 500 with no concurrency at all.
    rmeMakeDoctorOnline($this->doctor, $this->rmeBranch);
    $this->actingAs($this->manager);

    $register = fn (): ClinicVisit => app(ClinicVisitService::class)->create([
        'patient_mode' => 'existing',
        'patient_id' => Patient::factory()->create(['branch_id' => $this->rmeBranch->id])->id,
        'branch_id' => $this->rmeBranch->id,
        'doctor_id' => $this->doctor->id,
        'clinic_id' => null,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
    ], $this->manager);

    $register();
    $removed = $register();
    $removed->delete();

    $next = $register();

    expect(ClinicVisit::withTrashed()->whereKey($removed->id)->first()?->trashed())->toBeTrue()
        ->and($next->queue_number)->toBe(3)
        ->and($next->visit_number)->toEndWith('-003');
});
