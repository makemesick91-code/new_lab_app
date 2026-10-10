<?php

/**
 * PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT — the server-side pilot gate.
 *
 * KTP camera OCR needs the global flag AND pilot eligibility: an operator named
 * in the cohort, working (server-resolved) at the single approved branch,
 * inside the pilot period, and — unless waived — on an approved bound tablet.
 * Every denial must hide both the parse endpoint (404) and the camera UI, while
 * the existing scanner + manual registration flow keeps working. All identities
 * are FICTIONAL.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\KtpCameraOcrPilotGate;
use App\Modules\Patient\Support\KtpCameraOcrPilotDecision;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeRole;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    ktpPilotScope();
    ktpPilotFlag(false);
});

afterEach(fn () => Carbon::setTestNow());

function pilotParse(User $user, array $session = [])
{
    return test()->actingAs($user)
        ->withSession($session)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), [
            ...ktpConsent(),
            'lines' => [['text' => 'NIK : 7371015708900003', 'confidence' => 92]],
        ]);
}

/** Denied at SOME server layer (policy 403 or gate 404) — never 200. */
function pilotDenied($response): void
{
    expect($response->status())->toBeIn([403, 404]);
}

function pilotReason(?User $user, array $session = []): string
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    foreach ($session as $k => $v) {
        $request->session()->put($k, $v);
    }

    return app(KtpCameraOcrPilotGate::class)->decide($user, $request)->reason;
}

function pilotDevice(Branch $branch, array $attrs = []): DoctorDevice
{
    return DoctorDevice::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ], $attrs));
}

// ------------------------------------------------------------- allow path --

it('lets an approved pilot operator at the pilot branch use OCR', function () {
    $operator = ktpPilotOperator();

    pilotParse($operator)->assertOk();
    expect(pilotReason($operator))->toBe(KtpCameraOcrPilotDecision::ALLOWED);

    $this->actingAs($operator)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('data-ocr-enabled="1"', false)
        ->assertSee('Foto KTP dengan Kamera');
});

// ------------------------------------------------------------ both gates --

it('stays off for a pilot operator while the global flag is off', function () {
    $operator = ktpPilotOperator();
    ktpPilotFlag(false);

    pilotParse($operator)->assertNotFound();
    expect(pilotReason($operator))->toBe(KtpCameraOcrPilotDecision::FEATURE_DISABLED);
});

it('enables nobody when the flag is on but no pilot scope exists', function () {
    $branch = ktpPilotBranch();
    $user = User::factory()->create();
    rmeMakeAdminClinicActive($user, $branch);
    ktpPilotScope(); // empty cohort, empty branch
    ktpPilotFlag(true);

    pilotParse($user)->assertNotFound();
    expect(pilotReason($user))->toBe(KtpCameraOcrPilotDecision::PILOT_NOT_CONFIGURED);

    $this->actingAs($user)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('data-ocr-enabled="0"', false)
        ->assertDontSee('Foto KTP dengan Kamera')
        ->assertSee('Unggah foto KTP secara manual');
});

it('exposes no "everyone" switch in the pilot configuration', function () {
    $keys = array_keys(require base_path('config/patient_ktp_ocr_pilot.php'));

    expect($keys)->not->toContain('all')
        ->and($keys)->not->toContain('everyone')
        ->and($keys)->not->toContain('global')
        ->and(implode(' ', $keys))->not->toContain('role');
});

// --------------------------------------------------------------- operator --

it('denies an operator outside the cohort at the same pilot branch', function () {
    $branch = ktpPilotBranch();
    ktpPilotOperator($branch);

    $outsider = User::factory()->create();
    rmeMakeAdminClinicActive($outsider, $branch);

    pilotParse($outsider)->assertNotFound();
    expect(pilotReason($outsider))->toBe(KtpCameraOcrPilotDecision::OPERATOR_NOT_IN_PILOT);

    // The existing manual flow is untouched for the outsider.
    $this->actingAs($outsider)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('data-ocr-enabled="0"', false)
        ->assertSee('Unggah foto KTP secara manual');
});

