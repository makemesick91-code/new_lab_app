<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A2 (rule 243 §2.10)
|--------------------------------------------------------------------------
| The published LEGACY ODONTOGRAM archive under the doctor encounter scope
| (`rme.doctor_encounter_scope`, default OFF, FeatureFlagService only).
|
| Flag ON, for a Doctor (Owner, Super Admin and Supervisor RME exempt): read
| only while the doctor has an own in-service encounter with the patient (read
| mode `history`, DoctorPatientScopeService::readModeFor()); otherwise 404 on
| the viewer, the page-image route and the source door (before the rule 243 §1
| download refusal, which stays: 403 inside the scope, plan default 4).
| Assignment rows and the doctor's own old visits grant nothing. The history
| list and the legacy odontogram lookup mirror the per-record answer; the
| controller re-asserts the scope past a bypassed policy.
|
| Flag OFF: every read answers exactly as today. Non-doctor and exempt readers
| and the branch rules are unchanged.
|
| Selected by the existing `LegacyOdontogram` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19).
| Helper prefix `lodes` is unique.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

uses()->group('LegacyOdontogram');

const LODES_FLAG = 'rme.doctor_encounter_scope';

const LODES_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function () {
    // 10:00 WITA on 2026-11-17 (02:00 UTC).
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC'));

    seedAccessControl();
    Storage::fake('legacy_odontogram_private');

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    [$this->doctorA, $this->userA] = lodesDoctor($this->branch);
    [$this->doctorB, $this->userB] = lodesDoctor($this->branch);

    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->archive = lodesArchive($this->patient, $this->branch, 'a');

    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function lodesFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[LODES_FLAG]['default'] = $on;
    $flags[LODES_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

/** @return array{0: Doctor, 1: User} */
function lodesDoctor(Branch $practice): array
{
    $user = User::factory()->create(['branch_id' => null]);
    $user->assignRole('Doctor');
    // The Doctor role does not carry the odontogram clinical-read permission;
    // granted so the door is reachable and only the scope decides.
    $user->givePermissionTo('view_legacy_odontogram_archive');

    $doctor = Doctor::factory()->withAllowedBranches([$practice])->create([
        'user_id' => $user->id,
        'branch_id' => $practice->id,
        'is_active' => true,
    ]);

    return [$doctor->refresh(), $user->refresh()];
}

function lodesArchive(Patient $patient, Branch $branch, string $tag): LegacyOdontogramRecord
{
    $record = LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'odontogram_date' => '2018-02-03',
        'source_disk' => 'legacy_odontogram_private',
        'source_pdf_path' => "lodes/{$tag}/source.pdf",
        'page_count' => 1,
        'status' => LegacyOdontogramRecordStatus::PUBLISHED,
    ]);

    LegacyOdontogramRecordPage::query()->create([
        'odontogram_legacy_record_id' => $record->id,
        'page_number' => 1,
        'width' => 1,
        'height' => 1,
        'dpi' => 200,
        'rotation' => 0,
        'image_disk' => 'legacy_odontogram_private',
        'image_path' => "lodes/{$tag}/page-1.png",
        'image_sha256' => str_repeat('c', 64),
    ]);

    Storage::disk('legacy_odontogram_private')->put("lodes/{$tag}/source.pdf", legacyRmePdfBytes(1));
    Storage::disk('legacy_odontogram_private')->put("lodes/{$tag}/page-1.png", base64_decode(LODES_PNG));

    return $record->refresh();
}

function lodesVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function lodesArrange(string $scenario): void
{
    $a = test()->doctorA;

    match ($scenario) {
        'own encounter in progress' => lodesVisit($a, ClinicVisit::STATUS_IN_PROGRESS),
        'own encounter cashier pending' => lodesVisit($a, ClinicVisit::STATUS_CASHIER_PENDING),
        'own visit completed yesterday' => lodesVisit($a, ClinicVisit::STATUS_COMPLETED, ['completed_at' => '2026-11-16 03:00:00', 'visit_date' => '2026-11-16']),
        'manual assignment only' => PatientDoctorAssignment::factory()->create([
            'patient_id' => test()->patient->id,
            'doctor_id' => $a->id,
            'assignment_type' => PatientDoctorAssignment::TYPE_MANUAL,
            'unassigned_at' => null,
        ]),
        'another doctor in service' => lodesVisit(test()->doctorB, ClinicVisit::STATUS_IN_PROGRESS),
        'no relationship' => null,
    };
}

function lodesStatus(User $user, string $route, LegacyOdontogramRecord $record): int
{
    $parameters = $route === 'rme.legacy-odontograms.pages.show' ? [$record->id, 1] : [$record->id];

    return test()->actingAs($user)->get(route($route, $parameters))->getStatusCode();
}

/** @return array<string, bool> asked before any HTTP request */
function lodesListsSee(User $user, Patient $patient): array
{
    return [
        'history service' => app(LegacyOdontogramPatientHistoryService::class)->hasLegacyOdontograms($user, (int) $patient->id),
        'legacy lookup' => app(LegacyOdontogramPatientRepositoryInterface::class)->findSelectableById($user, (int) $patient->id) !== null,
    ];
}

