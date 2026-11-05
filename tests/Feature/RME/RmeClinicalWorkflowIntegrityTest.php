<?php

// DMS-RME-REVISION-PROGRAM-5 — clinical workflow audit, verified defects only.
//
// Each block pins one defect the audit reproduced against a canonical rule. The
// direction of every fix is the one rule 135 states permanently: UI DENY +
// SERVER DENY, never a relaxed server. Helper prefix `p5cw` is unique because the
// critical gate loads every suite into one process.
//
//   CW5-01  a visit's status is decided from the LOCKED row, never from a stale
//           copy (AIR-RMC-01: completed/cancelled are terminal, the map is fixed)
//   CW5-02  Detail Kunjungan never offers "Buat Rekam Medis" the server refuses
//           (AIR-RMC-25: capability, not `can('create', MedicalRecord)` alone)
//   CW5-03  the Rekam Medis page's "Selesai Pemeriksaan" mirrors Detail Kunjungan
//           (rule 135 matrix, rule 134 §6)
//   CW5-04  the handwriting editor and diagnosis forms follow the RME capability
//           (AIR-RMC-25)
//   CW5-05  the front office never authors the clinical record (rule 134 §1, §7;
//           rule 108 §12; DPT-064)
//   CW5-06  a new sheet's source visit is IDOR-validated (AIR-RMC-23)
//   CW5-07  consent signing is decided from the locked visit (AIR-RMC-06)
//   CW5-08  the consent pages state the real rule (AIR-RMC-06, AIR-RMC-11)

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);

    $this->clinician = userWith([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents', 'view_rme_consents',
    ]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
});

function p5cwVisit(string $status, ?Patient $patient = null, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'status' => $status,
    ], $overrides));
}

function p5cwRecord(ClinicVisit $visit): MedicalRecord
{
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
    ]);

    return $record;
}

/** A second request's view of the same row: loaded now, acted on later. */
function p5cwStale(ClinicVisit $visit): ClinicVisit
{
    return ClinicVisit::query()->findOrFail($visit->id);
}

function p5cwRefused(callable $work): bool
{
    return p5cwRefusal($work) !== null;
}

/** The refusal's error keys, or null when the work succeeded. */
function p5cwRefusal(callable $work): ?array
{
    try {
        $work();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return null;
}

/** Capture every row-locking statement on the visit table while $work runs (PostgreSQL). */
function p5cwVisitLocks(callable $work): array
{
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'trx_clinic_visits') && preg_match('/for (no key )?update/', $sql)) {
            $locks[] = $sql;
        }
    });

    $work();

    return $locks;
}

function p5cwConsentData(): array
{
    return [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => false,
        'consenter_signature' => validPodSignatureData(),
    ];
}

function p5cwFrontDesk(string $role = 'Admin Klinik'): User
{
    $user = userInRole($role);
    rmeMakeAdminClinicActive($user, test()->branch);

    return $user;
}

/*
|--------------------------------------------------------------------------
| CW5-01 — the transition is decided from the locked row
|--------------------------------------------------------------------------
*/

it('CW5-01a refuses to finish an examination that was cancelled after the request loaded it', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $stale = p5cwStale($visit);

    app(ClinicVisitService::class)->transitionStatus(p5cwStale($visit), ClinicVisit::STATUS_CANCELLED, $this->clinician);

    expect(p5cwRefusal(fn () => app(ClinicVisitService::class)
        ->transitionStatus($stale, ClinicVisit::STATUS_CASHIER_PENDING, $this->clinician)))->toBe(['status'])
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CANCELLED)
        ->and($visit->cancelled_at)->not->toBeNull();
});

it('CW5-01b refuses to cancel a visit that reached the cashier after the request loaded it', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $stale = p5cwStale($visit);

    app(ClinicVisitService::class)->transitionStatus(p5cwStale($visit), ClinicVisit::STATUS_CASHIER_PENDING, $this->clinician);

    expect(p5cwRefusal(fn () => app(ClinicVisitService::class)
        ->transitionStatus($stale, ClinicVisit::STATUS_CANCELLED, $this->clinician)))->toBe(['status'])
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and($visit->cancelled_at)->toBeNull();
});