it('lets a non-pilot operator still register a patient manually', function () {
    $branch = ktpPilotBranch();
    ktpPilotOperator($branch);
    $outsider = User::factory()->create();
    rmeMakeAdminClinicActive($outsider, $branch);

    $this->actingAs($outsider)->post(route('settings.patients.store'), [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'medical_record_number' => 'MRN-PILOT-OUT-1',
        'name' => 'Pasien Manual Fiktif',
        'ktp_ocr_applied' => '0',
    ])->assertSessionHasNoErrors();

    expect(Patient::where('name', 'Pasien Manual Fiktif')->exists())->toBeTrue();
});

// ----------------------------------------------------------------- branch --

it('denies a cohort operator working at another branch', function () {
    $pilotBranch = ktpPilotBranch('KTPP');
    $otherBranch = ktpPilotBranch('KTPX');

    $operator = User::factory()->create();
    rmeMakeAdminClinicActive($operator, $otherBranch);
    ktpPilotScope(['operator_user_ids' => (string) $operator->id, 'branch_codes' => $pilotBranch->code]);
    ktpPilotFlag(true);

    pilotParse($operator)->assertNotFound();
    expect(pilotReason($operator))->toBe(KtpCameraOcrPilotDecision::BRANCH_NOT_IN_PILOT);
});

it('never trusts a branch_id supplied in the request', function () {
    $pilotBranch = ktpPilotBranch('KTPP');
    $otherBranch = ktpPilotBranch('KTPX');

    $operator = User::factory()->create();
    rmeMakeAdminClinicActive($operator, $otherBranch);
    ktpPilotScope(['operator_user_ids' => (string) $operator->id, 'branch_codes' => $pilotBranch->code]);
    ktpPilotFlag(true);

    $this->actingAs($operator)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), [
            ...ktpConsent(),
            'branch_id' => $pilotBranch->id,
            'lines' => [['text' => 'NIK : 7371015708900003', 'confidence' => 92]],
        ])
        ->assertNotFound();
});

it('denies a cohort operator who has no selected working branch', function () {
    $branch = ktpPilotBranch();
    $operator = userInRole('Admin Klinik'); // never went online
    ktpPilotScope(['operator_user_ids' => (string) $operator->id, 'branch_codes' => $branch->code]);
    ktpPilotFlag(true);

    expect(pilotReason($operator))->toBe(KtpCameraOcrPilotDecision::NO_WORKING_BRANCH);
    pilotDenied(pilotParse($operator));
});

it('denies a governance role whose scope spans every branch, even in the cohort', function () {
    $branch = ktpPilotBranch();
    $supervisor = userInRole('Supervisor RME');
    ktpPilotScope(['operator_user_ids' => (string) $supervisor->id, 'branch_codes' => $branch->code]);
    ktpPilotFlag(true);

    expect(pilotReason($supervisor))->toBe(KtpCameraOcrPilotDecision::NO_WORKING_BRANCH);
});

// ----------------------------------------------------------------- period --

