<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A2 (rule 243 §2.10)
|--------------------------------------------------------------------------
| The published LEGACY RME archive under the doctor encounter scope
| (`rme.doctor_encounter_scope`, default OFF, FeatureFlagService only).
|
| Flag ON, for a Doctor (Owner, Super Admin and Supervisor RME exempt): the
| archive is patient-level history, so it is readable only while the doctor
| has an OWN in-service encounter with the patient — read mode `history` from
| DoctorPatientScopeService::readModeFor(), never re-derived. Otherwise 404
| (revises rule 99 §1/§2/§11, AIR-LRM-25/26 and rule 97 §5/§6, AIR-LRM-27):
| an assignment row grants nothing, the doctor's own old visit (`own` mode)
| grants nothing, another doctor's patient is 404. The viewer, the page-image
| route and the download doors answer 404 before any download rule; inside
| the scope the rule 243 §1 download refusal (403, audited) is kept. Every
| list a doctor sees (history service, visit timeline, workspace pages,
| document rail, legacy lookup) mirrors the per-record answer. The policy is
| re-asserted in the controller through the same authority, so a bypassed
| policy still cannot serve a doctor.
|
| Flag OFF: every read answers exactly as today (parity). Front Office, Admin
| Klinik and the exempt roles are unchanged; the branch rules (origin branch
| from the Nomor RM, DoctorClinicalBranchResolver) are unchanged.
|
| Selected by the existing `LegacyRme` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `lrdes` is unique (the critical gate loads every suite at once).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Services\LegacyRmePatientLookupService;
use App\Modules\MedicalRecord\Services\RmeWorkspaceDocumentPresenter;
use App\Modules\MedicalRecord\Services\RmeWorkspacePageSequencer;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

uses()->group('LegacyRme');

const LRDES_FLAG = 'rme.doctor_encounter_scope';

const LRDES_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

const LRDES_PRINT_DENY = 'Dokter tidak diizinkan mencetak atau mengunduh rekam medis.';

beforeEach(function () {
    // 10:00 WITA on 2026-11-17 (02:00 UTC): one clinical day in both zones.
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC'));

    seedAccessControl();
    // Published read is never gated on the migration capability (rule 98).
    legacyRmeArchiveFlag(false);
    Storage::fake('legacy_rme_private');

    // MAIN is never an RME branch; leaving it RME-enabled would let an unpinned
    // BranchContext fall back into scope and hide a real failure.
    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    [$this->doctorA, $this->userA] = lrdesDoctor($this->branch);
    [$this->doctorB, $this->userB] = lrdesDoctor($this->branch);

    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->archive = lrdesArchive($this->patient, $this->branch, 'a');

    // Sprint 66's EnsureRmeOnlineContext redirects a Doctor with no online
    // context; orthogonal to the archive's own boundary measured here.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function lrdesFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[LRDES_FLAG]['default'] = $on;
    $flags[LRDES_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

/**
 * A Doctor-role account whose doctor master practises in `$practice` (the
 * production shape: users.branch_id NULL; the seeded Doctor role carries
 * `view_legacy_rme_archive`).
 *
 * @return array{0: Doctor, 1: User}
 */
function lrdesDoctor(Branch $practice): array
{
    $user = User::factory()->create(['branch_id' => null]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->withAllowedBranches([$practice])->create([
        'user_id' => $user->id,
        'branch_id' => $practice->id,
        'is_active' => true,
    ]);

    return [$doctor->refresh(), $user->refresh()];
}

/** A published archive with one rendered page and its source PDF on the private disk. */
function lrdesArchive(Patient $patient, Branch $origin, string $tag): LegacyRmeRecord
{
    $record = LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $origin->id,
        'rme_date' => '2019-04-17',
        'source_disk' => 'legacy_rme_private',
        'source_pdf_path' => "lrdes/{$tag}/source.pdf",
        'page_count' => 1,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
    ]);

    LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $record->id,
        'page_number' => 1,
        'background_disk' => 'legacy_rme_private',
        'background_path' => "lrdes/{$tag}/page-1.png",
    ]);

    Storage::disk('legacy_rme_private')->put("lrdes/{$tag}/source.pdf", legacyRmePdfBytes(1));
    Storage::disk('legacy_rme_private')->put("lrdes/{$tag}/page-1.png", base64_decode(LRDES_PNG));

    return $record->refresh();
}

function lrdesVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function lrdesAssignment(Doctor $doctor, string $type): void
{
    PatientDoctorAssignment::factory()->create([
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'assignment_type' => $type,
        'unassigned_at' => null,
    ]);
}

