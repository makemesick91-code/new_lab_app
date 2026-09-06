<?php

namespace App\Support\Android;

use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Services\Foundation\FeatureFlagService;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — is the next sprint allowed to
 * start?
 *
 * Answers one question: can an operator install the already-approved production
 * APK on exactly one already-approved tablet, and arm enforcement for exactly
 * one already-approved doctor, without improvising anything?
 *
 * It reads configuration and the recorded release manifest. It signs nothing,
 * builds nothing, installs nothing, opens no vault, reaches no network, touches
 * no device and holds no credential. A test asserts that this file contains
 * none of the primitives that could do any of those things, because a
 * preparation gate that can act is a preparation gate that will one day act.
 *
 * Rules live in config/android_release.php rather than inline. That is the
 * Phase 3 lesson: a scanner whose own source matches the strings it forbids
 * reddens on a codebase that is obeying it, and gets deleted for being wrong.
 */
class Phase4aPilotPreparationScanner
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly AndroidDoctorEnforcementScope $scope,
        private readonly string $basePath,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $checks = array_merge(
            $this->pilotAuthorityChecks(),
            $this->nonDestructiveModelChecks(),
            $this->deferralChecks(),
            $this->enforcementScopeChecks(),
            $this->boundaryChecks(),
            $this->releaseArtifactChecks(),
            $this->updateAndRollbackChecks(),
            $this->preparationHygieneChecks(),
        );

        $failed = array_values(array_filter($checks, fn (array $c): bool => $c['status'] === 'FAIL'));
        $watch = array_values(array_filter($checks, fn (array $c): bool => $c['status'] === 'WATCH'));
        $status = $failed !== [] ? 'FAIL' : ($watch !== [] ? 'WATCH' : 'GO');
        $boundary = $this->boundary();

        return [
            'status' => $status,
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'PASS')),
                'watch' => count($watch),
                'failed' => count($failed),

                // Derived from the verdict, never asserted independently. A
                // summary field that can disagree with the checks under it is
                // the false green this whole family of gates exists to prevent.
                'phase4a_pilot_preparation' => $status === 'GO',

                'preparation_state' => (string) config('android_release.phase_4a.preparation.state'),
                'pilot_model' => (string) config('android_release.device_management.pilot_model'),
                'factory_reset_required' => config('android_release.phase_4a.factory_reset_required') === true,
                'device_owner_required' => config('android_release.phase_4a.device_owner_required') === true,
                'full_kiosk_required' => config('android_release.phase_4a.full_kiosk_required') === true,

                'enforcement_scope_mode' => $this->scope->mode(),
                'enforcement_scope_usable' => $this->scope->isUsable(),
                'enforcement_flag_armed' => $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG),

                // Every one of these is a thing this sprint did not do.
                'apk_distributed' => $boundary['apk_distributed'] ?? null,
                'apk_installed' => $boundary['apk_installed'] ?? null,
                'tablet_touched' => $boundary['tablet_touched'] ?? null,
                'adb_used' => $boundary['adb_used'] ?? null,
                'device_enrolled' => $boundary['device_enrolled'] ?? null,
                'pilot_activated' => $boundary['pilot_activated'] ?? null,
                'pilot_browser_denial_active' => $boundary['pilot_browser_denial_active'] ?? null,
                'global_enforcement_active' => $boundary['global_enforcement_active'] ?? null,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // A. Pilot authority
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function pilotAuthorityChecks(): array
    {
        $doctor = trim((string) config('android_release.enforcement.owner_signoff.pilot_doctor'));
        $branch = trim((string) config('android_release.enforcement.owner_signoff.pilot_branch'));
        $label = trim((string) config('android_release.phase_4a.pilot_device_label'));
        $authorized = config('android_release.enforcement.owner_signoff.phase_4a_pilot_authorized') === true;

        $missing = [];

        foreach (['pilot_doctor' => $doctor, 'pilot_branch' => $branch, 'pilot_device_label' => $label] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        if (! $authorized) {
            $missing[] = 'phase_4a_pilot_authorized';
        }

        $checks = [$this->check(
            'pilot_authority_declared',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === []
                ? "Owner-approved pilot scope is recorded: {$doctor} at {$branch} on {$label}."
                : 'Pilot authority is incomplete: '.implode(', ', $missing).'.',
        )];

        // A serial identifies one physical object and is useless for running a
        // pilot. Committing one would be a hardware identifier nobody asked for.
        $logical = config('android_release.real_device_preflight.device_serial_may_be_committed') === false
            && (string) config('android_release.real_device_preflight.device_reference_style') === 'logical_label';

        $checks[] = $this->check(
            'pilot_device_reference_is_logical_label',
            $logical ? 'PASS' : 'FAIL',
            $logical
                ? 'The pilot device is referenced by logical label; serials stay out of source control.'
                : 'The device reference policy no longer forbids committing a hardware serial.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // The non-destructive decision
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function nonDestructiveModelChecks(): array
    {
        $destructive = [];

        foreach ([
            'factory_reset_required',
            'device_owner_required',
            'full_kiosk_required',
            'managed_google_play_required',
        ] as $key) {
            if (config('android_release.phase_4a.'.$key) !== false) {
                $destructive[] = $key;
            }
        }

        $checks = [$this->check(
            'phase_4a_is_non_destructive',
            $destructive === [] ? 'PASS' : 'FAIL',
            $destructive === []
                ? 'Phase 4A requires no factory reset, no Device Owner, no kiosk and no Managed Google Play.'
                : 'Phase 4A has become destructive again: '.implode(', ', $destructive).' is no longer false.',
        )];

        $model = (string) config('android_release.device_management.pilot_model');
        $supported = (array) config('android_release.device_management.supported_models');
        $declared = (string) config('android_release.phase_4a.model');

        // Two ways to get this wrong: point the pilot at a model nobody
        // supports, or let the two places that name a model drift apart.
        $ok = $model !== '' && in_array($model, $supported, true) && $model === $declared;

        $checks[] = $this->check(
            'phase_4a_model_is_supported',
            $ok ? 'PASS' : 'FAIL',
            $ok
                ? "The pilot uses the supported '{$model}' model."
                : "Pilot model '{$model}' is not a supported model, or disagrees with phase_4a.model '{$declared}'.",
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // Rule 147 — deferral, not deletion
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function deferralChecks(): array
    {
        $deferred = (array) config('android_release.phase_4a.deferred_to_dedicated_device_phase');
        $applicable = (array) config('android_release.phase_4a.acceptance_checks');
        $historical = (array) config('android_release.pilot.acceptance_checks');

        // Iterated from the CANONICAL set, not from the deferral list. Walking
        // the deferral list would let an entry be removed from it and disappear
        // from the loop in the same move — the requirement gone, the check
        // green. The canonical set is the authority for what is owed; the
        // deferral list only says where it went.
        $canonical = (array) config('android_release.scanner.dedicated_device_kiosk_checks');
        $problems = [];

        foreach ($canonical as $check) {
            // Absent from the deferral list is a deleted requirement wearing a
            // deferral's clothes.
            if (! in_array($check, $deferred, true)) {
                $problems[] = "{$check} is no longer recorded as deferred";
            }

            // Present in the Phase 4A matrix is a requirement the pilot still
            // cannot meet on a tablet that was not wiped.
            if (in_array($check, $applicable, true)) {
                $problems[] = "{$check} is deferred yet still required by Phase 4A";
            }

            if (! in_array($check, $historical, true)) {
                $problems[] = "{$check} is not in the historical acceptance record";
            }
        }

        if ($canonical === []) {
            $problems[] = 'no canonical kiosk check set is declared, so nothing can be proven deferred';
        }

        $checks = [$this->check(
            'deferred_kiosk_checks_declared',
            $problems === [] ? 'PASS' : 'FAIL',
            $problems === []
                ? count($canonical).' Device Owner and lock-task checks are deferred to a dedicated-device phase, and none of them is silently dropped.'
                : 'Deferral is inconsistent: '.implode('; ', $problems).'.',
        )];

        $controls = array_values(array_filter(
            (array) config('android_release.phase_4a.compensating_controls'),
            fn ($c): bool => is_string($c) && trim($c) !== '',
        ));

        // The two that carry the security weight. Screen lock and USB debugging
        // are hygiene; these two are the reason the boundary still exists.
        $required = ['app_only_boundary_enforced_server_side', 'pilot_enforcement_scope_armed_before_app_only_claimed'];
        $missing = array_values(array_diff($required, $controls));

        $checks[] = $this->check(
            'deferral_has_compensating_controls',
            $controls !== [] && $missing === [] ? 'PASS' : 'FAIL',
            $controls !== [] && $missing === []
                ? count($controls).' compensating controls replace the deferred kiosk properties.'
                : ($controls === []
                    ? 'Kiosk checks are deferred with no compensating control recorded.'
                    : 'Compensating controls are missing: '.implode(', ', $missing).'.'),
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // Enforcement scope: available, and inert
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function enforcementScopeChecks(): array
    {
        $mode = $this->scope->mode();
        $modes = (array) config('android_release.enforcement.scope.modes');
        $known = in_array($mode, $modes, true);

        $checks = [$this->check(
            'enforcement_scope_mechanism_available',
            $known ? 'PASS' : 'FAIL',
            $known
                ? "A pilot-scoped enforcement mechanism exists and is set to '{$mode}'."
                : "Enforcement scope mode '{$mode}' is not one of the declared modes.",
        )];

        // Fleet-wide denial belongs to Phase 5 and has its own prerequisites.
        // During Phase 4A it must not be permitted, whatever the mode says.
        $globalPermitted = $this->scope->globalPermitted();

        $checks[] = $this->check(
            'global_scope_not_permitted_in_phase_4a',
            $globalPermitted ? 'FAIL' : 'PASS',
            $globalPermitted
                ? 'Fleet-wide doctor enforcement is permitted. That is a Phase 5 decision and must not ship armed in Phase 4A.'
                : 'Fleet-wide doctor enforcement is not permitted; only a declared pilot scope can enforce.',
        );

        $armed = $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG);
        $configuredOff = config('android_release.enforcement.active') === false
            && config('android_release.enforcement.doctor_browser_login_denied') === false;

        // Two distinct failures, deliberately not collapsed.
        //
        // The first is enforcement live in a preparation sprint. The second is
        // subtler and is the one the scope mechanism creates: the flag armed
        // while the scope covers nobody. That state denies no doctor anything,
        // so it cannot lock a clinic out — but an operator reading only the flag
        // would believe doctors were locked to devices when they are not. A
        // silent no-op in a security control has to be loud somewhere, and this
        // is where.
        if ($armed && ! $this->scope->isUsable()) {
            $detail = 'The enforcement flag is armed while the scope covers nobody ('
                .implode(', ', $this->scope->invalidReasons()).'). No doctor is enforced; do not read the flag as protection.';
        } elseif ($armed || ! $configuredOff) {
            $detail = 'Doctor device enforcement is live. A preparation sprint must ship it off.';
        } else {
            $detail = 'Doctor device enforcement is off: the flag is not armed and no browser denial is configured.';
        }

        $checks[] = $this->check(
            'enforcement_inactive',
            ($armed || ! $configuredOff) ? 'FAIL' : 'PASS',
            $detail,
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // B/O. Preparation is not activation; approval is not distribution
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function boundary(): array
    {
        return (array) config('android_release.phase_4a.activation_boundary');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function boundaryChecks(): array
    {
        $boundary = $this->boundary();
        $claimed = [];

        foreach ($boundary as $key => $value) {
            if ($value !== false) {
                $claimed[] = (string) $key;
            }
        }

        $checks = [$this->check(
            'activation_boundary_all_false',
            $boundary !== [] && $claimed === [] ? 'PASS' : 'FAIL',
            $boundary === []
                ? 'No activation boundary is recorded, so nothing asserts that the pilot has not started.'
                : ($claimed === []
                    ? count($boundary).' activation claims are all false: nothing was distributed, installed, enrolled or activated.'
                    : 'Activation is claimed for: '.implode(', ', $claimed).'.'),
        )];

        $state = (string) config('android_release.phase_4a.preparation.state');
        $states = (array) config('android_release.phase_4a.preparation.states');
        $terminal = (string) config('android_release.phase_4a.preparation.terminal_preparation_state');
        $activated = (array) config('android_release.phase_4a.preparation.states_that_imply_activation');

        // An unrecognised state is a FAIL, not a pass. "probably_fine" is
        // exactly how an unset decision passes for a made one.
        $isTerminal = $state !== '' && in_array($state, $states, true) && $state === $terminal;

        $checks[] = $this->check(
            'preparation_state_is_terminal',
            $isTerminal ? 'PASS' : 'FAIL',
            $isTerminal
                ? "Preparation stops at '{$state}', the declared terminal preparation state."
                : "Preparation state '{$state}' is not the declared terminal preparation state '{$terminal}', or is not a known state.",
        );

        // Rule 147 the other way round: relaxing the state must not let a
        // later, activated state read as preparation. An unknown state fails
        // here too — it cannot be proven NOT to imply activation.
        $implies = in_array($state, $activated, true) || ! in_array($state, $states, true);

        $checks[] = $this->check(
            'preparation_state_does_not_imply_activation',
            $implies ? 'FAIL' : 'PASS',
            $implies
                ? "State '{$state}' implies the pilot is running, or is unknown and cannot be proven otherwise."
                : "State '{$state}' is a preparation state; it claims no running pilot.",
        );

        $manifest = $this->manifest();
        $approved = ($manifest['approval_status'] ?? null) === 'approved';
        $manifestDistributed = ($manifest['rollout_state']['apk_distributed'] ?? null) === true;
        $configDistributed = ($boundary['apk_distributed'] ?? null) !== false;

        $checks[] = $this->check(
            'approval_does_not_imply_distribution',
            (! $manifestDistributed && ! $configDistributed) ? 'PASS' : 'FAIL',
            (! $manifestDistributed && ! $configDistributed)
                ? 'The release is '.($approved ? 'approved' : 'not approved').' and still undistributed; approval is not delivery.'
                : 'Distribution is claimed. An approved artifact is not a distributed one.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // C. The artifact an operator is about to install
    // -----------------------------------------------------------------------

    /**
     * The recorded release manifest, or an empty array when unreadable.
     *
     * @return array<string,mixed>
     */
    private function manifest(): array
    {
        $relative = (string) config('android_release.phase_4a.release_manifest');
        $directory = trim((string) config('android_release.phase_4a.release_manifest_directory'), '/');

        // The declared path must sit exactly one level inside the declared
        // directory, with no traversal segment anywhere. This class reads; it
        // does not resolve arbitrary paths on behalf of a config value.
        if ($directory === ''
            || str_contains($relative, '..')
            || $relative !== $directory.'/'.basename($relative)) {
            return [];
        }

        $path = $this->basePath.'/'.$relative;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function releaseArtifactChecks(): array
    {
        $manifest = $this->manifest();
        $checks = [];

        $sha = strtolower(trim((string) ($manifest['artifact_sha256'] ?? '')));
        $size = $manifest['artifact_size_bytes'] ?? null;
        $filename = trim((string) ($manifest['apk_filename'] ?? ''));

        // 64 lowercase hex, and nothing shorter. A truncated digest that still
        // "looks like" a hash is how a prefix comparison gets introduced later.
        $shaOk = preg_match('/^[0-9a-f]{64}$/', $sha) === 1
            && is_int($size) && $size > 0
            && $filename !== '';

        $checks[] = $this->check(
            'release_artifact_recorded',
            $shaOk ? 'PASS' : 'FAIL',
            $shaOk
                ? "The signed artifact is recorded: {$filename}, {$size} bytes, full-length SHA-256."
                : 'The release manifest does not record a filename, a positive size and a full-length SHA-256.',
        );

        $pin = strtolower(trim((string) config('android_release.signing.production_certificate_sha256')));
        $signer = strtolower(trim((string) ($manifest['signer_certificate_sha256'] ?? '')));

        // Exact, full-length, constant-time-shaped equality against the pin in
        // SOURCE CONTROL. Never against the copy in the manifest: whoever can
        // swap the APK can swap the file sitting next to it.
        $pinOk = preg_match('/^[0-9a-f]{64}$/', $pin) === 1
            && preg_match('/^[0-9a-f]{64}$/', $signer) === 1
            && hash_equals($pin, $signer);

        $checks[] = $this->check(
            'release_signer_matches_pin',
            $pinOk ? 'PASS' : 'FAIL',
            $pinOk
                ? 'The recorded signer is exactly the pinned production certificate.'
                : 'The recorded signer is not a full-length exact match for the pinned production certificate.',
        );

        $packageId = trim((string) config('android_release.distribution.package_id'));
        $manifestPackage = trim((string) ($manifest['package_name'] ?? ''));
        $versionName = trim((string) ($manifest['version_name'] ?? ''));
        $versionCode = $manifest['version_code'] ?? null;

        $identityOk = $packageId !== ''
            && $manifestPackage !== ''
            && $packageId === $manifestPackage
            && $versionName !== ''
            && is_int($versionCode) && $versionCode >= 1;

        $checks[] = $this->check(
            'release_package_identity_matches_policy',
            $identityOk ? 'PASS' : 'FAIL',
            $identityOk
                ? "The artifact is {$manifestPackage} {$versionName} (versionCode {$versionCode}), matching the permanent package id."
                : 'The recorded application id, versionName or versionCode does not match the permanent package policy.',
        );

        $steps = (array) config('android_release.phase_4a.preinstall_verification');
        $requiredSteps = (array) config('android_release.phase_4a.preinstall_verification_required');
        $missingSteps = array_values(array_diff($requiredSteps, $steps));

        $checks[] = $this->check(
            'preinstall_verification_declared',
            $missingSteps === [] ? 'PASS' : 'FAIL',
            $missingSteps === []
                ? count($steps).' preinstall verification steps must pass before the artifact goes near the tablet.'
                : 'Preinstall verification is missing: '.implode(', ', $missingSteps).'.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // M. Update in place, and getting back out
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function updateAndRollbackChecks(): array
    {
        $contract = [
            'update_contract.signer_mismatch_blocks_update' => true,
            'update_contract.uninstall_destroys_keystore_identity' => true,
            'update_contract.clear_app_data_destroys_keystore_identity' => true,
            'update_contract.in_place_update_preserves_device_identity' => true,
            'update_contract.trust_restored_by_installation_alone' => false,
            'versioning.version_code_decrement_permitted' => false,
            'versioning.version_code_reuse_permitted' => false,
        ];

        $broken = [];

        foreach ($contract as $key => $expected) {
            if (config('android_release.'.$key) !== $expected) {
                $broken[] = $key;
            }
        }

        $checks = [$this->check(
            'update_in_place_contract_recorded',
            $broken === [] ? 'PASS' : 'FAIL',
            $broken === []
                ? 'Update in place requires the same signer and a non-decreasing versionCode; uninstall and clear-data destroy the device identity.'
                : 'The update-in-place contract has drifted: '.implode(', ', $broken).'.',
        )];

        // Uninstall is the cheap reflex that costs a re-enrolment, so the
        // recorded rollback mechanism must not name it as the routine route.
        $mechanism = strtolower((string) config('android_release.versioning.rollback_mechanism'));
        $routineUninstall = str_contains($mechanism, 'uninstall');

        $checks[] = $this->check(
            'rollback_never_routine_uninstall',
            $routineUninstall ? 'FAIL' : 'PASS',
            $routineUninstall
                ? "The recorded rollback mechanism '{$mechanism}' routes through uninstall, which destroys the device identity."
                : 'Rollback is a forward fix; uninstall is not a routine step.',
        );

        $rollback = (array) config('android_release.phase_4a.rollback');
        $cases = [
            'server_side_pilot_problem',
            'device_authorization_problem',
            'bad_apk_behaviour',
            'app_unusable',
            'signer_mismatch',
            'device_identity_lost',
        ];
        $missing = [];

        foreach ($cases as $case) {
            if (trim((string) ($rollback[$case] ?? '')) === '') {
                $missing[] = $case;
            }
        }

        $checks[] = $this->check(
            'rollback_matrix_complete',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === []
                ? count($cases).' failure modes each have a recorded rollback route, decided before activation.'
                : 'No rollback route is recorded for: '.implode(', ', $missing).'.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // D. What preparation is allowed to require
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function preparationHygieneChecks(): array
    {
        $requires = [];

        foreach (['requires_signing_key_access', 'requires_device_access', 'requires_adb'] as $key) {
            if (config('android_release.phase_4a.preparation.'.$key) !== false) {
                $requires[] = $key;
            }
        }

        $checks = [$this->check(
            'preparation_needs_no_key_device_or_adb',
            $requires === [] ? 'PASS' : 'FAIL',
            $requires === []
                ? 'Preparation needs no signing key, no tablet and no adb; it works from public release evidence only.'
                : 'Preparation claims to require: '.implode(', ', $requires).'.',
        )];

        $checklist = trim((string) config('android_release.phase_4a.operator_checklist'));
        $exists = $checklist !== '' && is_file($this->basePath.'/'.$checklist);
        $registered = in_array($checklist, (array) config('android_release.scanner.required_documents'), true);

        $checks[] = $this->check(
            'operator_checklist_present',
            ($exists && $registered) ? 'PASS' : 'FAIL',
            ($exists && $registered)
                ? "The activation operator checklist is present at {$checklist} and is a required document."
                : ($exists
                    ? "Checklist {$checklist} exists but is not a required document, so it can go missing silently."
                    : "The activation operator checklist '{$checklist}' does not exist."),
        );

        $events = array_values(array_filter(
            (array) config('android_release.phase_4a.audit_events_required'),
            fn ($e): bool => is_string($e) && trim($e) !== '',
        ));

        // Which events are mandatory is a rule, so it lives in config.
        $requiredEvents = (array) config('android_release.phase_4a.audit_events_mandatory');
        $missingEvents = array_values(array_diff($requiredEvents, $events));

        $checks[] = $this->check(
            'audit_requirements_declared',
            $missingEvents === [] ? 'PASS' : 'FAIL',
            $missingEvents === []
                ? count($events).' audit events are required of the activation sprint.'
                : 'Required audit events are not declared: '.implode(', ', $missingEvents).'.',
        );

        return $checks;
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, string $status, string $detail): array
    {
        return ['id' => $id, 'status' => $status, 'detail' => $detail];
    }
}