it('CW5-01c refuses to start an examination on a visit cancelled after the request loaded it', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_WAITING);
    $stale = p5cwStale($visit);

    app(ClinicVisitService::class)->transitionStatus(p5cwStale($visit), ClinicVisit::STATUS_CANCELLED, $this->clinician);

    expect(p5cwRefusal(fn () => app(ClinicVisitService::class)
        ->transitionStatus($stale, ClinicVisit::STATUS_IN_PROGRESS, $this->clinician)))->toBe(['status'])
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CANCELLED)
        ->and($visit->started_at)->toBeNull();
});

it('CW5-01d refuses to place a room on a visit cancelled after the request loaded it', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline(Doctor::factory()->withAllowedBranches([$this->branch])->create(), $this->branch, $room);
    $visit = p5cwVisit(ClinicVisit::STATUS_WAITING, overrides: ['clinic_room_id' => null]);
    $stale = p5cwStale($visit);

    app(ClinicVisitService::class)->transitionStatus(p5cwStale($visit), ClinicVisit::STATUS_CANCELLED, $this->clinician);

    expect(p5cwRefusal(fn () => app(ClinicVisitService::class)
        ->assignRoom($stale, (int) $room->id, $this->clinician)))->toBe(['clinic_room_id'])
        ->and($visit->refresh()->clinic_room_id)->toBeNull();
});

it('CW5-01d+ places the same room on a live visit when nothing raced it (positive control)', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline(Doctor::factory()->withAllowedBranches([$this->branch])->create(), $this->branch, $room);
    $visit = p5cwVisit(ClinicVisit::STATUS_WAITING, overrides: ['clinic_room_id' => null]);

    app(ClinicVisitService::class)->assignRoom(p5cwStale($visit), (int) $room->id, $this->clinician);

    expect((int) $visit->refresh()->clinic_room_id)->toBe((int) $room->id);
});

it('CW5-01e still finishes a current examination and hands back the persisted row', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);

    $result = app(ClinicVisitService::class)->transitionStatus($visit, ClinicVisit::STATUS_CASHIER_PENDING, $this->clinician);

    expect($result->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and($visit->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('CW5-01f locks the visit row FOR NO KEY UPDATE before deciding a transition, a room or a signature (PostgreSQL)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks compile to nothing on SQLite; this assertion needs PostgreSQL.');
    }

    $finish = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($finish);
    $transition = p5cwVisitLocks(fn () => app(ClinicVisitService::class)
        ->transitionStatus($finish, ClinicVisit::STATUS_CASHIER_PENDING, $this->clinician));

    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline(Doctor::factory()->withAllowedBranches([$this->branch])->create(), $this->branch, $room);
    $queued = p5cwVisit(ClinicVisit::STATUS_WAITING, Patient::factory()->create(['branch_id' => $this->branch->id]), ['clinic_room_id' => null]);
    $placement = p5cwVisitLocks(fn () => app(ClinicVisitService::class)->assignRoom($queued, (int) $room->id, $this->clinician));

    $examined = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS, Patient::factory()->create(['branch_id' => $this->branch->id]));
    $signing = p5cwVisitLocks(fn () => app(RmeVisitConsentService::class)->sign($examined, $this->clinician, p5cwConsentData()));

    // The strength matters: FOR UPDATE would conflict with the FOR KEY SHARE every
    // foreign-key insert takes on the visit (the 40P01 RmeInvoiceService records).
    foreach ([$transition, $placement, $signing] as $locks) {
        expect(collect($locks)->contains(fn ($sql) => str_contains($sql, 'for no key update')))->toBeTrue();
    }
});

/*
|--------------------------------------------------------------------------
| CW5-02 — Detail Kunjungan's "Buat Rekam Medis" follows the capability
|--------------------------------------------------------------------------
*/