it('reads the archive and its page image inside an own in-service encounter, and keeps the source refused (flag ON)', function (string $scenario) {
    lodesFlag(true);
    lodesArrange($scenario);

    expect(lodesStatus($this->userA, 'rme.legacy-odontograms.show', $this->archive))->toBe(200);

    $this->actingAs($this->userA)->get(route('rme.legacy-odontograms.pages.show', [$this->archive->id, 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Rule 243 §1 / plan default 4: the source PDF stays refused, audited.
    expect(lodesStatus($this->userA, 'rme.legacy-odontograms.source', $this->archive))->toBe(403)
        ->and(DB::table('sys_audit_logs')->where('action', 'DOCTOR_PRINT_LEGACY_ODONTOGRAM_REJECTED')->count())->toBe(1);
})->with(['own encounter in progress', 'own encounter cashier pending']);

it('answers 404 on the viewer, the page image and the source outside the scope (flag ON)', function (string $scenario) {
    lodesFlag(true);
    lodesArrange($scenario);

    foreach (['rme.legacy-odontograms.show', 'rme.legacy-odontograms.pages.show', 'rme.legacy-odontograms.source'] as $route) {
        expect(lodesStatus($this->userA, $route, $this->archive))->toBe(404, $route);
    }

    expect(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with([
    'own visit completed yesterday',
    'manual assignment only',
    'another doctor in service',
    'no relationship',
]);

it('shows the archive in the doctor lists exactly when the record itself is readable', function (bool $flag, string $scenario, bool $readable) {
    lodesFlag($flag);
    lodesArrange($scenario);

    $lists = lodesListsSee($this->userA, $this->patient);
    $show = lodesStatus($this->userA, 'rme.legacy-odontograms.show', $this->archive);
    $page = lodesStatus($this->userA, 'rme.legacy-odontograms.pages.show', $this->archive);

    $refused = $flag ? 404 : 403;

    expect($lists)->each->toBe($readable)
        ->and($show)->toBe($readable ? 200 : $refused)
        ->and($page)->toBe($readable ? 200 : $refused);
})->with([
    'ON in progress' => [true, 'own encounter in progress', true],
    'ON completed yesterday' => [true, 'own visit completed yesterday', false],
    'ON manual assignment' => [true, 'manual assignment only', false],
    'ON other doctor' => [true, 'another doctor in service', false],
    'OFF in progress' => [false, 'own encounter in progress', true],
    'OFF completed yesterday' => [false, 'own visit completed yesterday', true],
    'OFF manual assignment' => [false, 'manual assignment only', true],
    'OFF other doctor' => [false, 'another doctor in service', false],
    'OFF nothing' => [false, 'no relationship', false],
]);

it('refuses an out-of-scope doctor even when the policy is bypassed (the controller re-asserts, flag ON)', function () {
    lodesFlag(true);
    lodesArrange('own visit completed yesterday');

    Gate::before(fn () => true);

    foreach (['rme.legacy-odontograms.show', 'rme.legacy-odontograms.pages.show', 'rme.legacy-odontograms.source'] as $route) {
        expect(lodesStatus($this->userA, $route, $this->archive))->toBe(404, $route);
    }
});

it('leaves exempt and non-doctor readers unchanged, with no encounter at all', function (bool $flag, string $who) {
    lodesFlag($flag);

    $reader = match ($who) {
        'Super Admin' => superAdmin(),
        'Supervisor RME' => User::factory()->create(['branch_id' => null])->assignRole('Supervisor RME'),
        'Admin Klinik' => User::factory()->create(['branch_id' => $this->branch->id])->assignRole('Admin Klinik'),
    };

    expect(lodesStatus($reader->refresh(), 'rme.legacy-odontograms.show', $this->archive))->toBe(200)
        ->and(lodesStatus($reader, 'rme.legacy-odontograms.pages.show', $this->archive))->toBe(200)
        ->and(app(LegacyOdontogramPatientHistoryService::class)->publishedRecordsFor($reader, (int) $this->patient->id))->toHaveCount(1);
})->with([true, false])->with(['Super Admin', 'Supervisor RME', 'Admin Klinik']);

it('keeps branch isolation: an in-scope doctor still gets 404 for an archive from a branch they do not practise in', function (bool $flag) {
    lodesFlag($flag);
    lodesArrange('own encounter in progress');

    $foreign = lodesArchive($this->patient, $this->otherBranch, 'foreign');

    expect(app(LegacyOdontogramPatientHistoryService::class)->publishedRecordsFor($this->userA, (int) $this->patient->id)->pluck('id')->all())
        ->toBe([(int) $this->archive->id])
        ->and(lodesStatus($this->userA, 'rme.legacy-odontograms.show', $foreign))->toBe(404)
        ->and(lodesStatus($this->userA, 'rme.legacy-odontograms.pages.show', $foreign))->toBe(404)
        ->and(lodesStatus($this->userA, 'rme.legacy-odontograms.show', $this->archive))->toBe(200);
})->with(['flag ON' => true, 'flag OFF' => false]);
