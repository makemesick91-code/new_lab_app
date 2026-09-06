<?php

use App\Models\User;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — the preparation contract for a
 * NON-DESTRUCTIVE Phase 4A doctor pilot.
 *
 * WHY THIS SUITE EXISTS
 *
 * The recorded Phase 4A pilot could not be run. `device_management.pilot_model`
 * was `self_owned_device_owner` and six of the thirty-three recorded acceptance
 * checks — device_owner_established, lock_task_active and the four escape
 * blocks — are Device Owner and lock-task properties. Android grants Device
 * Owner only on a device with no accounts, i.e. after a factory reset, and the
 * owner has refused a factory reset for this pilot. So an activation agent
 * reading only the configuration had three options: wipe the tablet against an
 * explicit instruction, mark six checks PASS falsely, or stall mid-pilot.
 *
 * Removing those six checks is the easy half. The half that matters is rule
 * 147: lock task was ALSO what physically stopped a doctor reaching a browser,
 * so dropping it moves the app-only boundary entirely onto the server. And the
 * server had exactly one switch — a single global boolean whose own description
 * says it "DENIES browser login for every account holding the Doctor role".
 * There was no way to enforce app-only for one pilot doctor. The only two
 * reachable states were fleet-wide clinical lockout, or an "app-only pilot"
 * with no app-only property at all.
 *
 * These tests pin both halves, and pin that preparation is not activation.
 */
function phase4aScanner(): Phase4aPilotPreparationScanner
{
    return app(Phase4aPilotPreparationScanner::class);
}

function phase4aCheck(string $id): array
{
    $checks = collect(phase4aScanner()->scan()['checks'])->keyBy('id');

    expect($checks->has($id))->toBeTrue("Check {$id} disappeared from the preparation scanner.");

    return $checks->get($id);
}

/**
 * Set the enforcement scope the way a deployment would.
 *
 * Two files, deliberately. The mode and the pilot doctor are host values in
 * config/doctor_device_enforcement.php; fleet-wide permission is a
 * source-controlled fact in config/android_release.php and is not reachable from
 * a host at all. This helper hides the split so the tests below read as one
 * decision, which is how an operator thinks about it.
 */
function phase4aScope(array $overrides): void
{
    if (array_key_exists('global_permitted', $overrides)) {
        config()->set('android_release.enforcement.scope', array_merge(
            (array) config('android_release.enforcement.scope'),
            ['global_permitted' => $overrides['global_permitted']],
        ));

        unset($overrides['global_permitted']);
    }

    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        $overrides,
    ));
}

// ---------------------------------------------------------------------------
// A. PILOT AUTHORITY
// ---------------------------------------------------------------------------

it('records the exact pilot doctor, branch and logical device label', function () {
    expect(config('android_release.enforcement.owner_signoff.pilot_doctor'))->toBe('drg Karmila');
    expect(config('android_release.enforcement.owner_signoff.pilot_branch'))->toBe('Cabang Sunu');
    expect(config('android_release.phase_4a.pilot_device_label'))->toBe('PHASE4A_PILOT_TABLET_01');

    expect(phase4aCheck('pilot_authority_declared')['status'])->toBe('PASS');
});

it('refuses a hardware serial as the device reference and keeps the label logical', function () {
    expect(config('android_release.real_device_preflight.device_serial_may_be_committed'))->toBeFalse();
    expect(config('android_release.real_device_preflight.device_reference_style'))->toBe('logical_label');
    expect(phase4aCheck('pilot_device_reference_is_logical_label')['status'])->toBe('PASS');
});

it('fails pilot authority when the doctor or branch authority is blank', function () {
    config()->set('android_release.enforcement.owner_signoff.pilot_doctor', '');

    expect(phase4aCheck('pilot_authority_declared')['status'])->toBe('FAIL');
});