it('CW5-02a does not offer a live Buat Rekam Medis form on Detail Kunjungan before consent', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertDontSee('action="'.route('rme.visits.medical-record.store', $visit).'"', false)
        ->assertSee('Buat Rekam Medis')
        // Navigation to the workspace stays, as a real GET link (it used to be
        // satisfied only by the coincidence that the POST form shares the URL).
        ->assertSee('href="'.route('rme.visits.medical-record.show', $visit).'"', false);
});

it('CW5-02b offers the Buat Rekam Medis form once the encounter is consented', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('action="'.route('rme.visits.medical-record.store', $visit).'"', false);
});

it('CW5-02c does not offer it on a visit whose examination has not started', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_WAITING);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertDontSee('action="'.route('rme.visits.medical-record.store', $visit).'"', false);
});

/*
|--------------------------------------------------------------------------
| CW5-03 / CW5-04 — the Rekam Medis page follows the capability
|--------------------------------------------------------------------------
*/

it('CW5-03 does not offer Selesai Pemeriksaan on the Rekam Medis page while consent is unsigned', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    p5cwRecord($visit);
    $finish = 'action="'.route('rme.visits.transition', $visit).'"';

    $page = fn () => $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->get(route('rme.visits.medical-record.show', $visit));

    $page()->assertOk()->assertDontSee($finish, false);

    rmeSignedConsentFor($visit);

    $page()->assertOk()->assertSee($finish, false);
});

it('CW5-04 keeps the handwriting editor and the diagnosis forms closed until the encounter is consented', function () {
    $earlier = p5cwVisit(ClinicVisit::STATUS_COMPLETED);
    p5cwRecord($earlier);
    $current = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $page = fn () => $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $current));

    $page()->assertOk()
        ->assertDontSee('id="handwriting-form"', false)
        ->assertDontSee('Cari diagnosis (kode / nama)');

    rmeSignedConsentFor($current);

    $page()->assertOk()
        ->assertSee('id="handwriting-form"', false)
        ->assertSee('Cari diagnosis (kode / nama)');
});

/*
|--------------------------------------------------------------------------
| CW5-05 — the front office never authors the clinical record
|--------------------------------------------------------------------------
*/

it('CW5-05a refuses Admin Klinik the active RME and odontogram authority on a consented encounter', function () {
    $admin = p5cwFrontDesk('Admin Klinik');
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $consents = app(RmeVisitConsentService::class);

    expect($consents->canAuthorRmeForPatient((int) $visit->patient_id, $admin))->toBeFalse()
        ->and($consents->canAuthorOdontogramFor($visit, $admin))->toBeFalse()
        ->and(p5cwRefused(fn () => $consents->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $admin)))->toBeTrue()
        ->and(p5cwRefused(fn () => $consents->assertOdontogramAuthoringAllowed($visit, $admin)))->toBeTrue();
});

it('CW5-05b refuses Front Office the same authority', function () {
    $frontOffice = p5cwFrontDesk('Front Office');
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $consents = app(RmeVisitConsentService::class);

    expect($consents->canAuthorRmeForPatient((int) $visit->patient_id, $frontOffice))->toBeFalse()
        ->and($consents->canAuthorOdontogramFor($visit, $frontOffice))->toBeFalse();
});

it('CW5-05c refuses an Admin Klinik direct write of the record and the chart', function () {
    $admin = p5cwFrontDesk('Admin Klinik');
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $chart = ['tooth_map_payload' => ['teeth' => ['11' => ['conditions' => ['caries']]]]];

    // Refused by the authority, not by a missing permission: the account passes
    // the route's `manage_clinic_visits` middleware and is turned back by the
    // service with a validation error.
    $this->actingAs($admin)->patch(route('rme.visits.odontogram.store', $visit), $chart)
        ->assertRedirect()->assertSessionHasErrors();
    $this->actingAs($admin)->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertRedirect()->assertSessionHasErrors();

    expect(Odontogram::query()->where('clinic_visit_id', $visit->id)->count())->toBe(0)
        ->and(MedicalRecord::query()->where('clinic_visit_id', $visit->id)->count())->toBe(0);
});