/** The relationship between doctor A and the patient, by scenario name. */
function lrdesArrange(string $scenario): void
{
    $a = test()->doctorA;

    match ($scenario) {
        'own encounter in progress' => lrdesVisit($a, ClinicVisit::STATUS_IN_PROGRESS),
        'own encounter waiting' => lrdesVisit($a, ClinicVisit::STATUS_WAITING),
        'own encounter completed today' => lrdesVisit($a, ClinicVisit::STATUS_COMPLETED, ['completed_at' => '2026-11-17 01:00:00']),
        'own visit completed yesterday' => lrdesVisit($a, ClinicVisit::STATUS_COMPLETED, ['completed_at' => '2026-11-16 03:00:00', 'visit_date' => '2026-11-16']),
        'own visit cancelled today' => lrdesVisit($a, ClinicVisit::STATUS_CANCELLED),
        'manual assignment only' => lrdesAssignment($a, PatientDoctorAssignment::TYPE_MANUAL),
        'shared assignment only' => lrdesAssignment($a, PatientDoctorAssignment::TYPE_SHARED),
        'auto_visit assignment only' => lrdesAssignment($a, PatientDoctorAssignment::TYPE_AUTO_VISIT),
        'another doctor in service' => lrdesVisit(test()->doctorB, ClinicVisit::STATUS_IN_PROGRESS),
        'no relationship' => null,
    };
}

function lrdesStatus(User $user, string $route, LegacyRmeRecord $record): int
{
    $parameters = $route === 'rme.legacy-records.pages.show' ? [$record->id, 1] : [$record->id];

    return test()->actingAs($user)->get(route($route, $parameters))->getStatusCode();
}

/**
 * Every list a doctor sees the archive through, asked before any HTTP request
 * (DoctorPatientScopeService memoises per ROUTED request only).
 *
 * @return array<string, bool>
 */
function lrdesListsSee(User $user, Patient $patient): array
{
    $patientId = (int) $patient->id;

    return [
        'history service' => app(LegacyRmePatientHistoryService::class)->publishedRecordsFor($user, $patientId)->isNotEmpty(),
        'visit timeline' => app(LegacyRmePatientHistoryService::class)->timelineFor($user, $patientId, [])->isNotEmpty(),
        'workspace pages' => app(RmeWorkspacePageSequencer::class)->sequenceFor($user, $patientId, [])->isNotEmpty(),
        'document rail' => app(RmeWorkspaceDocumentPresenter::class)->hasLegacyDocuments($user, $patientId),
        'legacy lookup' => app(LegacyRmePatientLookupService::class)->findSelectable($user, $patientId) !== null,
    ];
}

function lrdesPrintAudits(): int
{
    return DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count();
}

/*
|--------------------------------------------------------------------------
| 1 — flag ON: an own in-service encounter reads, everything else is 404
|--------------------------------------------------------------------------
*/