it('fails when the declared pilot device label is missing', function () {
    config()->set('android_release.phase_4a.pilot_device_label', '');

    expect(phase4aCheck('pilot_authority_declared')['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// N. THE NON-DESTRUCTIVE DECISION
// ---------------------------------------------------------------------------

it('records Phase 4A as non-destructive: no factory reset, no Device Owner, no kiosk', function () {
    expect(config('android_release.phase_4a.factory_reset_required'))->toBeFalse();
    expect(config('android_release.phase_4a.device_owner_required'))->toBeFalse();
    expect(config('android_release.phase_4a.full_kiosk_required'))->toBeFalse();
    expect(config('android_release.phase_4a.managed_google_play_required'))->toBeFalse();

    expect(phase4aCheck('phase_4a_is_non_destructive')['status'])->toBe('PASS');
});

it('points the pilot at a supported non-destructive model', function () {
    expect(config('android_release.device_management.pilot_model'))->toBe('self_owned_non_destructive');
    expect(config('android_release.device_management.supported_models'))
        ->toContain('self_owned_non_destructive');

    expect(phase4aCheck('phase_4a_model_is_supported')['status'])->toBe('PASS');
});

it('fails when the pilot model is not one of the supported models', function () {
    config()->set('android_release.device_management.pilot_model', 'invented_model');

    expect(phase4aCheck('phase_4a_model_is_supported')['status'])->toBe('FAIL');
});

it('fails the non-destructive check the moment a factory reset is required again', function () {
    config()->set('android_release.phase_4a.factory_reset_required', true);

    expect(phase4aCheck('phase_4a_is_non_destructive')['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// RULE 147 — the six kiosk checks are DEFERRED, never silently dropped
// ---------------------------------------------------------------------------

it('defers every Device Owner and lock-task acceptance check instead of deleting it', function () {
    $deferred = (array) config('android_release.phase_4a.deferred_to_dedicated_device_phase');
    $applicable = (array) config('android_release.phase_4a.acceptance_checks');

    foreach (['device_owner_established', 'lock_task_active', 'home_escape_blocked',
        'recents_escape_blocked', 'external_browser_escape_blocked', 'reboot_returns_to_clinic_app'] as $check) {
        expect($deferred)->toContain($check);
        expect($applicable)->not->toContain($check);
    }

    // The originals stay in the historical Phase 3.5 list. Deferral is a
    // reclassification, not an erasure of what a dedicated device must prove.
    expect(config('android_release.pilot.acceptance_checks'))->toContain('device_owner_established');

    expect(phase4aCheck('deferred_kiosk_checks_declared')['status'])->toBe('PASS');
});

it('fails when a deferred kiosk check is quietly dropped from both lists', function () {
    config()->set(
        'android_release.phase_4a.deferred_to_dedicated_device_phase',
        array_values(array_diff(
            (array) config('android_release.phase_4a.deferred_to_dedicated_device_phase'),
            ['lock_task_active'],
        )),
    );

    expect(phase4aCheck('deferred_kiosk_checks_declared')['status'])->toBe('FAIL');
});

it('fails when a deferred kiosk check is smuggled back into Phase 4A acceptance', function () {
    config()->set('android_release.phase_4a.acceptance_checks', array_merge(
        (array) config('android_release.phase_4a.acceptance_checks'),
        ['device_owner_established'],
    ));

    expect(phase4aCheck('deferred_kiosk_checks_declared')['status'])->toBe('FAIL');
});

it('requires a compensating control for every capability the deferral gives up', function () {
    $controls = (array) config('android_release.phase_4a.compensating_controls');

    expect($controls)
        ->toContain('device_screen_lock_enabled')
        ->toContain('usb_debugging_disabled_and_verified')
        ->toContain('install_unknown_sources_permission_revoked_after_install')
        ->toContain('app_only_boundary_enforced_server_side')
        ->toContain('pilot_enforcement_scope_armed_before_app_only_claimed');

    expect(phase4aCheck('deferral_has_compensating_controls')['status'])->toBe('PASS');
});

it('fails when the deferral is recorded with no compensating control at all', function () {
    config()->set('android_release.phase_4a.compensating_controls', []);

    expect(phase4aCheck('deferral_has_compensating_controls')['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// J/K. PILOT-SCOPED ENFORCEMENT — PREPARED, INERT, AND NEVER GLOBAL
// ---------------------------------------------------------------------------

it('ships the scope mechanism defaulting to pilot with no target, so nothing is enforced', function () {
    expect(config('doctor_device_enforcement.scope.mode'))->toBe(AndroidDoctorEnforcementScope::MODE_PILOT);
    expect(config('doctor_device_enforcement.scope.pilot.doctor_user_id'))->toBeNull();

    // Not in the runtime config, and not settable from a host. Fleet-wide denial
    // is a reviewed source-control change, because its blast radius is every
    // doctor in every branch.
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();
    expect(config('doctor_device_enforcement.scope'))->not->toHaveKey('global_permitted');

    $scope = app(AndroidDoctorEnforcementScope::class);

    expect($scope->isPilotMode())->toBeTrue();
    expect($scope->isUsable())->toBeFalse();
    expect($scope->coversUser(1))->toBeFalse();
    expect($scope->invalidReasons())->toContain('pilot_mode_without_doctor_user_id');
});

it('narrows enforcement to exactly the declared pilot doctor', function () {
    phase4aScope(['mode' => AndroidDoctorEnforcementScope::MODE_PILOT, 'pilot' => ['doctor_user_id' => 4242]]);

    $scope = app(AndroidDoctorEnforcementScope::class);

    expect($scope->isUsable())->toBeTrue();
    expect($scope->coversUser(4242))->toBeTrue();
    expect($scope->coversUser(4243))->toBeFalse();
});

it('never widens to the whole fleet when the pilot target is unusable', function () {
    foreach ([null, 0, -1, '', 'abc'] as $bad) {
        phase4aScope(['mode' => AndroidDoctorEnforcementScope::MODE_PILOT, 'pilot' => ['doctor_user_id' => $bad]]);

        $scope = app(AndroidDoctorEnforcementScope::class);

        expect($scope->isUsable())->toBeFalse();
        expect($scope->coversUser(1))->toBeFalse();
        expect($scope->coversUser(4242))->toBeFalse();
    }
});

it('refuses fleet-wide scope while global enforcement is not permitted', function () {
    phase4aScope(['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED, 'global_permitted' => false]);

    $scope = app(AndroidDoctorEnforcementScope::class);

    expect($scope->coversUser(1))->toBeFalse();
    expect($scope->invalidReasons())->toContain('global_scope_not_permitted');
});

it('applies fleet-wide scope only when global enforcement is explicitly permitted', function () {
    phase4aScope(['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED, 'global_permitted' => true]);

    $scope = app(AndroidDoctorEnforcementScope::class);

    expect($scope->isUsable())->toBeTrue();
    expect($scope->coversUser(1))->toBeTrue();
    expect($scope->coversUser(999))->toBeTrue();
});

it('treats an unknown scope mode as covering nobody rather than guessing', function () {
    phase4aScope(['mode' => 'ludicrous', 'global_permitted' => true]);

    $scope = app(AndroidDoctorEnforcementScope::class);

    expect($scope->coversUser(1))->toBeFalse();
    expect($scope->invalidReasons())->toContain('unknown_scope_mode');
});

// ---------------------------------------------------------------------------
// The gate honours the scope — and stays inert while the flag is off
// ---------------------------------------------------------------------------

it('leaves every doctor unenforced while the enforcement flag is off, whatever the scope says', function () {
    phase4aScope(['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED, 'global_permitted' => true]);

    $gate = app(DoctorAppLoginGate::class);
    $user = new User(['name' => 'x']);
    $user->id = 7;

    expect($gate->enforcementEnabled())->toBeFalse();
    expect($gate->denyBrowserSessionReason($user, request()))->toBeNull();
});

it('narrows through the gate by role and by scope together', function () {
    seedAccessControl();

    $inScope = User::factory()->create();
    $inScope->assignRole('Doctor');

    $otherDoctor = User::factory()->create();
    $otherDoctor->assignRole('Doctor');

    $notADoctor = User::factory()->create();

    $gate = app(DoctorAppLoginGate::class);

    phase4aScope([
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $inScope->id],
    ]);

    // The whole point: one doctor enforced, the other left exactly as they are.
    expect($gate->inEnforcementScope($inScope))->toBeTrue();
    expect($gate->inEnforcementScope($otherDoctor))->toBeFalse();

    // Scope narrows; it never widens past the role. A fleet-wide scope must not
    // pull in an account that was never subject to the doctor device rules.
    phase4aScope(['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED, 'global_permitted' => true]);

    expect($gate->inEnforcementScope($otherDoctor))->toBeTrue();
    expect($gate->inEnforcementScope($notADoctor))->toBeFalse();
});

// ---------------------------------------------------------------------------
// B. PREPARATION IS NOT ACTIVATION
// ---------------------------------------------------------------------------

it('stops at ready_for_pilot and never at an activated state', function () {
    expect(config('android_release.phase_4a.preparation.state'))->toBe('ready_for_pilot');
    expect(config('android_release.phase_4a.preparation.terminal_preparation_state'))->toBe('ready_for_pilot');

    expect(phase4aCheck('preparation_state_is_terminal')['status'])->toBe('PASS');
    expect(phase4aCheck('preparation_state_does_not_imply_activation')['status'])->toBe('PASS');
});

it('fails when the preparation state claims the pilot is already running', function () {
    config()->set('android_release.phase_4a.preparation.state', 'pilot_active');

    expect(phase4aCheck('preparation_state_does_not_imply_activation')['status'])->toBe('FAIL');
});

it('fails on an unrecognised preparation state instead of reading it as ready', function () {
    config()->set('android_release.phase_4a.preparation.state', 'probably_fine');

    expect(phase4aCheck('preparation_state_is_terminal')['status'])->toBe('FAIL');
    expect(phase4aCheck('preparation_state_does_not_imply_activation')['status'])->toBe('FAIL');
});

it('asserts every activation boundary is still false', function () {
    foreach ((array) config('android_release.phase_4a.activation_boundary') as $key => $value) {
        expect($value)->toBeFalse("Activation boundary {$key} must ship false.");
    }

    expect(phase4aCheck('activation_boundary_all_false')['status'])->toBe('PASS');
});

it('fails the boundary check when any activation claim flips true', function () {
    config()->set('android_release.phase_4a.activation_boundary.apk_installed', true);

    expect(phase4aCheck('activation_boundary_all_false')['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// O. APPROVED IS NOT DISTRIBUTED
// ---------------------------------------------------------------------------

it('does not read an approved release as a distributed one', function () {
    $manifest = json_decode((string) file_get_contents(
        base_path('docs/evidence/android-release/DaengtisiaMS-Clinic-v0.3.0-phase3.release.json'),
    ), true);

    expect($manifest['approval_status'])->toBe('approved');
    expect($manifest['rollout_state']['apk_distributed'])->toBeFalse();
    expect(config('android_release.phase_4a.activation_boundary.apk_distributed'))->toBeFalse();

    expect(phase4aCheck('approval_does_not_imply_distribution')['status'])->toBe('PASS');
});

it('fails when distribution is claimed on the strength of approval alone', function () {
    config()->set('android_release.phase_4a.activation_boundary.apk_distributed', true);

    expect(phase4aCheck('approval_does_not_imply_distribution')['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// C. RELEASE ARTIFACT — EXACT, NEVER A PREFIX
// ---------------------------------------------------------------------------

it('verifies the recorded artifact against the pinned signer and the package policy', function () {
    expect(phase4aCheck('release_artifact_recorded')['status'])->toBe('PASS');
    expect(phase4aCheck('release_signer_matches_pin')['status'])->toBe('PASS');
    expect(phase4aCheck('release_package_identity_matches_policy')['status'])->toBe('PASS');
});

it('rejects a signer that merely shares a prefix with the pin', function () {
    $pin = (string) config('android_release.signing.production_certificate_sha256');
    config()->set('android_release.signing.production_certificate_sha256', substr($pin, 0, 32));

    expect(phase4aCheck('release_signer_matches_pin')['status'])->toBe('FAIL');
});

it('fails when the recorded application id drifts from the permanent package id', function () {
    config()->set('android_release.distribution.package_id', 'com.example.other');

    expect(phase4aCheck('release_package_identity_matches_policy')['status'])->toBe('FAIL');
});

it('requires the preinstall verification steps to be declared before any install', function () {
    expect((array) config('android_release.phase_4a.preinstall_verification'))
        ->toContain('apk_sha256')
        ->toContain('apksigner_verify')
        ->toContain('signer_matches_pinned_certificate')
        ->toContain('application_id')
        ->toContain('version_code');

    expect(phase4aCheck('preinstall_verification_declared')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// M. UPDATE-IN-PLACE
// ---------------------------------------------------------------------------

it('keeps the update-in-place contract that binds signer identity to device identity', function () {
    expect(config('android_release.update_contract.signer_mismatch_blocks_update'))->toBeTrue();
    expect(config('android_release.update_contract.uninstall_destroys_keystore_identity'))->toBeTrue();
    expect(config('android_release.update_contract.clear_app_data_destroys_keystore_identity'))->toBeTrue();
    expect(config('android_release.versioning.version_code_decrement_permitted'))->toBeFalse();

    expect(phase4aCheck('update_in_place_contract_recorded')['status'])->toBe('PASS');
});

it('fails when uninstall is normalised into an ordinary rollback step', function () {
    config()->set('android_release.versioning.rollback_mechanism', 'uninstall_then_install_older');

    expect(phase4aCheck('rollback_never_routine_uninstall')['status'])->toBe('FAIL');
});

it('records a rollback route for every failure mode the pilot can hit', function () {
    $rollback = (array) config('android_release.phase_4a.rollback');

    foreach (['server_side_pilot_problem', 'device_authorization_problem', 'bad_apk_behaviour',
        'app_unusable', 'signer_mismatch', 'device_identity_lost'] as $case) {
        expect($rollback)->toHaveKey($case);
        expect(trim((string) $rollback[$case]))->not->toBe('');
    }

    expect(phase4aCheck('rollback_matrix_complete')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// D. NO PRIVATE KEY, NO TABLET, NO ADB
// ---------------------------------------------------------------------------

it('declares that preparation needs no signing key, no tablet and no adb', function () {
    expect(config('android_release.phase_4a.preparation.requires_signing_key_access'))->toBeFalse();
    expect(config('android_release.phase_4a.preparation.requires_device_access'))->toBeFalse();
    expect(config('android_release.phase_4a.preparation.requires_adb'))->toBeFalse();

    expect(phase4aCheck('preparation_needs_no_key_device_or_adb')['status'])->toBe('PASS');
});

it('keeps the preparation scanner free of anything that could sign, install or reach out', function () {
    $source = (string) file_get_contents(app_path('Support/Android/Phase4aPilotPreparationScanner.php'));

    // Tool names AND the primitives that could invoke anything. Forbidding
    // only the names would let `shell_exec($cmd)` through; forbidding only the
    // primitives would let the scanner grow a hardcoded apksigner path.
    foreach (['apksigner', 'keytool', 'Process::', 'Http::', 'shell_exec', 'proc_open',
        'passthru', 'system(', 'file_put_contents', 'unlink(', 'curl_'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('keeps the governance record free of environment reads and the runtime config free of credentials', function () {
    // The release governance suite already asserts this for
    // config/android_release.php, and this sprint broke it once: a scope block
    // reading the host put an environment read into the file whose whole point
    // is not having one, and the comment explaining the absence tripped the
    // pattern too. Pinned here as well, next to the split it justifies.
    $governance = (string) file_get_contents(config_path('android_release.php'));

    expect($governance)->toStartWith('<?php', 'android_release.php was not read.');
    expect($governance)->not->toMatch('/\benv\s*\(/', 'The governance record must not read the environment.');

    // The runtime config may read the host — that is what it is for — but only
    // for the two non-secret values it declares.
    $runtime = (string) file_get_contents(config_path('doctor_device_enforcement.php'));

    expect($runtime)->toStartWith('<?php', 'doctor_device_enforcement.php was not read.');
    expect($runtime)->not->toMatch("/config\s*\(\s*['\"]services\./", 'The runtime config must not read service credentials.');

    foreach (['SECRET', 'PASSWORD', 'PASSPHRASE', 'TOKEN', 'KEYSTORE', 'PKCS12', 'PRIVATE_KEY'] as $forbidden) {
        expect($runtime)->not->toContain($forbidden);
    }

    // And the readiness path this sprint adds must be as fork-safe as the one it
    // sits beside.
    foreach (['Support/Android/Phase4aPilotPreparationScanner.php',
        'Support/Android/AndroidDoctorEnforcementScope.php',
        'Console/Commands/AndroidPhase4aPilotReadinessCommand.php'] as $relative) {
        $source = (string) file_get_contents(app_path($relative));

        expect($source)->toStartWith('<?php', $relative.' was not read.');
        expect($source)->not->toMatch('/\benv\s*\(/', $relative.' reads the environment.');
        expect($source)->not->toMatch("/config\s*\(\s*['\"]services\./", $relative.' reads service credentials.');
    }
});

// ---------------------------------------------------------------------------
// The operator checklist and the durable rule must exist to be followed
// ---------------------------------------------------------------------------

it('ships the operator activation checklist the next sprint is told to follow', function () {
    $path = (string) config('android_release.phase_4a.operator_checklist');

    expect($path)->not->toBe('');
    expect(file_exists(base_path($path)))->toBeTrue("Operator checklist {$path} is missing.");
    expect(config('android_release.scanner.required_documents'))->toContain($path);

    expect(phase4aCheck('operator_checklist_present')['status'])->toBe('PASS');
});

it('fails when the operator checklist named by config does not exist', function () {
    config()->set('android_release.phase_4a.operator_checklist', 'docs/runbooks/not-written-yet.md');

    expect(phase4aCheck('operator_checklist_present')['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// The command is the gate an operator actually runs
// ---------------------------------------------------------------------------

it('reports GO with no watch and no failure, and prints the activation boundary', function () {
    $exit = Artisan::call('android:phase4a-pilot-readiness');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('PHASE4A_PILOT_PREPARATION=GO');
    expect($output)->toContain('APK_DISTRIBUTED=false');
    expect($output)->toContain('TABLET_TOUCHED=false');
    expect($output)->toContain('ADB_USED=false');
    expect($output)->toContain('PILOT_ACTIVATED=false');
    expect($output)->toContain('PILOT_BROWSER_DENIAL_ACTIVE=false');
    expect($output)->toContain('GLOBAL_ENFORCEMENT_ACTIVE=false');
});

it('exits non-zero and stops claiming GO once any check fails', function () {
    config()->set('android_release.phase_4a.factory_reset_required', true);

    $exit = Artisan::call('android:phase4a-pilot-readiness');

    expect($exit)->toBe(1);
    expect(Artisan::output())->not->toContain('PHASE4A_PILOT_PREPARATION=GO');
});

it('fails the gate when the enforcement flag is armed while the scope covers nobody', function () {
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = true;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = true;
    config()->set('feature_flags.flags', $flags);

    expect(phase4aCheck('enforcement_inactive')['status'])->toBe('FAIL');
    expect(Artisan::call('android:phase4a-pilot-readiness'))->toBe(1);
});

it('emits machine-readable output without leaking a secret or a hardware identifier', function () {
    Artisan::call('android:phase4a-pilot-readiness', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray();
    expect($decoded['status'])->toBe('GO');
    expect($decoded['summary']['phase4a_pilot_preparation'])->toBeTrue();
    expect($decoded['summary']['pilot_activated'])->toBeFalse();

    $raw = strtolower((string) json_encode($decoded));

    foreach (['begin private key', 'pkcs12', 'passphrase', 'imei', 'serialno'] as $leak) {
        expect($raw)->not->toContain($leak);
    }
});

// ---------------------------------------------------------------------------
// Self-review findings, pinned so they cannot come back
// ---------------------------------------------------------------------------

it('refuses to read a release manifest from outside its declared directory', function () {
    // The first version of this scanner built the manifest path by interpolating
    // a config value. Not exploitable — nothing reaches that config in
    // production — but a read-only auditor should have no traversal surface to
    // reason about, so the path is now pinned to one basename in one directory.
    foreach ([
        '../../../../etc/passwd',
        'docs/evidence/android-release/../../../composer.json',
        'docs/evidence/other/DaengtisiaMS-Clinic-v0.3.0-phase3.release.json',
        'docs/evidence/android-release/nested/manifest.json',
    ] as $hostile) {
        config()->set('android_release.phase_4a.release_manifest', $hostile);

        // With no readable manifest the artifact checks fail closed. They must
        // never pass on a file the scanner was not supposed to open.
        expect(phase4aCheck('release_artifact_recorded')['status'])->toBe('FAIL');
        expect(phase4aCheck('release_signer_matches_pin')['status'])->toBe('FAIL');
    }
});

it('never lets the enforcement scope be widened by a request', function () {
    // The scope is a configuration decision. If a request could reach it, the
    // doctor being enforced would be chosen by the client.
    foreach ([
        'app/Support/Android/AndroidDoctorEnforcementScope.php',
        'app/Support/Android/Phase4aPilotPreparationScanner.php',
    ] as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toStartWith('<?php', $relative.' was not read.');

        foreach (['request(', 'Request $', '$_GET', '$_POST', '$_REQUEST', 'input('] as $forbidden) {
            expect($source)->not->toContain($forbidden, $relative.' reads the request.');
        }
    }
});

it('keeps a pilot target that cannot be compared from narrowing to a coincidence', function () {
    // A float, a numeric string with padding, and a boolean all used to be
    // plausible ways to end up comparing against something that is not the
    // approved doctor. Each of them must resolve to "no target", never to a
    // target that happens to match user 1.
    foreach ([4242.0, ' 4242', '4242 ', true, [4242], '04242abc'] as $unusable) {
        config()->set('doctor_device_enforcement.scope', [
            'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
            'pilot' => ['doctor_user_id' => $unusable],
        ]);

        $scope = app(AndroidDoctorEnforcementScope::class);

        expect($scope->pilotDoctorUserId())->toBeNull();
        expect($scope->isUsable())->toBeFalse();
        expect($scope->coversUser(1))->toBeFalse();
        expect($scope->coversUser(4242))->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// Mutation-driven: the gaps a mutant walked through
// ---------------------------------------------------------------------------

/** Arm the canonical enforcement flag for one test. Named uniquely: Pest shares
 * these functions across files, and colliding with the gate suite's helper would
 * break whichever file loaded second. */
function phase4aArmEnforcement(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $on;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $on;

    config()->set('feature_flags.flags', $flags);
}

/**
 * Run an assertion against a manifest whose fields we control.
 *
 * The scanner reads the manifest off disk and will only read one basename inside
 * one directory, so a fixture has to live there too. This function OWNS the path
 * it writes and removes it in a finally — a leaked fixture inside a governance
 * evidence directory is worse than a leaked temp file, because it looks
 * authoritative.
 */
function withPhase4aManifest(array $overrides, Closure $assert): void
{
    $directory = base_path('docs/evidence/android-release');
    $relative = 'docs/evidence/android-release/phase4a-mutation-fixture.release.json';
    $path = base_path($relative);

    $real = json_decode((string) file_get_contents(
        $directory.'/DaengtisiaMS-Clinic-v0.3.0-phase3.release.json',
    ), true);

    try {
        file_put_contents($path, (string) json_encode(array_merge($real, $overrides)));
        config()->set('android_release.phase_4a.release_manifest', $relative);

        $assert();
    } finally {
        @unlink($path);
    }

    expect(file_exists($path))->toBeFalse('The manifest fixture was not cleaned up.');
}

it('reads a controlled manifest fixture at all, so the assertions below are not vacuous', function () {
    // Presence assertion first. Every check below asserts a FAIL, and a FAIL is
    // also what an unreadable fixture produces — so prove the happy path first,
    // or the three tests after this one prove nothing.
    withPhase4aManifest([], function () {
        expect(phase4aCheck('release_artifact_recorded')['status'])->toBe('PASS');
        expect(phase4aCheck('release_signer_matches_pin')['status'])->toBe('PASS');
    });
});

it('rejects a signer that shares a long prefix with the pin but is not the pin', function () {
    // A mutant that compared the first sixteen characters survived the earlier
    // prefix test, because that test truncated the PIN and so failed on the pin's
    // own format instead of on the comparison. This one keeps both values
    // full-length and well-formed, and differs only in the tail.
    $pin = (string) config('android_release.signing.production_certificate_sha256');
    $lookalike = substr($pin, 0, 16).str_repeat('0', 48);

    expect(strlen($lookalike))->toBe(64);
    expect($lookalike)->not->toBe($pin);

    withPhase4aManifest(['signer_certificate_sha256' => $lookalike], function () {
        expect(phase4aCheck('release_signer_matches_pin')['status'])->toBe('FAIL');
    });
});

it('rejects a truncated artifact digest even though every character is hex', function () {
    // 40 hex characters is a plausible-looking digest and is not a SHA-256. A
    // length-agnostic pattern accepted it.
    withPhase4aManifest(['artifact_sha256' => str_repeat('a', 40)], function () {
        expect(phase4aCheck('release_artifact_recorded')['status'])->toBe('FAIL');
    });
});

it('refuses a traversal path even when it resolves to a perfectly valid manifest', function () {
    // The earlier traversal test pointed at files that were not manifests, so
    // removing the guard still produced a FAIL and the mutant lived. Point the
    // traversal at a REAL, VALID manifest outside the declared directory: with
    // the guard this fails closed, without it the scanner reads a file it was
    // never authorised to open and reports PASS.
    $outside = storage_path('app/phase4a-outside-manifest.json');

    try {
        file_put_contents($outside, (string) file_get_contents(
            base_path('docs/evidence/android-release/DaengtisiaMS-Clinic-v0.3.0-phase3.release.json'),
        ));

        config()->set(
            'android_release.phase_4a.release_manifest',
            'docs/evidence/android-release/../../../storage/app/phase4a-outside-manifest.json',
        );

        expect(phase4aCheck('release_artifact_recorded')['status'])->toBe('FAIL');
        expect(phase4aCheck('release_signer_matches_pin')['status'])->toBe('FAIL');
    } finally {
        @unlink($outside);
    }
});

it('denies the browser to the pilot doctor and leaves every other doctor alone', function () {
    // The behavioural assertion the whole sprint rests on, and the one whose
    // absence let a mutant delete the scope check from the browser path
    // unnoticed: the earlier test called inEnforcementScope() directly, which a
    // mutant bypassing it in denyBrowserSessionReason() does not touch.
    seedAccessControl();

    $pilot = User::factory()->create();
    $pilot->assignRole('Doctor');

    $otherBranchDoctor = User::factory()->create();
    $otherBranchDoctor->assignRole('Doctor');

    phase4aArmEnforcement(true);
    phase4aScope([
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    $gate = app(DoctorAppLoginGate::class);
    $request = Request::create('/');

    // The pilot doctor has no device binding, so a browser is refused.
    expect($gate->denyBrowserSessionReason($pilot, $request))
        ->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);

    // And the doctor down the road is untouched. If this ever returns a deny
    // code, the pilot has become a fleet-wide clinical lockout.
    expect($gate->denyBrowserSessionReason($otherBranchDoctor, $request))->toBeNull();
    expect($gate->denySessionReason($otherBranchDoctor, $request))->toBeNull();

    // Disarming the scope releases the pilot doctor too, with no data change.
    phase4aScope(['pilot' => ['doctor_user_id' => null]]);

    expect($gate->denyBrowserSessionReason($pilot, $request))->toBeNull();
});

it('pins which audit events are mandatory, not merely that the scanner agrees with itself', function () {
    // A mutant deleted `authorization_rejected_with_reason` from the mandatory
    // list and every check stayed green: the scanner diffs the mandatory list
    // against the required list, so weakening the RULE weakens the check with it.
    // A governance rule that only its own consumer validates is not pinned.
    expect(config('android_release.phase_4a.audit_events_mandatory'))
        ->toEqual([
            'authorization_approved',
            'authorization_rejected_with_reason',
            'session_invalidated_with_reason',
        ]);

    // And the wider set the activation sprint owes.
    expect(config('android_release.phase_4a.audit_events_required'))
        ->toContain('device_enrollment_requested')
        ->toContain('authorization_pending_created')
        ->toContain('device_revoked')
        ->toContain('pilot_enforcement_scope_changed');
});

it('pins the canonical kiosk check set the deferral is proven against', function () {
    // Same failure shape as the audit list: the scanner proves the deferral
    // against this set, so the set itself has to be pinned or the proof moves
    // whenever somebody edits the thing being proved.
    expect(config('android_release.scanner.dedicated_device_kiosk_checks'))
        ->toEqual([
            'device_owner_established',
            'lock_task_active',
            'home_escape_blocked',
            'recents_escape_blocked',
            'external_browser_escape_blocked',
            'reboot_returns_to_clinic_app',
        ]);
});