it('CW5-05c+ lets the clinician make the very same two writes (positive control)', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $chart = ['tooth_map_payload' => ['teeth' => ['11' => ['conditions' => ['caries']]]]];

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->actingAs($this->clinician)
        ->patch(route('rme.visits.odontogram.store', $visit), $chart)->assertSessionHasNoErrors();
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->actingAs($this->clinician)
        ->post(route('rme.visits.medical-record.store', $visit), [])->assertSessionHasNoErrors();

    expect(Odontogram::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1)
        ->and(MedicalRecord::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('CW5-05f tells the front office the real reason on a consented encounter, never to fetch a consent', function () {
    $admin = p5cwFrontDesk('Admin Klinik');
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    p5cwRecord($visit);
    rmeSignedConsentFor($visit);

    $this->actingAs($admin)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Hanya dapat dibaca')
        ->assertDontSee('Persetujuan Tindakan Medis belum ditandatangani')
        ->assertDontSee('id="handwriting-form"', false);
});

it('CW5-05d keeps a clinician who also holds the front-office role able to author', function () {
    $clinician = userInRole('Admin Klinik');
    $clinician->assignRole('Perawat');
    rmeMakeAdminClinicActive($clinician, $this->branch);
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);

    expect(app(RmeVisitConsentService::class)->canAuthorOdontogramFor($visit, $clinician))->toBeTrue();
});

it('CW5-05e keeps the non-role clinician fixture authorized (regression for the existing suites)', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient((int) $visit->patient_id, $this->clinician))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| CW5-06 — a new sheet's source visit is validated
|--------------------------------------------------------------------------
*/

it('CW5-06a never records another patient\'s visit as a new sheet\'s source', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $foreign = p5cwVisit(ClinicVisit::STATUS_COMPLETED, Patient::factory()->create(['branch_id' => $this->branch->id]));

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->post(route('rme.visits.medical-record.store', $visit), ['source_visit_id' => $foreign->id])
        ->assertRedirect();

    expect((int) MedicalRecord::query()->where('clinic_visit_id', $visit->id)->value('source_visit_id'))->toBe((int) $visit->id);
});

it('CW5-06b ignores a source visit id that does not exist instead of failing', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->post(route('rme.visits.medical-record.store', $visit), ['source_visit_id' => 987654321])
        ->assertRedirect();

    expect((int) MedicalRecord::query()->where('clinic_visit_id', $visit->id)->value('source_visit_id'))->toBe((int) $visit->id);
});

/*
|--------------------------------------------------------------------------
| CW5-07 — consent signing is decided from the locked visit
|--------------------------------------------------------------------------
*/

it('CW5-07 refuses to sign a consent for a visit cancelled after the request loaded it', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $stale = p5cwStale($visit);

    app(ClinicVisitService::class)->transitionStatus(p5cwStale($visit), ClinicVisit::STATUS_CANCELLED, $this->clinician);

    expect(p5cwRefusal(fn () => app(RmeVisitConsentService::class)->sign($stale, $this->clinician, p5cwConsentData())))
        ->toBe(['clinic_visit_id'])
        ->and(RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->count())->toBe(0);
});

it('CW5-07+ signs the same consent on a live examination when nothing raced it (positive control)', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $consent = app(RmeVisitConsentService::class)->sign(p5cwStale($visit), $this->clinician, p5cwConsentData());

    expect((int) $consent->clinic_visit_id)->toBe((int) $visit->id)
        ->and((int) $consent->doctor_id)->toBe((int) $visit->doctor_id)
        ->and(app(RmeVisitConsentService::class)->hasValidConsent($visit->refresh()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| CW5-08 — the consent pages state the real rule
|--------------------------------------------------------------------------
*/

it('CW5-08a explains the real signing window when the consent form is refused', function () {
    $visit = p5cwVisit(ClinicVisit::STATUS_WAITING);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->clinician)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertRedirect(route('rme.visits.show', $visit));

    $message = (string) session('error');

    expect($message)->not->toContain('setelah dokter menyelesaikan pemeriksaan')
        ->and($message)->toContain('Mulai Pemeriksaan');
});
