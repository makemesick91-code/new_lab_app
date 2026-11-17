<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A3b (rule 243 §2.11)
|--------------------------------------------------------------------------
| The list surfaces outside the workspace, with `rme.doctor_encounter_scope` ON:
|  - the unified RM pages (/rme/medical-records): the latest native record of
|    each patient row and the patient page's native records list only records
|    whose visit the doctor may read (the visit scope's SQL twin);
|  - the prescription history beside the prescription form lists only
|    prescriptions whose visit the doctor may read (same SQL twin, before the
|    20-row limit);
|  - the visit page's follow-up links ("Kontrol dari", "Kontrol lanjutan") and
|    the odontogram page's parent card ("Odontogram Kunjungan Sebelumnya")
|    name only visits the doctor may open.
| With the flag OFF every surface is exactly as before (parity tests below).
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpls` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const DPLS_FLAG = 'rme.doctor_encounter_scope';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');

    test()->dplsBranch = Branch::factory()->create(['code' => 'LS01', 'is_rme_enabled' => true]);
    $roomA = ClinicRoom::factory()->create(['branch_id' => test()->dplsBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => test()->dplsBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    test()->dplsRoom = $roomA;
    $clinic = Clinic::factory()->create();

    // B's visits are created in A's room so the rule 138 room scope admits them
    // from A's side and only the encounter scope decides.
    foreach (['A' => $roomA, 'B' => $roomB] as $name => $room) {
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dplsBranch->id, 'name' => 'drg. Dokter '.$name.' Daftar']);
        $user = User::factory()->create()->assignRole('Doctor');
        $doctor->update(['user_id' => $user->id]);
        $doctor->branches()->sync([(int) test()->dplsBranch->id]);
        rmeMakeDoctorOnline($doctor, test()->dplsBranch, $room, $user);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

        test()->{'dplsDoctor'.$name} = $doctor->refresh();
        test()->{'dplsUser'.$name} = $user->refresh();
    }

    test()->dplsPatient = Patient::factory()->create(['branch_id' => test()->dplsBranch->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function dplsFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPLS_FLAG]['default'] = $on;
    $flags[DPLS_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function dplsVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dplsBranch->id,
        'patient_id' => test()->dplsPatient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->dplsRoom->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function dplsCompleted(Doctor $doctor, string $completedAtUtc, array $overrides = []): ClinicVisit
{
    return dplsVisit($doctor, ClinicVisit::STATUS_COMPLETED, array_merge([
        'completed_at' => $completedAtUtc,
        'visit_date' => substr($completedAtUtc, 0, 10),
    ], $overrides));
}

function dplsSheet(ClinicVisit $visit, ?MedicalRecord $book = null): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'canonical_visit_id' => $book?->clinic_visit_id ?? $visit->id,
    ])->refresh();
}

function dplsPrescription(ClinicVisit $visit): RmePrescription
{
    return RmePrescription::factory()->withStoredCanvases()->unsigned()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'prescribed_by_name' => 'Penulis Resep #'.$visit->id.'#',
    ]);
}

/** @return list<int> */
function dplsIds(iterable $models): array
{
    return collect($models)->map(fn ($model) => (int) $model->id)->sort()->values()->all();
}

/**
 * The book on B's first visit, B's sheet last month, A's in-service encounter
 * with its own sheet, and — created LAST, so it is the newest record — B's
 * sheet on a PRE-EXAM visit today ($bStatus).
 */
function dplsRecordFixture(string $bStatus = ClinicVisit::STATUS_IN_PROGRESS): array
{
    $bookVisit = dplsCompleted(test()->dplsDoctorB, '2025-01-01 03:00:00');
    $book = dplsSheet($bookVisit);
    $bOld = dplsCompleted(test()->dplsDoctorB, '2026-10-01 03:00:00');
    $bOldSheet = dplsSheet($bOld, $book);
    $aVisit = dplsVisit(test()->dplsDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $aSheet = dplsSheet($aVisit, $book);
    $bLive = dplsVisit(test()->dplsDoctorB, $bStatus);
    $bLiveSheet = dplsSheet($bLive, $book);

    return compact('bookVisit', 'book', 'bOld', 'bOldSheet', 'aVisit', 'aSheet', 'bLive', 'bLiveSheet');
}

/*
|--------------------------------------------------------------------------
| 1 — the unified RM pages
|--------------------------------------------------------------------------
*/

it('shows on the unified index the newest record the doctor may read, never another doctor\'s pre-exam one', function (string $bStatus) {
    dplsFlag(true);
    $f = dplsRecordFixture($bStatus);

    $response = $this->actingAs(test()->dplsUserA)->get(route('rme.medical-records.index'))->assertOk();
    $latest = $response->viewData('latestNative')->get(test()->dplsPatient->id);

    expect($latest)->not->toBeNull()
        ->and((int) $latest->id)->toBe((int) $f['aSheet']->id)
        ->and($response->getContent())->not->toContain($f['bLive']->visit_number);
})->with([ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS]);

it('lists on the unified patient page only the native records whose visit the doctor may read', function () {
    dplsFlag(true);
    $f = dplsRecordFixture();

    $response = $this->actingAs(test()->dplsUserA)
        ->get(route('rme.medical-records.patients.show', test()->dplsPatient->id))
        ->assertOk();

    expect(dplsIds($response->viewData('nativeRecords')))->toBe(dplsIds([$f['book'], $f['bOldSheet'], $f['aSheet']]))
        ->and($response->getContent())->not->toContain($f['bLive']->visit_number);
});

it('neither counts nor matches on the unified index a sheet the doctor may not read', function (bool $on) {
    dplsFlag($on);
    $f = dplsRecordFixture();

    $index = $this->actingAs(test()->dplsUserA)->get(route('rme.medical-records.index'))->assertOk();
    $row = collect($index->viewData('patients')->items())->firstWhere('id', test()->dplsPatient->id);
    $search = $this->actingAs(test()->dplsUserA)
        ->get(route('rme.medical-records.index', ['search' => $f['bLive']->visit_number]))
        ->assertOk();
    $found = collect($search->viewData('patients')->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

    // Flag ON: book, B's old sheet and A's sheet; flag OFF: B's live sheet too.
    expect((int) $row->native_count)->toBe($on ? 3 : 4)
        ->and(in_array((int) test()->dplsPatient->id, $found, true))->toBe(! $on);
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('keeps both unified pages whole with the flag OFF and for Supervisor RME', function (bool $on) {
    dplsFlag($on);
    $f = dplsRecordFixture();
    $viewer = $on ? rmeClinicalAuthor() : test()->dplsUserA;

    $index = $this->actingAs($viewer)->get(route('rme.medical-records.index'))->assertOk();
    $page = $this->actingAs($viewer)->get(route('rme.medical-records.patients.show', test()->dplsPatient->id))->assertOk();

    expect((int) $index->viewData('latestNative')->get(test()->dplsPatient->id)?->id)->toBe((int) $f['bLiveSheet']->id)
        ->and(dplsIds($page->viewData('nativeRecords')))->toBe(dplsIds([$f['book'], $f['bOldSheet'], $f['aSheet'], $f['bLiveSheet']]))
        ->and($page->getContent())->toContain($f['bLive']->visit_number);
})->with(['flag OFF, doctor' => [false], 'flag ON, Supervisor RME' => [true]]);

it('scopes the unified index latest record in SQL, never per row', function () {
    dplsFlag(true);
    $measure = function (): int {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (str_contains($query->sql, '"trx_medical_records"')) {
                $count++;
            }
        });
        $this->actingAs(test()->dplsUserA)->get(route('rme.medical-records.index'))->assertOk();

        return $count;
    };

    dplsRecordFixture();
    $small = $measure();

    foreach (range(1, 4) as $i) {
        test()->dplsPatient = Patient::factory()->create(['branch_id' => test()->dplsBranch->id]);
        dplsRecordFixture();
    }
    app()->forgetScopedInstances();

    expect($measure())->toBe($small);
});

/*
|--------------------------------------------------------------------------
| 2 — the prescription history
|--------------------------------------------------------------------------
*/

it('lists in the prescription history only prescriptions whose visit the doctor may read', function (string $mode) {
    dplsFlag(true);
    $bOld = dplsCompleted(test()->dplsDoctorB, '2026-10-01 03:00:00');
    $bOldRx = dplsPrescription($bOld);
    $bLive = dplsVisit(test()->dplsDoctorB, ClinicVisit::STATUS_IN_PROGRESS);
    $bLiveRx = dplsPrescription($bLive);
    $ownOld = dplsCompleted(test()->dplsDoctorA, '2025-06-01 03:00:00');
    $ownOldRx = dplsPrescription($ownOld);
    $page = $mode === 'history'
        ? dplsVisit(test()->dplsDoctorA, ClinicVisit::STATUS_CASHIER_PENDING)
        : dplsCompleted(test()->dplsDoctorA, '2025-07-01 03:00:00');

    $response = $this->actingAs(test()->dplsUserA)->get(route('rme.visits.prescription.show', $page))->assertOk();

    $expected = $mode === 'history' ? [$bOldRx, $ownOldRx] : [$ownOldRx];

    expect(dplsIds($response->viewData('history')))->toBe(dplsIds($expected))
        ->and($response->getContent())->not->toContain($bLive->visit_number)
        ->and($response->getContent())->not->toContain($bLiveRx->prescribed_by_name);
})->with(['history', 'own']);

it('keeps the prescription history whole with the flag OFF', function () {
    dplsFlag(false);
    $bOldRx = dplsPrescription(dplsCompleted(test()->dplsDoctorB, '2026-10-01 03:00:00'));
    $bLiveRx = dplsPrescription(dplsVisit(test()->dplsDoctorB, ClinicVisit::STATUS_IN_PROGRESS));
    $page = dplsCompleted(test()->dplsDoctorA, '2025-07-01 03:00:00');

    $response = $this->actingAs(test()->dplsUserA)->get(route('rme.visits.prescription.show', $page))->assertOk();

    expect(dplsIds($response->viewData('history')))->toBe(dplsIds([$bOldRx, $bLiveRx]));
});

/*
|--------------------------------------------------------------------------
| 3 — follow-up links on the visit page, the odontogram page's parent card
|--------------------------------------------------------------------------
*/

/** A's own old visit is a control of B's first visit and has a control of its own by B, still waiting. */
function dplsFollowUpFixture(): array
{
    $parent = dplsCompleted(test()->dplsDoctorB, '2025-01-01 03:00:00');
    Odontogram::factory()->create(['clinic_visit_id' => $parent->id, 'branch_id' => $parent->branch_id]);
    $own = dplsCompleted(test()->dplsDoctorA, '2025-06-01 03:00:00', [
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
    ]);
    $child = dplsVisit(test()->dplsDoctorB, ClinicVisit::STATUS_WAITING, [
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $own->id,
    ]);

    return compact('parent', 'own', 'child');
}

it('names on the visit page only the follow-up visits the doctor may open', function (string $mode) {
    dplsFlag(true);
    $f = dplsFollowUpFixture();
    if ($mode === 'history') {
        dplsVisit(test()->dplsDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    }

    $html = $this->actingAs(test()->dplsUserA)->get(route('rme.visits.show', $f['own']))->assertOk()->getContent();

    // History mode reads B's completed parent; nobody reads B's waiting control.
    expect(str_contains($html, route('rme.visits.show', $f['parent'])))->toBe($mode === 'history')
        ->and($html)->not->toContain(route('rme.visits.show', $f['child']))
        ->and($html)->not->toContain($f['child']->visit_number);

    if ($mode === 'own') {
        expect($html)->not->toContain($f['parent']->visit_number)
            ->and($html)->not->toContain('Kontrol dari');
    }
})->with(['own', 'history']);

it('shows the odontogram page\'s parent card only when the doctor may open the parent visit', function (string $mode) {
    dplsFlag(true);
    $f = dplsFollowUpFixture();
    if ($mode === 'history') {
        dplsVisit(test()->dplsDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    }

    $html = $this->actingAs(test()->dplsUserA)->get(route('rme.visits.odontogram.show', $f['own']))->assertOk()->getContent();

    expect(str_contains($html, 'Odontogram Kunjungan Sebelumnya'))->toBe($mode === 'history')
        ->and(str_contains($html, $f['parent']->visit_number))->toBe($mode === 'history');
})->with(['own', 'history']);

it('keeps the follow-up links and the parent card exactly as before with the flag OFF', function () {
    dplsFlag(false);
    $f = dplsFollowUpFixture();

    $visitPage = $this->actingAs(test()->dplsUserA)->get(route('rme.visits.show', $f['own']))->assertOk()->getContent();
    $chartPage = $this->actingAs(test()->dplsUserA)->get(route('rme.visits.odontogram.show', $f['own']))->assertOk()->getContent();

    expect($visitPage)->toContain(route('rme.visits.show', $f['parent']))
        ->and($visitPage)->toContain(route('rme.visits.show', $f['child']))
        ->and($chartPage)->toContain('Odontogram Kunjungan Sebelumnya');
});

it('keeps Supervisor RME exempt on the follow-up links', function () {
    dplsFlag(true);
    $f = dplsFollowUpFixture();

    $html = $this->actingAs(rmeClinicalAuthor())->get(route('rme.visits.show', $f['own']))->assertOk()->getContent();

    expect($html)->toContain(route('rme.visits.show', $f['parent']))
        ->and($html)->toContain(route('rme.visits.show', $f['child']));
});