it('honours the pilot period inclusively on the clinical calendar', function (string $now, string $expected) {
    // Clock first: the operator's online context must be fresh at that instant.
    Carbon::setTestNow(Carbon::parse($now));
    $operator = ktpPilotOperator(null, ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-20']);

    expect(pilotReason($operator))->toBe($expected);
})->with([
    'day before start' => ['2026-10-04 08:00:00', KtpCameraOcrPilotDecision::OUTSIDE_PILOT_PERIOD],
    'first day (WITA)' => ['2026-10-04 16:30:00', KtpCameraOcrPilotDecision::ALLOWED], // 00:30 WITA 05 Oct
    'last day' => ['2026-10-20 10:00:00', KtpCameraOcrPilotDecision::ALLOWED],
    'day after end (WITA)' => ['2026-10-20 16:30:00', KtpCameraOcrPilotDecision::OUTSIDE_PILOT_PERIOD], // 00:30 WITA 21 Oct
]);

// ------------------------------------------------ fail-closed configuration --

it('covers nobody when the pilot configuration is invalid', function (array $scope, string $error) {
    $branch = ktpPilotBranch();
    $operator = User::factory()->create();
    rmeMakeAdminClinicActive($operator, $branch);

    ktpPilotScope(array_merge([
        'operator_user_ids' => (string) $operator->id,
        'branch_codes' => $branch->code,
    ], $scope));
    ktpPilotFlag(true);

    expect(pilotReason($operator))->toBe(KtpCameraOcrPilotDecision::PILOT_NOT_CONFIGURED)
        ->and(app(KtpCameraOcrPilotGate::class)->posture()['errors'])->toContain($error);
    pilotParse($operator)->assertNotFound();
})->with([
    'MAIN branch' => [['branch_codes' => 'MAIN'], 'branch_main_not_permitted'],
    'two branches' => [['branch_codes' => 'KTPP,KTPX'], 'too_many_branches'],
    'unknown branch' => [['branch_codes' => 'NOPE'], 'branch_not_active_rme'],
    'garbage operator id' => [['operator_user_ids' => 'abc'], 'invalid_operator_id'],
    'too many operators' => [['operator_user_ids' => '1,2,3,4,5,6'], 'too_many_operators'],
    'no period' => [['starts_on' => null, 'ends_on' => null], 'pilot_period_invalid'],
    'reversed period' => [['starts_on' => '2026-10-20', 'ends_on' => '2026-10-01'], 'pilot_period_reversed'],
    'rolled-over date' => [['starts_on' => '2026-02-31', 'ends_on' => '2026-10-31'], 'pilot_period_invalid'],
    'period too long' => [['starts_on' => '2026-09-01', 'ends_on' => '2026-10-31'], 'pilot_period_too_long'],
    'device required, none approved' => [['require_bound_device' => true], 'device_required_but_none_approved'],
]);

it('covers nobody when the pilot branch is switched off', function (array $attrs) {
    $operator = ktpPilotOperator();
    Branch::query()->where('code', 'KTPP')->update($attrs);

    expect(pilotReason($operator))->toBeIn([
        KtpCameraOcrPilotDecision::PILOT_NOT_CONFIGURED,
        KtpCameraOcrPilotDecision::NO_WORKING_BRANCH,
    ]);
    pilotDenied(pilotParse($operator));
})->with([
    'inactive' => [['is_active' => false]],
    'not RME-enabled' => [['is_rme_enabled' => false]],
]);

it('fails safe to requiring a device unless explicitly disabled', function (?string $value, bool $expected) {
    $keys = ['PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE'];
    foreach ($keys as $key) {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        $config = require base_path('config/patient_ktp_ocr_pilot.php');
        expect($config['require_bound_device'])->toBe($expected);
    } finally {
        putenv('PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE');
        unset($_ENV['PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE'], $_SERVER['PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE']);
    }
})->with([
    'unset' => [null, true],
    'blank' => ['', true],
    'misspelled' => ['flase', true],
    'true' => ['true', true],
    'false' => ['false', false],
    'zero' => ['0', false],
    'off' => ['off', false],
]);

it('keeps its ceilings out of reach of the environment', function () {
    $source = file_get_contents(base_path('config/patient_ktp_ocr_pilot.php'));

    foreach (['max_operators', 'max_branches', 'max_devices', 'max_period_days'] as $key) {
        expect($source)->toMatch("/'".$key."' => \\d+,/");
    }
});

// ----------------------------------------------------------------- device --
//
// A device is bound ONLY by the front-office trusted-device lock, and the gate
// reuses that lock's own evaluate() — so these fixtures build what production
// would: a Front Office account in the lock's cohort, the lock armed for the
// branch, and an approved tablet (active, key-proven, enrolment verified).

/** Pilot branch must be a code the front-office lock's allowlist accepts. */
function pilotFoSetup(array $deviceOverrides = []): array
{
    $branch = ktpPilotBranch('SPN4');
    $device = pilotDevice($branch, array_merge(['enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED], $deviceOverrides));

    $user = User::factory()->create()->assignRole(FrontOfficeRole::NAME);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $branch->id);

    $flags = config('feature_flags.flags');
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = true;
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['env_value'] = null;
    config(['feature_flags.flags' => $flags]);
    config()->set('front_office_device_lock.scope.cohort', $user->id.':SPN4');

    ktpPilotScope([
        'operator_user_ids' => (string) $user->id,
        'branch_codes' => 'SPN4',
        'device_ids' => (string) $device->id,
        'require_bound_device' => true,
    ]);
    ktpPilotFlag(true);

    return [$user, $device, $branch];
}

it('requires an approved bound tablet when the device requirement is on', function () {
    [$user, $device] = pilotFoSetup();
    $key = FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID;

    expect(pilotReason($user))->toBe(KtpCameraOcrPilotDecision::DEVICE_NOT_BOUND)
        ->and(pilotReason($user, [$key => $device->id]))->toBe(KtpCameraOcrPilotDecision::ALLOWED);

    pilotParse($user, [$key => $device->id])->assertOk();

    // Last, because an unbound request is thrown out by the front-office lock's
    // own middleware, which ends this operator's session and online context.
    // The test client keeps ONE session across requests, so the binding from
    // the request above must be cleared explicitly, or this would silently
    // re-send it and measure nothing.
    $this->flushSession();
    pilotDenied(pilotParse($user));
});

it('refuses a bound tablet that is not on the pilot allowlist', function () {
    [$user, $device, $branch] = pilotFoSetup();
    $other = pilotDevice($branch, ['enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED]);
    $key = FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID;

    expect(pilotReason($user, [$key => $other->id]))->toBe(KtpCameraOcrPilotDecision::DEVICE_NOT_APPROVED);
    expect(pilotReason($user, [$key => 'not-a-number']))->toBe(KtpCameraOcrPilotDecision::DEVICE_NOT_BOUND);
    pilotDenied(pilotParse($user, [$key => $other->id]));
});

it('stops honouring an approved tablet the moment it stops being eligible', function (array $attrs) {
    [$user, $device] = pilotFoSetup();
    $key = FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID;
    expect(pilotReason($user, [$key => $device->id]))->toBe(KtpCameraOcrPilotDecision::ALLOWED);

    if (isset($attrs['other_branch'])) {
        $attrs = ['branch_id' => ktpPilotBranch('KTPX')->id];
    }
    $device->forceFill($attrs)->save();

    expect(pilotReason($user, [$key => $device->id]))->not->toBe(KtpCameraOcrPilotDecision::ALLOWED);
    pilotDenied(pilotParse($user, [$key => $device->id]));
})->with([
    'revoked' => [['status' => DoctorDevice::STATUS_REVOKED]],
    'unverified identity' => [['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]],
    // Security review MEDIUM-2: enrolment must count, exactly as the lock counts it.
    'enrolment not verified' => [['enrollment_status' => DoctorDevice::ENROLLMENT_PENDING]],
    'moved to another branch' => [['other_branch' => true]],
]);

it('stops honouring a bound tablet once the front-office device lock is switched off', function () {
    // Security review LOW-3: with the lock off nothing revalidates the binding,
    // so a stale session key must not keep granting OCR.
    [$user, $device] = pilotFoSetup();
    $key = FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID;
    expect(pilotReason($user, [$key => $device->id]))->toBe(KtpCameraOcrPilotDecision::ALLOWED);

    $flags = config('feature_flags.flags');
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = false;
    config(['feature_flags.flags' => $flags]);

    expect(pilotReason($user, [$key => $device->id]))->toBe(KtpCameraOcrPilotDecision::DEVICE_NOT_BOUND);
});

it('has no device definition of its own (reuses the front-office lock)', function () {
    $source = file_get_contents(app_path('Modules/Patient/Services/KtpCameraOcrPilotGate.php'));

    expect($source)->toContain('$this->deviceLock->evaluate($user, $request)')
        ->and($source)->toContain('isEnrollmentVerified()');
});

// ----------------------------------------------------------------- safety --

it('writes nothing while deciding for an operator with a fresh context', function () {
    $operator = ktpPilotOperator();
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes++;
        }
    });

    app(KtpCameraOcrPilotGate::class)->decide($operator, Request::create('/'));
    app(KtpCameraOcrPilotGate::class)->posture();

    expect($writes)->toBe(0);
});

it('only narrows when an expired online context is lazily retired on the request path', function () {
    // Security review MEDIUM-1, pinned honestly: the canonical online-context
    // service retires an EXPIRED context when the branch is resolved. That is
    // the only write, it is not the gate's own, and it ends in a denial.
    $operator = ktpPilotOperator();
    DB::table('trx_user_online_contexts')->where('user_id', $operator->id)
        ->update(['last_seen_at' => now()->subDays(3)]);

    expect(pilotReason($operator))->toBe(KtpCameraOcrPilotDecision::NO_WORKING_BRANCH);
});

it('never writes from posture(), even with a stale context present', function () {
    $operator = ktpPilotOperator();
    DB::table('trx_user_online_contexts')->where('user_id', $operator->id)
        ->update(['last_seen_at' => now()->subDays(3)]);
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes++;
        }
    });

    app(KtpCameraOcrPilotGate::class)->posture();

    expect($writes)->toBe(0);
});

it('carries no PII in a decision', function () {
    $operator = ktpPilotOperator();

    expect(array_keys(app(KtpCameraOcrPilotGate::class)->decide($operator)->toArray()))
        ->toBe(['allowed', 'reason', 'user_id', 'branch_id', 'device_id']);
});

// ----------------------------------------------------------------- command --

it('reports INERT at rest and never arms anything', function () {
    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--strict' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true);

    expect($report['verdict'])->toBe('INERT')
        ->and($report['everyone_mode_exists'])->toBeFalse()
        ->and(config('feature_flags.flags')['patient.ktp_camera_ocr']['default'])->toBeFalse();
});

it('reports ARMED for a valid pilot and evaluates one operator', function () {
    $operator = ktpPilotOperator();

    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--strict' => true, '--user' => (string) $operator->id]))->toBe(0);
    $report = json_decode(Artisan::output(), true);

    expect($report['verdict'])->toBe('ARMED')
        ->and($report['posture']['operator_user_ids'])->toBe([$operator->id])
        ->and($report['user_decision']['reason'])->toBe(KtpCameraOcrPilotDecision::ALLOWED);
});

it('fails --strict when the flag is on but the scope covers nobody', function () {
    ktpPilotFlag(true);

    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--strict' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true)['verdict'])->toBe('MISCONFIGURED');
});

it('reports ARMED_UNREACHABLE when a device is required but the device lock is off', function () {
    $branch = ktpPilotBranch();
    $device = pilotDevice($branch);
    ktpPilotOperator($branch, ['require_bound_device' => true, 'device_ids' => (string) $device->id]);

    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--strict' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true)['verdict'])->toBe('ARMED_UNREACHABLE');
});

it('writes nothing when evaluating an operator, even with a stale context', function () {
    $operator = ktpPilotOperator();
    DB::table('trx_user_online_contexts')->where('user_id', $operator->id)
        ->update(['last_seen_at' => now()->subDays(3)]);
    $before = DB::table('trx_user_online_contexts')->where('user_id', $operator->id)->first();

    Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--user' => (string) $operator->id]);

    expect(DB::table('trx_user_online_contexts')->where('user_id', $operator->id)->first())->toEqual($before);
});