it('reads the archive and its page images inside an own in-service encounter (flag ON)', function (string $scenario) {
    lrdesFlag(true);
    lrdesArrange($scenario);

    expect(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(200);

    $page = $this->actingAs($this->userA)->get(route('rme.legacy-records.pages.show', [$this->archive->id, 1]));

    $page->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $page->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
})->with([
    'own encounter in progress',
    'own encounter waiting',
    'own encounter completed today',
]);

it('keeps the rule 243 §1 download refusal inside the scope (403, audited)', function () {
    lrdesFlag(true);
    lrdesArrange('own encounter in progress');

    foreach (['rme.legacy-records.source', 'rme.legacy-records.print', 'rme.legacy-records.export'] as $route) {
        $this->actingAs($this->userA)->get(route($route, $this->archive->id))
            ->assertForbidden()
            ->assertSee(LRDES_PRINT_DENY);
    }

    expect(lrdesPrintAudits())->toBe(3);
});

it('answers 404 on every archive door outside the scope, before any download rule (flag ON)', function (string $scenario) {
    lrdesFlag(true);
    lrdesArrange($scenario);

    foreach ([
        'rme.legacy-records.show',
        'rme.legacy-records.pages.show',
        'rme.legacy-records.source',
        'rme.legacy-records.print',
        'rme.legacy-records.export',
    ] as $route) {
        expect(lrdesStatus($this->userA, $route, $this->archive))->toBe(404, $route);
    }

    // Nothing was served, so no doctor-print refusal was recorded either.
    expect(lrdesPrintAudits())->toBe(0);
})->with([
    'own visit completed yesterday',
    'own visit cancelled today',
    'manual assignment only',
    'shared assignment only',
    'auto_visit assignment only',
    'another doctor in service',
    'no relationship',
]);

it('lets the other doctor read their own in-service patient while doctor A gets 404 (flag ON)', function () {
    lrdesFlag(true);
    lrdesArrange('another doctor in service');

    expect(lrdesStatus($this->userB, 'rme.legacy-records.show', $this->archive))->toBe(200)
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(404);
});

it('follows the clinical calendar (WITA): completed after local midnight is today, before it is not', function () {
    lrdesFlag(true);

    // 00:30 WITA on 2026-11-17 is still 2026-11-16 in UTC.
    $today = lrdesVisit($this->doctorA, ClinicVisit::STATUS_COMPLETED, ['completed_at' => '2026-11-16 16:30:00']);

    expect(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(200);

    // 23:30 WITA on 2026-11-16: yesterday on the clinical calendar.
    $today->forceFill(['completed_at' => '2026-11-16 15:30:00'])->save();

    expect(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(404);
});

/*
|--------------------------------------------------------------------------
| 2 — lists mirror the per-record answer, flag ON and OFF
|--------------------------------------------------------------------------
*/

it('shows the archive in every doctor list exactly when the record itself is readable', function (bool $flag, string $scenario, bool $readable) {
    lrdesFlag($flag);
    lrdesArrange($scenario);

    $lists = lrdesListsSee($this->userA, $this->patient);
    $show = lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive);
    $page = lrdesStatus($this->userA, 'rme.legacy-records.pages.show', $this->archive);

    $refused = $flag ? 404 : 403;

    expect($lists)->each->toBe($readable)
        ->and($show)->toBe($readable ? 200 : $refused)
        ->and($page)->toBe($readable ? 200 : $refused);
})->with([
    'ON in progress' => [true, 'own encounter in progress', true],
    'ON completed today' => [true, 'own encounter completed today', true],
    'ON completed yesterday' => [true, 'own visit completed yesterday', false],
    'ON cancelled' => [true, 'own visit cancelled today', false],
    'ON manual assignment' => [true, 'manual assignment only', false],
    'ON other doctor' => [true, 'another doctor in service', false],
    'ON nothing' => [true, 'no relationship', false],
    // Flag OFF = the Sprint 66.2 relationship scope, today's answers exactly.
    'OFF in progress' => [false, 'own encounter in progress', true],
    'OFF completed yesterday' => [false, 'own visit completed yesterday', true],
    'OFF cancelled' => [false, 'own visit cancelled today', true],
    'OFF manual assignment' => [false, 'manual assignment only', true],
    'OFF auto_visit assignment' => [false, 'auto_visit assignment only', true],
    'OFF other doctor' => [false, 'another doctor in service', false],
    'OFF nothing' => [false, 'no relationship', false],
]);

/*
|--------------------------------------------------------------------------
| 3 — flag OFF parity on the download doors
|--------------------------------------------------------------------------
*/

it('answers every door exactly as today with the flag OFF', function (string $scenario, array $expected) {
    lrdesFlag(false);
    lrdesArrange($scenario);

    $actual = [];

    foreach (array_keys($expected) as $route) {
        $actual[$route] = lrdesStatus($this->userA, $route, $this->archive);
    }

    expect($actual)->toBe($expected);
})->with([
    // A treating doctor (any visit, even an old one): reads, never downloads.
    'old own visit' => ['own visit completed yesterday', [
        'rme.legacy-records.show' => 200,
        'rme.legacy-records.pages.show' => 200,
        'rme.legacy-records.source' => 403,
        'rme.legacy-records.print' => 403,
        'rme.legacy-records.export' => 403,
    ]],
    // No relationship: the policy's plain 403, as before A2.
    'no relationship' => ['no relationship', [
        'rme.legacy-records.show' => 403,
        'rme.legacy-records.pages.show' => 403,
        'rme.legacy-records.source' => 403,
        'rme.legacy-records.print' => 403,
        'rme.legacy-records.export' => 403,
    ]],
]);

/*
|--------------------------------------------------------------------------
| 4 — the re-assertion past a bypassed policy
|--------------------------------------------------------------------------
*/

it('refuses an out-of-scope doctor even when the policy is bypassed (the controller re-asserts, flag ON)', function () {
    lrdesFlag(true);
    lrdesArrange('own visit completed yesterday');

    // Simulate a policy that no longer refuses (a regression, or Gate::before).
    Gate::before(fn () => true);

    expect(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(404)
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.pages.show', $this->archive))->toBe(404)
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.source', $this->archive))->toBe(404);
});

it('leaves the flag-OFF read path untouched even with the policy bypassed (the re-assertion is a no-op)', function () {
    lrdesFlag(false);
    lrdesArrange('no relationship');

    Gate::before(fn () => true);

    expect(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(200)
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.pages.show', $this->archive))->toBe(200);
});

it('asks the encounter scope through its one authority (readModeFor)', function () {
    lrdesFlag(true);
    lrdesArrange('own visit completed yesterday');

    $scope = app(DoctorPatientScopeService::class);

    expect($scope->readModeFor($this->userA, (int) $this->patient->id))->toBe(DoctorPatientScopeService::READ_OWN)
        ->and($scope->authorizeLegacyArchiveRead($this->userA, $this->patient)->status())->toBe(404);

    lrdesVisit($this->doctorA, ClinicVisit::STATUS_IN_PROGRESS);

    expect($scope->readModeFor($this->userA, (int) $this->patient->id))->toBe(DoctorPatientScopeService::READ_HISTORY)
        ->and($scope->authorizeLegacyArchiveRead($this->userA, $this->patient))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 5 — exempt and non-doctor readers are unchanged
|--------------------------------------------------------------------------
*/

it('leaves exempt and non-doctor readers unchanged, with no encounter at all', function (bool $flag, string $who) {
    lrdesFlag($flag);

    $reader = match ($who) {
        'Super Admin' => superAdmin(),
        'Supervisor RME' => User::factory()->create(['branch_id' => null])->assignRole('Supervisor RME'),
        'Doctor + Supervisor RME' => tap(lrdesDoctor($this->branch)[1])->assignRole('Supervisor RME'),
        'Admin Klinik' => User::factory()->create(['branch_id' => $this->branch->id])->assignRole('Admin Klinik'),
        'Front Office' => User::factory()->create(['branch_id' => $this->branch->id])->assignRole('Front Office'),
    };

    expect(lrdesStatus($reader->refresh(), 'rme.legacy-records.show', $this->archive))->toBe(200)
        ->and(lrdesStatus($reader, 'rme.legacy-records.pages.show', $this->archive))->toBe(200)
        ->and(app(LegacyRmePatientHistoryService::class)->publishedRecordsFor($reader, (int) $this->patient->id))->toHaveCount(1);
})->with([true, false])->with([
    'Super Admin',
    'Supervisor RME',
    'Doctor + Supervisor RME',
    'Admin Klinik',
    'Front Office',
]);

/*
|--------------------------------------------------------------------------
| 6 — branch isolation is unchanged
|--------------------------------------------------------------------------
*/

it('keeps branch isolation: an in-scope doctor still gets 404 for an archive from a branch they do not practise in', function (bool $flag) {
    lrdesFlag($flag);
    lrdesArrange('own encounter in progress');

    $foreign = lrdesArchive($this->patient, $this->otherBranch, 'foreign');

    expect(app(LegacyRmePatientHistoryService::class)->publishedRecordsFor($this->userA, (int) $this->patient->id)->pluck('id')->all())
        ->toBe([(int) $this->archive->id])
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.show', $foreign))->toBe(404)
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.pages.show', $foreign))->toBe(404)
        ->and(lrdesStatus($this->userA, 'rme.legacy-records.show', $this->archive))->toBe(200);
})->with(['flag ON' => true, 'flag OFF' => false]);

it('answers an out-of-scope archive exactly like a missing one, in HTML and JSON (A2 review LOW-1, flag ON)', function () {
    lrdesFlag(true);
    lrdesArrange('no relationship');

    $missingId = (int) LegacyRmeRecord::query()->max('id') + 1000;

    foreach (['rme.legacy-records.show', 'rme.legacy-records.pages.show'] as $route) {
        $params = fn (int $id): array => $route === 'rme.legacy-records.pages.show' ? [$id, 1] : [$id];

        foreach ([['Accept' => 'application/json'], []] as $headers) {
            $outOfScope = $this->actingAs($this->userA)->withHeaders($headers)->get(route($route, $params($this->archive->id)));
            $missing = $this->actingAs($this->userA)->withHeaders($headers)->get(route($route, $params($missingId)));

            $outOfScope->assertNotFound();
            $missing->assertNotFound();
            expect($outOfScope->getContent())->toBe($missing->getContent(), $route.' '.json_encode($headers));
        }
    }
});
