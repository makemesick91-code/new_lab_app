<?php

namespace App\Support\Android;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5 — read-only posture
 * scan for Android production release governance.
 *
 * Answers one question: is this repository still in a state where a production
 * Android release could be made safely, and is Doctor device enforcement still
 * off? It reads files and the git index. It signs nothing, needs no secret,
 * touches no network, and cannot enable anything.
 *
 * Every rule it enforces is declared in config/android_release.php rather than
 * inline, so this file never contains the literals it forbids — the Phase 3
 * self-match failure class.
 */
class AndroidReleaseGovernanceScanner
{
    /**
     * Why a check reached its verdict.
     *
     * REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1 — a FAIL
     * that says "no key material was found" and a FAIL that says "the index
     * could not be read" are the same status and completely different
     * incidents. Collapsing them cost an operator a full diagnosis: the gate
     * reported the Gradle wrapper "is not tracked by git" when git had merely
     * declined to open a repository owned by another user, and the wrapper was
     * tracked all along. The status stays closed either way; the reason is how
     * a reader learns which door to walk through.
     */
    public const REASON_CLEAN = 'clean';

    public const REASON_TRACKED = 'tracked';

    public const REASON_NOT_TRACKED = 'not_tracked';

    public const REASON_FORBIDDEN_KEY_MATERIAL_FOUND = 'forbidden_key_material_found';

    public const REASON_INDEX_ACCESS_ERROR = 'index_access_error';

    public function __construct(
        private readonly KotlinSourceScanner $kotlin,
        private readonly string $basePath,
    ) {}

    /**
     * Every git invocation this scanner makes goes through here.
     *
     * INFRA-SEC-RUNTIME-1 deliberately leaves the deployed tree owned by root
     * while the application runs as an unprivileged identity. That is the
     * security boundary working, not a misconfiguration — so git's
     * foreign-ownership refusal is not something to switch off. It is
     * something to answer, narrowly: this scanner declares that THIS ONE
     * repository is the one it was constructed to audit, for the length of one
     * process, and says nothing about any other repository on the host.
     *
     * The rejected alternatives all trade the control away for the symptom:
     * a wildcard exemption trusts every repository anyone can create; a
     * persistent global entry survives the process, the deploy and the
     * operator's memory; and running the audit as root makes the privileged
     * identity the only one that can check whether the tree is safe.
     *
     * `-c name=value` is passed as its own argv entries to an argument array,
     * so the path is never parsed by a shell no matter what it contains. The
     * path itself is the constructor's base path, which the container binds
     * from base_path() — it is not, and must never become, request input.
     *
     * @param  array<int,string>  $arguments
     */
    private function git(array $arguments): ProcessResult
    {
        return Process::path($this->basePath)->run(array_merge(
            ['git', '-c', 'safe.directory='.$this->basePath],
            $arguments,
        ));
    }

    /**
     * Turn a failed git invocation into a sentence an operator can act on.
     *
     * The ownership marker lives in config for the same reason every other
     * literal here does: a scanner whose own source contains the strings it
     * matches on eventually matches itself.
     */
    private function indexAccessDetail(string $what, ProcessResult $result): string
    {
        $stderr = $result->errorOutput();

        foreach ((array) config('android_release.scanner.git_ownership_error_markers') as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($stderr, $marker)) {
                return $what.' git refused the repository as foreign-owned, so the index was never read. '
                    .'This is an index ACCESS failure, not a finding about the repository contents.';
            }
        }

        return $what.' git could not read the index. '
            .'This is an index ACCESS failure, not a finding about the repository contents.';
    }

    /**
     * @return array{status:string, checks:array<int,array<string,mixed>>, summary:array<string,mixed>}
     */
    public function scan(): array
    {
        $checks = array_merge(
            $this->releaseSigningChecks(),
            $this->committedSecretChecks(),
            $this->wrapperChecks(),
            $this->documentChecks(),
            $this->governanceDecisionChecks(),
            $this->signingCustodyChecks(),
            $this->directApkDistributionChecks(),
            $this->enforcementChecks(),
            $this->enforcementAuthorityChecks(),
            $this->realDevicePreflightChecks(),
        );

        $failed = array_values(array_filter($checks, fn (array $c): bool => $c['status'] === 'FAIL'));
        $watch = array_values(array_filter($checks, fn (array $c): bool => $c['status'] === 'WATCH'));

        return [
            'status' => $failed !== [] ? 'FAIL' : ($watch !== [] ? 'WATCH' : 'GO'),
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'PASS')),
                'watch' => count($watch),
                'failed' => count($failed),
                // Deliberately reported, never inferred. Phase 3.5 ended with
                // no production key in existence, and saying otherwise would
                // be the single most damaging thing this command could print.
                //
                // PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1 moved this
                // from a hardcoded `false` to the recorded custody fact. A
                // literal would keep printing "no key" forever, including
                // after a key genuinely exists — the same lie pointing the
                // other way. Reading the fact and failing
                // `custody_state_machine_consistent` when it contradicts the
                // certificate pin is what keeps this honest in both
                // directions.
                'production_signing_key_provisioned' => config('android_release.signing.custody.production_signing_key_provisioned') === true,

                // Custody readiness is a DECISION about destinations. It is
                // not a key, not a backup copy, and not a rehearsed recovery.
                // Reported next to the line above so the two are read
                // together and never substituted for one another.
                'signing_custody_status' => config('android_release.signing.custody.status'),

                // RENAMED by PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1.
                //
                // This was `signing_custody_ready_for_provisioning`, and once
                // the check behind it stopped requiring an exact status it
                // became permanently true. A machine-readable field literally
                // named "ready for provisioning" reading true forever, under an
                // invariant that exactly ONE production key may ever exist, is
                // the wrong signal to leave in output an agent may act on. The
                // checks it derives from assert that the PRECONDITIONS were
                // met, so that is what it is now called.
                'signing_custody_provisioning_preconditions_met' => $this->custodyReadyForProvisioning($checks),
                'signing_custody_backups_created' => config('android_release.signing.custody.backup_1_key_copy_created') === true
                    || config('android_release.signing.custody.backup_2_key_copy_created') === true,
                'signing_custody_recovery_verified' => config('android_release.signing.custody.recovery_verified') === true,

                // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1. Reported as
                // two lines rather than one, because they are now two facts
                // and a reader who sees only "certificate: yes" would conclude
                // that `android:verify-release` can authenticate an artifact.
                // It cannot: the pin is what arms it, and the pin is null
                // until PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1.
                'production_certificate_recorded' => $this->isCertificateFingerprint(
                    config('android_release.signing.production_certificate_sha256_recorded'),
                ),
                // Shape-checked with the SAME regex as the line above, not
                // merely "a non-empty string". Security review set the pin to
                // 'TBD' and this reported true while
                // AndroidReleaseArtifactVerifier rejected it on the regex and
                // failed closed — so the command would have printed
                // PRODUCTION_CERTIFICATE_PINNED=true for a verifier that
                // authenticates nothing, which is the exact inversion the
                // printed pair exists to prevent.
                'production_certificate_pinned' => $this->isCertificateFingerprint(
                    config('android_release.signing.production_certificate_sha256'),
                ),
                // The full Phase 4 real-device validation — signed release
                // installed, device enrolled, pilot run — has NOT happened.
                // The hardware preflight below is a different, narrower gate
                // and conflating the two is exactly the claim this refuses.
                'real_device_validation' => false,
                'real_device_hardware_preflight' => $this->preflightPassed($checks),
                'device_enforcement_active' => (bool) config('android_release.enforcement.active'),
            ],
        ];
    }

    /**
     * The release build type must not borrow the debug signing identity, which
     * is a key every Android developer on earth already has.
     */
    private function releaseSigningChecks(): array
    {
        $relative = (string) config('android_release.scanner.app_build_file');
        $path = $this->basePath.'/'.$relative;

        if (! is_file($path)) {
            return [$this->check('release_signing', 'FAIL', "Missing {$relative}.")];
        }

        $source = $this->readBounded($path);

        if ($source === null) {
            return [$this->check('release_signing', 'FAIL', "{$relative} exceeds the scan size bound; refusing to read it.")];
        }

        $stripped = $this->kotlin->stripComments($source);

        // Anchored to android > buildTypes > release, never a file-wide search
        // for `release {`. String literals are preserved deliberately (the
        // forbidden tokens contain one), so a file-wide match is hijackable by
        // any earlier string containing `release {` — the scan would read a
        // decoy and then affirm that the real block is clean.
        $releaseBlock = $this->kotlin->nestedBlockBody(
            $stripped,
            (array) config('android_release.scanner.release_block_path'),
        );

        if ($releaseBlock === null) {
            return [$this->check('release_signing', 'FAIL', 'No release build type found under android > buildTypes.')];
        }

        $checks = [];

        // Non-vacuity, asserted against the EXTRACTED BLOCK rather than the
        // whole file. Checking the file would let a hijacked or empty
        // extraction pass, because the markers still resolve somewhere in a
        // block the signing scan never looked at.
        $missingMarkers = array_values(array_filter(
            (array) config('android_release.scanner.required_release_block_markers'),
            fn (string $marker): bool => ! str_contains($releaseBlock, $marker),
        ));

        $empty = trim($releaseBlock) === '';

        $checks[] = $this->check(
            'release_block_readable',
            ! $empty && $missingMarkers === [] ? 'PASS' : 'FAIL',
            $empty
                ? 'The extracted release build type is empty; the signing scan would be vacuous.'
                : ($missingMarkers === []
                    ? 'Release build type is present and readable after comment stripping.'
                    : 'Markers missing from the extracted release block: '.implode(', ', $missingMarkers).'. The scan would be vacuous.'),
        );

        $found = array_values(array_filter(
            (array) config('android_release.scanner.forbidden_release_signing_tokens'),
            fn (string $token): bool => str_contains($releaseBlock, $token),
        ));

        $checks[] = $this->check(
            'release_not_debug_signed',
            $found === [] ? 'PASS' : 'FAIL',
            $found === []
                ? 'Release build type does not fall back to the debug signing identity.'
                : 'Release build type references debug signing: '.implode(', ', $found),
        );

        return $checks;
    }

    /**
     * Key material in the git index. Checked against the index rather than the
     * working tree: a file that is ignored today still ships if it was ever
     * committed, and history does not forget.
     */
    private function committedSecretChecks(): array
    {
        $checks = [];
        $patterns = (array) config('android_release.scanner.forbidden_tracked_path_patterns');
        $ignorePatterns = (array) config('android_release.scanner.required_gitignore_patterns');

        // Fail closed on an empty or disarmed pattern list.
        //
        // Both checks below are absence assertions, and an absence assertion
        // over an empty pattern list passes on nothing at all — the guard
        // reports PASS while enforcing exactly zero. A mutation that quietly
        // emptied these lists survived the first version of this scanner, so
        // the armed state is itself a check.
        //
        // The armed set is the CANONICAL list, not a hardcoded subset: a
        // hardcoded pair guarded two of six patterns and let the other four —
        // .p12, .pfx and the two properties files that carry the keystore
        // passwords — be deleted in silence.
        $required = (array) config('android_release.scanner.key_material_guard_minimum');
        $disarmed = array_values(array_filter(
            $required,
            fn (string $pattern): bool => ! in_array($pattern, $patterns, true),
        ));

        $ignoreDisarmed = array_values(array_filter(
            (array) config('android_release.scanner.gitignore_guard_minimum'),
            fn (string $pattern): bool => ! in_array($pattern, $ignorePatterns, true),
        ));

        $armed = $required !== [] && $patterns !== [] && $ignorePatterns !== []
            && $disarmed === [] && $ignoreDisarmed === [];

        $checks[] = $this->check(
            'key_material_guard_armed',
            $armed ? 'PASS' : 'FAIL',
            $required === [] || $patterns === [] || $ignorePatterns === []
                ? 'A key-material pattern list is empty, so the checks below would pass on nothing.'
                : ($armed
                    ? 'Key-material guards cover every declared keystore and credential pattern.'
                    : 'Key-material guard no longer covers: '.implode(', ', array_merge($disarmed, $ignoreDisarmed))),
        );

        $result = $this->git(['ls-files']);

        if (! $result->successful()) {
            // Append rather than return: discarding the armed check above would
            // silently shrink the reported check count on this path. The
            // ignore-file check does not need git, so it still runs below.
            //
            // FAIL, not PASS, and that is the whole point. If the gate cannot
            // prove forbidden key material is absent, it must not report that
            // it is. Absence of evidence is not evidence of absence.
            $checks[] = $this->check(
                'no_committed_key_material',
                'FAIL',
                $this->indexAccessDetail('Could not verify that no key material is tracked:', $result),
                self::REASON_INDEX_ACCESS_ERROR,
            );

            return array_merge($checks, [$this->ignoreFileCheck($ignorePatterns)]);
        }

        $tracked = array_filter(explode("\n", trim($result->output())));

        $offenders = array_values(array_filter($tracked, function (string $file) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, basename($file)) || fnmatch($pattern, $file)) {
                    return true;
                }
            }

            return false;
        }));

        $checks[] = $this->check(
            'no_committed_key_material',
            $offenders === [] ? 'PASS' : 'FAIL',
            $offenders === []
                ? 'No signing key material is tracked by git.'
                : 'Tracked key material: '.implode(', ', $offenders),
            $offenders === [] ? self::REASON_CLEAN : self::REASON_FORBIDDEN_KEY_MATERIAL_FOUND,
        );

        $checks[] = $this->ignoreFileCheck($ignorePatterns);

        return $checks;
    }

    /**
     * The ignore file must actually ignore the keystore paths.
     *
     * Parsed into effective rules rather than substring-matched over the raw
     * text. A raw match is satisfied by a comment that merely mentions the
     * pattern, and — worse — by `!android/**` + `/*.jks`, a negation that
     * explicitly UN-ignores keystores while the check reports they are ignored.
     *
     * @param  array<int,string>  $ignorePatterns
     * @return array<string,mixed>
     */
    private function ignoreFileCheck(array $ignorePatterns): array
    {
        $effective = $this->effectiveIgnoreRules();

        $missing = array_values(array_filter(
            $ignorePatterns,
            fn (string $pattern): bool => ! in_array($pattern, $effective, true),
        ));

        return $this->check(
            'keystore_ignored',
            $ignorePatterns !== [] && $missing === [] ? 'PASS' : 'FAIL',
            $ignorePatterns === []
                ? 'No ignore patterns declared; the check would pass on nothing.'
                : ($missing === []
                    ? 'Keystore paths are ignored, so a local key cannot be staged by accident.'
                    : 'Missing or negated ignore patterns: '.implode(', ', $missing)),
        );
    }

    /**
     * Phase 3's exit-126 failure. Git stores 100644 or 100755 and nothing else,
     * so a wrapper that is executable only on a developer's disk checks out
     * unusable in CI.
     */
    private function wrapperChecks(): array
    {
        $relative = (string) config('android_release.scanner.gradle_wrapper');
        $expected = (string) config('android_release.scanner.gradle_wrapper_required_index_mode');

        $result = $this->git(['ls-files', '-s', '--', $relative]);
        $output = trim($result->output());

        // These were one branch, and the merged message was actively
        // misleading: a refused repository was reported as an untracked
        // wrapper, sending an operator to look for a file that was committed
        // the whole time. Same FAIL, different incident, different fix.
        if (! $result->successful()) {
            return [$this->check(
                'gradle_wrapper_executable',
                'FAIL',
                $this->indexAccessDetail("Could not read the index mode of {$relative}:", $result),
                self::REASON_INDEX_ACCESS_ERROR,
            )];
        }

        if ($output === '') {
            return [$this->check(
                'gradle_wrapper_executable',
                'FAIL',
                "{$relative} is not tracked by git.",
                self::REASON_NOT_TRACKED,
            )];
        }

        $mode = explode(' ', $output)[0];

        return [$this->check(
            'gradle_wrapper_executable',
            $mode === $expected ? 'PASS' : 'FAIL',
            $mode === $expected
                ? 'The Gradle wrapper is committed executable.'
                : "The Gradle wrapper is committed {$mode}; CI needs {$expected}.",
            self::REASON_TRACKED,
        )];
    }

    private function documentChecks(): array
    {
        $missing = array_values(array_filter(
            (array) config('android_release.scanner.required_documents'),
            fn (string $doc): bool => ! is_file($this->basePath.'/'.$doc),
        ));

        return [$this->check(
            'governance_documents_present',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === []
                ? 'Every Phase 3.5 governance document is present.'
                : 'Missing governance documents: '.implode(', ', $missing),
        )];
    }

    /**
     * The five decisions Phase 3 left open. Each must be an explicit value, not
     * an absence — "unset" is how an unresolved decision passes for a made one.
     */
    /**
     * REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
     *
     * Google Play must not be a prerequisite for anything, and the APK must be
     * the canonical installable artifact.
     *
     * Note carefully what is NOT done here: a term scan over the active docs.
     * The prose explaining why Play was dropped necessarily names Play, so a
     * term scan would go red on a repository that already obeys the rule —
     * the same trap that bit the Phase 3 TLS guard and the Phase 3.5 read
     * guard. The load-bearing assertion is the machine-readable negatives,
     * which prose cannot satisfy; the term scan only requires that a file
     * naming Play also acknowledges it is superseded or not required, which is
     * what a quiet reintroduction would fail to do.
     */
    private function directApkDistributionChecks(): array
    {
        $checks = [];

        $channel = config('android_release.distribution.canonical_channel');
        $format = config('android_release.distribution.artifact_format');

        $checks[] = $this->check(
            'apk_is_canonical_release_artifact',
            $channel === 'direct_admin_managed_apk' && $format === 'apk' ? 'PASS' : 'FAIL',
            $channel === 'direct_admin_managed_apk' && $format === 'apk'
                ? 'Distribution is direct admin-managed APK installation.'
                : "Canonical channel is '{$channel}' with artifact format '{$format}'; expected direct APK.",
        );

        // The armed set, declared in config rather than hardcoded here — the
        // Phase 3.5 lesson about a guard that covers a subset of what it claims.
        $required = (array) config('android_release.scanner.required_distribution_negatives');
        $stillRequired = [];

        foreach ($required as $key) {
            if (config('android_release.distribution.'.$key) !== false) {
                $stillRequired[] = $key;
            }
        }

        $checks[] = $this->check(
            'google_play_not_required',
            $required !== [] && $stillRequired === [] ? 'PASS' : 'FAIL',
            $required === []
                ? 'No distribution negatives declared; the check would pass on nothing.'
                : ($stillRequired === []
                    ? 'Google Play, Play App Signing and Managed Google Play are all declared unused.'
                    : 'Still declaring a Google Play dependency: '.implode(', ', $stillRequired)),
        );

        $checks[] = $this->check(
            'signing_authority_is_self_managed',
            config('android_release.signing.app_signing_authority') === 'self_managed_daengtisiams' ? 'PASS' : 'FAIL',
            config('android_release.signing.app_signing_authority') === 'self_managed_daengtisiams'
                ? 'The production signing key is owned by DaengtisiaMS, and its loss is declared unrecoverable.'
                : 'Signing authority is not the self-managed DaengtisiaMS key.',
        );

        $checks[] = $this->markerScanCheck(
            'active_authority_acknowledges_supersession',
            (array) config('android_release.scanner.play_scan_paths'),
            (array) config('android_release.scanner.play_negation_markers'),
            'active',
        );

        $checks[] = $this->historicalSupersessionCheck();

        return $checks;
    }

    /**
     * A historical record must DECLARE its supersession, not merely mention it.
     *
     * Mutant M11 survived a check that accepted the marker anywhere in the
     * file: it rewrote ADR 0009's status line to "Accepted, current authority"
     * while an incidental body paragraph still contained the word, so a
     * document asserting it was live authority passed the supersession check.
     * A header window would not have caught it either — that paragraph is near
     * the top as well. The marker has to sit on a status or heading line.
     *
     * @return array<string,mixed>
     */
    private function historicalSupersessionCheck(): array
    {
        $paths = (array) config('android_release.scanner.play_historical_paths');
        $pattern = (string) config('android_release.scanner.supersession_declaration_pattern');

        if ($paths === [] || $pattern === '') {
            return $this->check('historical_authority_marked_superseded', 'FAIL', 'Supersession check is unarmed (no historical paths or no declaration pattern).');
        }

        $undeclared = [];
        $unreadable = [];

        foreach ($paths as $relative) {
            $contents = $this->readBounded($this->basePath.'/'.$relative);

            if ($contents === null) {
                $unreadable[] = $relative;

                continue;
            }

            if (preg_match($pattern, $contents) !== 1) {
                $undeclared[] = $relative;
            }
        }

        $ok = $unreadable === [] && $undeclared === [];

        return $this->check(
            'historical_authority_marked_superseded',
            $ok ? 'PASS' : 'FAIL',
            $unreadable !== []
                ? 'Historical authority files are unreadable: '.implode(', ', $unreadable)
                : ($ok
                    ? count($paths).' historical records declare their supersession on a status or heading line.'
                    : 'Supersession is not DECLARED (only mentioned, or absent) in: '.implode(', ', $undeclared)),
        );
    }

    /**
     * Every listed file that names a Play term must also carry one of the
     * markers. A file that names none is fine; a file that names one without a
     * marker is a live Play dependency reintroduced in silence.
     *
     * @param  array<int,string>  $paths
     * @param  array<int,string>  $markers
     * @return array<string,mixed>
     */
    private function markerScanCheck(string $id, array $paths, array $markers, string $kind): array
    {
        $terms = (array) config('android_release.scanner.play_dependency_terms');

        if ($paths === [] || $terms === [] || $markers === []) {
            return $this->check($id, 'FAIL', 'Play scan is unarmed (no paths, terms or markers declared).');
        }

        $offenders = [];
        $missing = [];
        $scanned = 0;

        foreach ($paths as $relative) {
            $contents = $this->readBounded($this->basePath.'/'.$relative);

            if ($contents === null) {
                $missing[] = $relative;

                continue;
            }

            $scanned++;

            $namesPlay = false;

            foreach ($terms as $term) {
                if (str_contains($contents, $term)) {
                    $namesPlay = true;

                    break;
                }
            }

            if (! $namesPlay) {
                continue;
            }

            $acknowledged = false;

            foreach ($markers as $marker) {
                if (str_contains($contents, $marker)) {
                    $acknowledged = true;

                    break;
                }
            }

            if (! $acknowledged) {
                $offenders[] = $relative;
            }
        }

        // Presence assertion: a scan over zero readable files proves nothing.
        $ok = $scanned > 0 && $offenders === [] && $missing === [];

        return $this->check(
            $id,
            $ok ? 'PASS' : 'FAIL',
            $missing !== []
                ? 'Declared '.$kind.' authority files are unreadable: '.implode(', ', $missing)
                : ($scanned === 0
                    ? 'No '.$kind.' authority files were scanned; the check would be vacuous.'
                    : ($offenders === []
                        ? $scanned.' '.$kind.' authority files scanned; every Play mention is acknowledged as superseded or unused.'
                        : 'Unacknowledged Google Play dependency in: '.implode(', ', $offenders))),
        );
    }

    private function governanceDecisionChecks(): array
    {
        $decisions = [
            'signing_authority_decided' => config('android_release.signing.app_signing_authority'),
            'production_key_custody_decided' => config('android_release.signing.production_key_custody'),
            'distribution_authority_decided' => config('android_release.distribution.canonical_channel'),
            'device_management_decided' => config('android_release.device_management.pilot_model'),
            'rollback_mechanism_decided' => config('android_release.versioning.rollback_mechanism'),
        ];

        $checks = [];

        foreach ($decisions as $key => $value) {
            $checks[] = $this->check(
                $key,
                is_string($value) && $value !== '' ? 'PASS' : 'FAIL',
                is_string($value) && $value !== ''
                    ? "Decided: {$value}"
                    : 'Undecided. Phase 4 is blocked until this is recorded.',
            );
        }

        // A monotonic versionCode is not a style preference: Play refuses a
        // lower one and Android blocks the downgrade install, so a policy that
        // permitted it would be describing something that cannot happen.
        $monotonic = config('android_release.versioning.version_code_monotonic') === true
            && config('android_release.versioning.version_code_decrement_permitted') === false
            && config('android_release.versioning.version_code_reuse_permitted') === false;

        $checks[] = $this->check(
            'version_code_policy_monotonic',
            $monotonic ? 'PASS' : 'FAIL',
            $monotonic
                ? 'versionCode is strictly monotonic; recovery is stop-distribution then forward-fix.'
                : 'versionCode policy permits reuse or decrement. Android only enforces >=, so nothing else would catch this.',
        );

        $noPrCiSigning = config('android_release.signing.pull_request_ci_may_sign') === false;

        $checks[] = $this->check(
            'pull_request_ci_cannot_sign',
            $noPrCiSigning ? 'PASS' : 'FAIL',
            $noPrCiSigning
                ? 'Pull-request CI has no path to the signing identity.'
                : 'Pull-request CI is permitted to sign, which makes every contributor a release authority.',
        );

        return $checks;
    }

    /**
     * The only media on which the first production signing key may ever be
     * generated. Owned by this class, deliberately not by config: the config
     * block is what an edit changes, so a rule read entirely out of it can be
     * satisfied by the same edit that breaks it.
     */
    private const GENERATION_MEDIA_ALLOWLIST = [
        'primary_it_workstation',
    ];

    /**
     * Media the config's forbidden list must always contain. It may forbid
     * more; it may never forbid less.
     */
    private const GENERATION_MEDIA_MUST_FORBID = [
        'production_vps',
        'pull_request_ci',
        'ci_runner',
        'self_hosted_ci_runner',
        'admin_klinik_workstation',
        'usb',
        'clinic_android_device',
        'cloud_vm',
        'temporary_container',
        'browser',
    ];

    /**
     * Fields a custodian entry may carry. An allowlist rather than a denylist,
     * because a denylist only ever catches the leaks somebody already thought
     * of — `device_id`, `volume_id`, `contact` and `unlock_hint` all sailed
     * through the first version.
     */
    private const CUSTODIAN_PERMITTED_FIELDS = [
        'role', 'roles', 'responsible_party', 'media', 'medium_type', 'os',
        'location', 'authorized_access', 'host_full_disk_encryption',
        'primary_secret_storage', 'login_password', 'screen_lock',
        'initial_key_generation_authority', 'status',
    ];

    /**
     * REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1.
     *
     * `disk_encryption` is deliberately absent from the allowlist above, and
     * this is the one removal in it. The field is not merely renamed: it was
     * ambiguous enough that `true` on custodian 1 meant "somebody believes
     * this disk is encrypted" while the machine underneath had no LUKS device
     * at all. Leaving the old name accepted would let that exact record come
     * back and satisfy the endpoint gate again.
     *
     * So a config still carrying `disk_encryption` is now an UNRECOGNISED
     * field and fails `custody_records_no_secret_material` — a loud rejection
     * rather than a silent one. The replacement is a pair: the host fact
     * (`host_full_disk_encryption`) and, where the host fact is false, the
     * control that actually protects the key (`primary_secret_storage`).
     */
    private const CUSTODIAN_REJECTED_LEGACY_FIELDS = [
        'disk_encryption',
    ];

    /**
     * Fields the primary secret storage record may carry.
     *
     * A second allowlist because `custodianUnknownFields()` only ever walked
     * the TOP level of a custodian entry. Introducing the first nested array
     * would otherwise have opened a hole underneath the very check that exists
     * to stop `unlock_hint` and friends reaching a committed file — a nested
     * `primary_secret_storage.passphrase_hint` would have sailed straight
     * through. The walk now descends, and this is what it allows down there.
     */
    private const PRIMARY_SECRET_STORAGE_PERMITTED_FIELDS = [
        'type', 'encryption', 'verified', 'default_state', 'auto_unlock',
        'plaintext_keyfile', 'outside_repository',
    ];

    /**
     * Top-level fields the signing namespace may carry, outside `custody`.
     *
     * An ALLOWLIST, because the denylist beneath it is known-insufficient by
     * this class's own admission: `custodySecretLeaks()` matches exact leaf
     * keys, so `primary_workstation_serial` and `keystore_passphrase_hint`
     * both walk straight past `serial` and `hint`. That is precisely why
     * custodian entries were given a field allowlist, and the reasoning
     * applies identically one level up — a namespace nobody enumerated is a
     * namespace where an unanticipated key is ignored rather than refused.
     *
     * `custody` is absent deliberately: it is unset before this list is
     * consulted and walked by its own rules.
     */
    private const SIGNING_PERMITTED_FIELDS = [
        'app_signing_authority', 'production_key_custody',
        'production_certificate_sha256', 'production_certificate_sha256_recorded',
        'production_certificate_pin_required_before_install',
        'key_loss_recoverable', 'key_loss_consequence', 'minimum_custodians',
        'permitted_storage', 'forbidden_storage', 'pull_request_ci_may_sign',
        'release_signing_context', 'app_signing_key_rotation', 'backup',
        'runbook', 'governance_doc',
    ];

    /**
     * The only `signing.*` leaves allowed to hold a value that LOOKS secret.
     *
     * Both are certificate SHA-256 fingerprints, which are public by
     * construction — the certificate ships inside every signed APK, so the
     * fingerprint discloses nothing that an installed artifact does not. They
     * are named individually rather than matched by pattern, so a future field
     * cannot inherit the exemption by resembling one of them.
     */
    private const SIGNING_PUBLIC_IDENTIFIER_FIELDS = [
        'production_certificate_sha256',
        'production_certificate_sha256_recorded',
    ];

    /**
     * Encryption a dedicated signing vault may use.
     *
     * An allowlist, so "encrypted" cannot be satisfied by a value that merely
     * sounds like it — `plain`, `ecryptfs`, `zip`, `none` and an empty string
     * all have to be rejected by construction rather than by review.
     */
    private const APPROVED_PRIMARY_VAULT_ENCRYPTION = [
        'luks2',
    ];

    /**
     * PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1.
     *
     * The custody DESIGNATION — who holds what, where, under which controls.
     * Separate from the custody POLICY above it, which says how many copies
     * must exist and where they may never live.
     *
     * The distinction every check here defends is that a destination being
     * READY is not a backup EXISTING. Readiness is a decision; a backup is an
     * artifact. As of this scan there is no production key, so there is no
     * artifact anywhere, and a reader who concludes otherwise from "custody
     * ready" has been misled by this scanner rather than informed by it.
     *
     * @return array<int,array<string,mixed>>
     */
    private function signingCustodyChecks(): array
    {
        $custody = config('android_release.signing.custody');

        // Fail closed. An absent custody block is not "nothing to check", it
        // is the designation having gone missing.
        if (! is_array($custody) || $custody === []) {
            return [$this->check(
                'signing_custody_status_recorded',
                'FAIL',
                'No signing custody designation is recorded. Provisioning has nowhere lawful to put a key.',
            )];
        }

        $checks = [];
        $states = (array) ($custody['states'] ?? []);
        $status = $custody['status'] ?? null;
        $custodians = is_array($custody['custodians'] ?? null) ? $custody['custodians'] : [];

        // ---- 1. The recorded state is one this model actually defines. -----
        $statusKnown = is_string($status) && $status !== '' && in_array($status, $states, true);

        $checks[] = $this->check(
            'signing_custody_status_recorded',
            $statusKnown ? 'PASS' : 'FAIL',
            $statusKnown
                ? "Custody state recorded as '{$status}'."
                : 'Custody state is absent or not one of the declared states: '.implode(', ', array_map('strval', $states)),
        );

        // ---- 2. Ready for provisioning, with every destination prepared. ---
        $readyFlags = [
            'primary_signing_workstation_ready',
            'backup_destination_1_ready',
            'sealed_cold_destination_ready',
            'offsite_destination_ready',
        ];

        $notReady = array_values(array_filter(
            $readyFlags,
            fn (string $flag): bool => ($custody[$flag] ?? null) !== true,
        ));

        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1.
        //
        // This used to demand `$status === 'ready_for_provisioning'` exactly,
        // which made the check red the moment the lifecycle it guards moved
        // forward. A state machine whose own gate fails on lawful progress
        // teaches the next operator that a FAIL here is normal, and that is
        // how a gate stops being read at all.
        //
        // What it must actually assert is that provisioning has cleared its
        // preconditions: every destination is still prepared, and custody has
        // reached AT LEAST readiness. It is deliberately not weakened for the
        // states BELOW readiness — `deferred` and `designated` still fail —
        // and the destination flags are still required in every state, so a
        // destination cannot be quietly retired after a key is written to it.
        //
        // Position is read from the config's own declared `states` order. An
        // unknown status has no position and fails here as well as in check 1.
        $position = array_search($status, $states, true);
        $readinessIndex = array_search('ready_for_provisioning', $states, true);

        $pastReadiness = is_int($position)
            && is_int($readinessIndex)
            && $position >= $readinessIndex;

        $readyForProvisioning = $pastReadiness && $notReady === [];
        $atReadiness = $status === 'ready_for_provisioning';

        $checks[] = $this->check(
            'signing_custody_ready_for_provisioning',
            $readyForProvisioning ? 'PASS' : 'FAIL',
            $readyForProvisioning
                ? ($atReadiness
                    ? 'All three custody destinations are designated and prepared. Key provisioning is permitted to start.'
                    : "All three custody destinations are designated and prepared, and custody has advanced to '{$status}'. "
                        .'This check asserts the preconditions for provisioning were met; it asserts nothing about what was created.')
                : ($notReady !== []
                    ? 'Destinations not marked ready: '.implode(', ', $notReady)
                    : (is_int($position)
                        ? "Custody state is '{$status}', which is before ready_for_provisioning."
                        : "Custody state '{$status}' has no position in the declared lifecycle.")),
        );

        // ---- 3. Enough destinations to satisfy the standing policy. --------
        $minimum = (int) config('android_release.signing.minimum_custodians');

        // Well-formed entries only. `'custodian_4' => []` is not a custody
        // destination, and counting it would satisfy the minimum with nothing.
        $designated = count(array_filter(
            $custodians,
            fn ($c): bool => is_array($c)
                && is_string($c['media'] ?? null)
                && (array) ($c['roles'] ?? []) !== [],
        ));

        $checks[] = $this->check(
            'custody_minimum_custodians_designated',
            $designated >= $minimum && $minimum > 0 ? 'PASS' : 'FAIL',
            $designated >= $minimum && $minimum > 0
                ? "{$designated} custody destinations designated against a minimum of {$minimum}."
                : "Only {$designated} custody destinations designated; policy requires {$minimum}.",
        );

        // ---- 4. Exactly one primary signing authority, and it is #1. -------
        // Both the singular `role` and the plural `roles` are read. Checking
        // only `role` let a second custodian carry
        // `primary_signing_authority` inside `roles[]` — the field checks 7
        // and 8 do read — while this one still printed "sole".
        $primaries = array_keys(array_filter(
            $custodians,
            fn ($c): bool => is_array($c) && (
                ($c['role'] ?? null) === 'primary_signing_authority'
                || in_array('primary_signing_authority', (array) ($c['roles'] ?? []), true)
            ),
        ));

        $primaryOk = $primaries === ['custodian_1'];

        $checks[] = $this->check(
            'custody_primary_signing_authority_designated',
            $primaryOk ? 'PASS' : 'FAIL',
            $primaryOk
                ? 'Custodian 1 is the sole primary signing authority.'
                : ($primaries === []
                    ? 'No primary signing authority is designated; nothing may sign.'
                    : 'Primary signing authority is ambiguous: '.implode(', ', $primaries)),
        );

        // ---- 5. Only the primary workstation may generate the first key. ---
        $generators = array_keys(array_filter(
            $custodians,
            fn ($c): bool => is_array($c) && ($c['initial_key_generation_authority'] ?? false) === true,
        ));

        $permittedMedia = (array) ($custody['permitted_initial_key_generation_media'] ?? []);
        $forbiddenMedia = (array) ($custody['forbidden_initial_key_generation_media'] ?? []);

        // The medium is checked against lists this class owns, NOT only the
        // ones in the config block being edited.
        //
        // Security review caught the first version reading both sides from
        // config: setting `custodian_1.media = 'production_vps'` and
        // `permitted = ['production_vps']` in one edit produced a PASS whose
        // message still read "VPS, CI, backup destinations, USB and the clinic
        // device are all excluded". Every clause of that sentence was false.
        // A rule that says where an unrecoverable key may be born cannot be
        // satisfied by the same edit that breaks it, so the anchors are here.
        $generatorMedium = $custodians['custodian_1']['media'] ?? null;

        $mediumAllowed = is_string($generatorMedium)
            && in_array($generatorMedium, self::GENERATION_MEDIA_ALLOWLIST, true);

        // The config may forbid MORE than this, never less.
        $missingForbidden = array_values(array_diff(self::GENERATION_MEDIA_MUST_FORBID, $forbiddenMedia));

        // The config's own permitted list may not widen past the allowlist.
        $overbroadPermitted = array_values(array_diff($permittedMedia, self::GENERATION_MEDIA_ALLOWLIST));

        $generationOk = $generators === ['custodian_1']
            && $mediumAllowed
            && $missingForbidden === []
            && $overbroadPermitted === []
            && $permittedMedia !== [];

        $checks[] = $this->check(
            'custody_key_generation_hosts_restricted',
            $generationOk ? 'PASS' : 'FAIL',
            $generationOk
                ? 'Only the primary IT workstation may generate the first production key; VPS, CI, backup destinations, USB, cloud VMs, containers and the clinic device are all excluded by a list this scanner owns.'
                : ($generators !== ['custodian_1']
                    ? ($generators === []
                        ? 'No key generation authority is designated.'
                        : 'Key generation authority is not restricted to custodian 1: '.implode(', ', $generators))
                    : (! $mediumAllowed
                        ? 'Key generation authority sits on a medium outside the scanner allowlist: '.var_export($generatorMedium, true)
                        : ($missingForbidden !== []
                            ? 'Media that must always be forbidden are missing from the forbidden list: '.implode(', ', $missingForbidden)
                            : 'The permitted generation media list has been widened beyond the scanner allowlist: '.implode(', ', $overbroadPermitted)))),
        );

        // ---- 6. Endpoint controls on every workstation holding a copy. -----
        $workstations = array_filter(
            $custodians,
            fn ($c): bool => is_array($c) && ($c['medium_type'] ?? null) === 'workstation',
        );

        $unprotected = [];

        foreach ($workstations as $key => $workstation) {
            foreach (['login_password', 'screen_lock'] as $control) {
                if (($workstation[$control] ?? null) !== true) {
                    $unprotected[] = "{$key}.{$control}";
                }
            }

            // Encryption at rest, which is no longer one boolean.
            //
            // A workstation satisfies it EITHER by encrypting the whole host
            // OR by holding signing material in a verified dedicated vault.
            // Custodian 1 does the second: the host is honestly recorded as
            // unencrypted and a LUKS2 container carries the key instead.
            //
            // What this must never again accept is a bare `true` with nothing
            // behind it, so when the host claim is false the vault record has
            // to survive `primaryVaultEncrypted()` in full — right encryption,
            // verified, closed at rest, no auto-unlock, no keyfile, outside
            // the repository. A half-filled vault block fails here.
            if (! $this->workstationEncryptedAtRest($workstation)) {
                $unprotected[] = "{$key}.encryption_at_rest";
            }
        }

        $endpointOk = $workstations !== [] && $unprotected === [];

        $vaulted = count(array_filter(
            $workstations,
            fn (array $w): bool => ($w['host_full_disk_encryption'] ?? null) !== true
                && $this->primaryVaultEncrypted($w['primary_secret_storage'] ?? null),
        ));

        $checks[] = $this->check(
            'custody_endpoint_controls_recorded',
            $endpointOk ? 'PASS' : 'FAIL',
            $endpointOk
                ? count($workstations).' custody workstations record a login password, a screen lock and encryption at rest'
                    .($vaulted > 0
                        ? " ({$vaulted} of them through a verified dedicated encrypted vault rather than host full-disk encryption)."
                        : ' through host full-disk encryption.')
                : ($workstations === []
                    ? 'No custody workstation was scanned; the endpoint control check would be vacuous.'
                    : 'Missing endpoint controls: '.implode(', ', $unprotected)),
        );

        // ---- 6b. The primary signing authority's key storage, specifically.
        //
        // Check 6 covers every workstation holding a copy. This one is about
        // the one machine that will hold the LIVE keystore, and it is separate
        // so the report can never blur "the backup machine is fine" into "the
        // signing machine is fine". It also states the host/vault distinction
        // in words, because that distinction is the entire finding this
        // revision exists to record.
        $primary = null;

        foreach ($custodians as $custodian) {
            if (is_array($custodian) && ($custodian['initial_key_generation_authority'] ?? null) === true) {
                $primary = $custodian;

                break;
            }
        }

        $primaryHostFde = is_array($primary) && ($primary['host_full_disk_encryption'] ?? null) === true;
        $primaryVault = is_array($primary) ? ($primary['primary_secret_storage'] ?? null) : null;
        $primaryVaultOk = $this->primaryVaultEncrypted($primaryVault);
        $primaryOk = is_array($primary) && ($primaryHostFde || $primaryVaultOk);

        $checks[] = $this->check(
            'custody_primary_secret_storage_encrypted',
            $primaryOk ? 'PASS' : 'FAIL',
            $primaryOk
                ? ($primaryHostFde
                    ? 'The primary signing authority encrypts its whole host, so the future keystore is covered at rest by host full-disk encryption.'
                    : 'The primary signing authority does NOT encrypt its host; the future keystore is covered at rest by a verified '
                        .var_export($primaryVault['encryption'] ?? null, true)
                        .' vault that stays closed when not in approved use. Host full-disk encryption is recorded false, which is the true value.')
                : ($primary === null
                    ? 'No custodian holds initial key generation authority, so no primary secret storage could be assessed.'
                    : 'The primary signing authority records neither host full-disk encryption nor a verified dedicated encrypted vault. '
                        .'There is nowhere lawful to put the production keystore.'),
        );

        // ---- 7 & 8. Sealed-cold and offsite destinations both exist. ------
        foreach ([
            'sealed_cold_destination' => 'sealed_cold_destination_ready',
            'offsite_destination' => 'offsite_destination_ready',
        ] as $role => $readyFlag) {
            $holders = array_keys(array_filter(
                $custodians,
                fn ($c): bool => is_array($c) && in_array($role, (array) ($c['roles'] ?? []), true),
            ));

            $ok = $holders !== [] && ($custody[$readyFlag] ?? null) === true;
            $label = str_replace('_', ' ', $role);

            $checks[] = $this->check(
                'custody_'.$role.'_designated',
                $ok ? 'PASS' : 'FAIL',
                $ok
                    ? "A {$label} is designated: ".implode(', ', $holders).'.'
                    : ($holders === []
                        ? "No {$label} is designated."
                        : "A {$label} is designated but not marked ready."),
            );
        }

        // ---- 9. Media handling rules that make a stolen medium useless. ----
        $rules = is_array($custody['media_rules'] ?? null) ? $custody['media_rules'] : [];

        $ruleExpectations = [
            'plaintext_signing_material_permitted' => false,
            'password_stored_with_key_permitted' => false,
            'encrypted_container_required' => true,
            'offline_when_not_in_approved_use' => true,
            'general_daily_use_after_custody_permitted' => false,
        ];

        $violated = [];

        foreach ($ruleExpectations as $rule => $expected) {
            if (($rules[$rule] ?? null) !== $expected) {
                $violated[] = $rule;
            }
        }

        $checks[] = $this->check(
            'custody_media_secret_handling_rules',
            $violated === [] ? 'PASS' : 'FAIL',
            $violated === []
                ? 'Signing material may only exist encrypted, never beside its passphrase, and the cold medium stays offline between operations.'
                : 'Custody media rules violated: '.implode(', ', $violated),
        );

        // ---- 10. The concentration is declared, not hidden. ----------------
        $shared = $custody['single_party_can_reach_all_copies'] ?? null;
        $controls = (array) ($custody['single_party_access_compensating_controls'] ?? []);

        // DERIVED, not taken on trust. Security review caught the first
        // version accepting a bare `false` at no cost, while the refuting
        // evidence sat three lines away in `authorized_access`. A caveat that
        // can be switched off by denying it is not a caveat, and this one is
        // the integrity centrepiece of the whole record.
        //
        // The concentration is the intersection of every custodian's
        // authorised-access list: a party present in all of them can reach
        // every copy, whatever the boolean says.
        $accessLists = array_values(array_map(
            fn ($c): array => array_map('strval', (array) ($c['authorized_access'] ?? [])),
            array_filter($custodians, 'is_array'),
        ));

        $intersection = $accessLists === [] ? [] : array_values(array_intersect(...$accessLists));
        $concentrationExists = $accessLists !== [] && $intersection !== [];

        // Every custodian must state who may reach it, or the intersection is
        // computed over a hole and means nothing.
        $accessRecorded = $accessLists !== []
            && ! in_array([], $accessLists, true)
            && count($accessLists) === count($custodians);

        $sharedOk = is_bool($shared)
            && $accessRecorded
            && $shared === $concentrationExists
            && ($shared === false || $controls !== []);

        $checks[] = $this->check(
            'custody_shared_access_declared',
            $sharedOk ? 'PASS' : 'FAIL',
            $sharedOk
                ? ($shared
                    ? 'Derived from authorised access: '.implode(', ', $intersection)
                        .' can reach every copy. Declared, with '.count($controls).' compensating controls recorded.'
                    : 'Derived from authorised access: no party appears on every custody destination.')
                : (! $accessRecorded
                    ? 'Not every custody destination records who may reach it; the concentration cannot be derived.'
                    : (! is_bool($shared)
                        ? 'Whether one party can reach every copy is undeclared. It must be answered either way.'
                        : ($shared !== $concentrationExists
                            ? 'The declaration contradicts the recorded access lists: declared '
                                .var_export($shared, true).', derived '.var_export($concentrationExists, true)
                                .($intersection !== [] ? ' (shared by: '.implode(', ', $intersection).')' : '')
                            : 'One party can reach all copies and no compensating control is recorded.'))),
        );

        // ---- 11. The lifecycle cannot be claimed out of order. -------------
        $certificate = config('android_release.signing.production_certificate_sha256');
        $recordedCertificate = config('android_release.signing.production_certificate_sha256_recorded');
        $keyProvisioned = ($custody['production_signing_key_provisioned'] ?? null) === true;

        // PRESENCE, not validity, and PRODUCTION-ANDROID-SIGNING-CERTIFICATE-
        // PIN-1 deliberately left it that way while making it explicit. The
        // ordering rules below have to treat junk as a CLAIM: a pin of 'TBD'
        // written while no key exists must still trip "pinned while no key is
        // claimed provisioned". Tightening this to isValid() would have made
        // that junk invisible to the very rule that catches it — a fail-open
        // relaxation dressed as a cleanup.
        $pinned = AndroidCertificateFingerprint::isPresent($certificate);

        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1.
        //
        // A recorded fingerprint has to be a fingerprint, not merely a
        // non-empty string. `'yes'` and `'TBD'` would otherwise satisfy "a
        // certificate is recorded" and let a key be claimed with no evidence
        // that one was ever generated.
        // PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1 also corrected the CASE
        // here. This regex had no `i` flag while the summary line derived from
        // the same field did, so an upper-case recorded value — the form
        // `keytool -list` prints — reported PRODUCTION_CERTIFICATE_RECORDED=true
        // and, in the same scan, "key claimed provisioned while no valid
        // certificate fingerprint is recorded". One field, two verdicts, both
        // printed. Case is representation, not identity.
        $recorded = AndroidCertificateFingerprint::isValid($recordedCertificate);

        $anyBackup = ($custody['backup_1_key_copy_created'] ?? null) === true
            || ($custody['backup_2_key_copy_created'] ?? null) === true
            || ($custody['sealed_cold_backup_created'] ?? null) === true
            || ($custody['offsite_backup_created'] ?? null) === true;

        $recovered = ($custody['recovery_verified'] ?? null) === true;

        $inconsistencies = [];

        // A key that exists has a certificate. Claiming one without the other
        // means one of the two facts is fiction.
        //
        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 corrected WHICH field
        // answers this. It used to read the PIN, which conflated a fact with
        // an enforcement decision: while both were false at once the single
        // field was adequate, but it made "the key exists" unstateable
        // without also arming `android:verify-release`. Coupling a custody
        // record to an enforcement switch would have pushed a future operator
        // to pin a certificate before the pinning task had reviewed anything,
        // to get the gate green.
        //
        // So the fact is checked against the recorded fingerprint, and the
        // pin gets its own rules below.
        if ($keyProvisioned && ! $recorded) {
            $inconsistencies[] = 'key claimed provisioned while no valid certificate fingerprint is recorded';
        }

        // The reverse, which nothing checked before: a certificate cannot
        // exist without the key it belongs to. Without this, the recorded
        // field would be a way to satisfy the rule above by writing 64 hex
        // characters, and the evidence field would have become the loophole
        // in the check it was added to serve.
        if ($recorded && ! $keyProvisioned) {
            $inconsistencies[] = 'a certificate fingerprint is recorded while no key is claimed provisioned';
        }

        // Pinning is downstream of the key, never ahead of it.
        if ($pinned && ! $keyProvisioned) {
            $inconsistencies[] = 'a certificate is pinned while no key is claimed provisioned';
        }

        // And the pin must be THE certificate, not A certificate. This is the
        // one that matters at install time: the pin is the only authority an
        // installer verifies an artifact against, so a pin that disagrees with
        // the recorded production certificate is either the wrong key or a
        // substituted one, and there is no third possibility worth assuming.
        // Compared case-INSENSITIVELY. `keytool -list` prints SHA-256 in
        // upper case, which is the most likely paste in the pinning task, and
        // a correct pin differing only in case would otherwise be reported as
        // "the wrong key or a substituted one" — a false accusation of
        // substitution, on the one message an operator would act on hardest.
        if ($pinned && $recorded && ! AndroidCertificateFingerprint::matches($certificate, $recordedCertificate)) {
            $inconsistencies[] = 'the pinned certificate does not match the recorded production certificate';
        }

        if ($anyBackup && ! $keyProvisioned) {
            $inconsistencies[] = 'a backup copy is claimed to exist while no key has been provisioned';
        }

        if ($recovered && ! $anyBackup) {
            $inconsistencies[] = 'recovery is claimed verified while no backup copy exists';
        }

        $checks[] = $this->check(
            'custody_state_machine_consistent',
            $inconsistencies === [] ? 'PASS' : 'FAIL',
            $inconsistencies === []
                ? 'Custody lifecycle facts are mutually consistent: nothing downstream is claimed ahead of what it depends on.'
                : 'Custody lifecycle contradiction — '.implode('; ', $inconsistencies).'.',
        );

        // ---- 12. Readiness must not quietly become provisioning. -----------
        $claims = [
            'production_signing_key_provisioned',
            'backup_1_key_copy_created',
            'backup_2_key_copy_created',
            'sealed_cold_backup_created',
            'offsite_backup_created',
            'recovery_verified',
        ];

        $premature = array_values(array_filter(
            $claims,
            fn (string $claim): bool => ($custody[$claim] ?? null) === true,
        ));

        $readinessStatus = $status === 'ready_for_provisioning';

        // A recorded fingerprint counts as a claim here too. A certificate
        // cannot be recorded unless a key was generated, so a record still
        // calling itself ready_for_provisioning while carrying one is
        // describing a key it says does not exist.
        $readinessHonest = ! $readinessStatus
            || ($premature === [] && ! $pinned && ! $recorded);

        // The detail must describe what was actually verified. The first
        // version printed "claims no key, no backup copy and no verified
        // recovery — those remain false" for ANY status other than
        // ready_for_provisioning, including an `operational` record with every
        // created flag true. Check 2 failed alongside it, so the gate was red
        // overall — but a reader consuming the report check by check, which
        // is the documented usage, saw an affirmatively false PASS line.
        $checks[] = $this->check(
            'custody_readiness_does_not_claim_provisioning',
            $readinessHonest ? 'PASS' : 'FAIL',
            $readinessHonest
                ? ($readinessStatus
                    ? 'Custody is ready for provisioning and claims no key, no backup copy and no verified recovery. Those remain false.'
                    : "Custody state is '{$status}', past readiness; this check does not apply and asserts nothing about the claims below it.")
                : 'Custody is still ready_for_provisioning yet claims: '
                    .implode(', ', $premature !== []
                        ? $premature
                        : [$pinned ? 'a pinned production certificate' : 'a recorded production certificate'])
                    .'. Readiness is a decision, not an artifact.',
        );

        // ---- 12b. The status may not run ahead of the artifacts. ----------
        //
        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1.
        //
        // Check 11 forbids a DOWNSTREAM fact without its upstream one, and
        // check 12 guards the single state `ready_for_provisioning`. Between
        // them sat an unguarded gap: every state past readiness could be
        // declared with nothing behind it at all. `status => 'operational'`
        // with all six flags false contradicted no rule, and the summary line
        // a human actually reads — `signing_custody_status` — would have
        // announced it.
        //
        // That gap matters most in exactly the direction this task moves. The
        // whole reason three destinations exist is that a key held in one
        // place is a key that will eventually be lost, so "custody is
        // finished" must be answerable only by the artifacts, never by the
        // word.
        //
        // The rule is one-directional on purpose. A status may lag the facts —
        // that is merely conservative, and check 12 already refuses the one
        // lagging state that would mislead. It may never lead them.
        $supported = [
            'ready_for_provisioning' => $notReady === [],
            'key_provisioned' => $keyProvisioned,
            'backups_created' => $keyProvisioned
                && ($custody['backup_1_key_copy_created'] ?? null) === true
                && ($custody['backup_2_key_copy_created'] ?? null) === true
                && ($custody['sealed_cold_backup_created'] ?? null) === true
                && ($custody['offsite_backup_created'] ?? null) === true,
            'recovery_verified' => $recovered,
            // `operational` additionally requires the certificate to be armed:
            // a key nobody can verify an artifact against is not operational,
            // whatever the backups say.
            'operational' => $recovered && $pinned,
        ];

        // Each state inherits every requirement before it, so a later claim
        // cannot be satisfied by skipping an earlier one.
        $cumulative = true;
        $unmet = [];

        foreach ($supported as $state => $ok) {
            $cumulative = $cumulative && $ok;
            $supported[$state] = $cumulative;

            if (! $ok) {
                $unmet[$state] = true;
            }
        }

        // States BEFORE readiness make no artifact claim and are not listed
        // above. States AT OR AFTER readiness always do.
        //
        // Security review found this failing open. The guard was
        // `array_key_exists($status, $supported)`, so any state absent from the
        // map was read as "claims nothing" and PASSed — and check 2 above no
        // longer pins the status to an exact value, so a state appended to
        // `custody.states` after `ready_for_provisioning` sailed through every
        // custody gate with all six artifact flags false. The relaxation in
        // check 2 is what removed the backstop that had been making this
        // harmless, so this is a defect introduced with that change, not one
        // inherited.
        //
        // The map is therefore not the authority on whether a state claims
        // artifacts; POSITION is. A state at or past readiness with no
        // requirement defined for it is an unanswerable claim, and the only
        // safe answer to an unanswerable claim about an unrecoverable key is
        // to refuse it.
        $claimsArtifacts = array_key_exists((string) $status, $supported);
        $undefinedPastReadiness = ! $claimsArtifacts && $pastReadiness;

        $statusSupported = ! $undefinedPastReadiness
            && (! $claimsArtifacts || $supported[(string) $status] === true);

        $firstUnmet = array_key_first($unmet);

        $checks[] = $this->check(
            'custody_status_matches_recorded_facts',
            $statusSupported ? 'PASS' : 'FAIL',
            $statusSupported
                ? ($claimsArtifacts
                    ? "Custody status '{$status}' is supported by the recorded artifacts; no state is claimed ahead of what exists."
                    : "Custody status '{$status}' is before readiness and makes no artifact claim, so there is nothing for the recorded facts to contradict.")
                : ($undefinedPastReadiness
                    ? "Custody status '{$status}' is declared at or past readiness but no artifact requirement is defined for it, "
                        .'so what it claims cannot be checked. Add it to the supported-state requirements before using it.'
                    : "Custody status '{$status}' claims more than the recorded facts support; the first unmet requirement is '"
                        .$firstUnmet."'. The status is a summary of the artifacts, never a substitute for them."),
        );

        // ---- 13. No secret or locating material in a committed file. -------
        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 widened this beyond
        // `custody`.
        //
        // Security review demonstrated that the walk covered `signing.custody.*`
        // and nothing above it, so a hardware serial, the vault filesystem UUID
        // or a passphrase hint could be committed at `signing.*` and no control
        // in the tree would notice — while this very check printed "no leaf key
        // or value matches a passphrase, serial, identifier...". The three
        // things it named are exactly the three that got through.
        //
        // The finding was found here because this sprint ADDED a field at
        // `signing.*`, which is precisely when an unpoliced namespace stops
        // being theoretical.
        $signing = config('android_release.signing');
        $signing = is_array($signing) ? $signing : [];

        // `custody` is walked separately below, with the custodian field
        // allowlist that only applies down there. Excluded here so a leak
        // inside it is reported once rather than twice.
        unset($signing['custody']);

        $leaks = array_merge(
            $this->signingSecretLeaks($signing),
            $this->custodySecretLeaks($custody),
            $this->custodianUnknownFields($custodians),
        );

        $checks[] = $this->check(
            'custody_records_no_secret_material',
            $leaks === [] ? 'PASS' : 'FAIL',
            $leaks === []
                ? 'Across the whole signing namespace, every field is declared in an allowlist and no leaf key or value matches a passphrase, serial, identifier, contact detail or street-level address pattern. '
                    .'The certificate fingerprints are the only shape exemption, and they are public identifiers.'
                : 'Secret, locating or unrecognised material recorded in committed signing config: '.implode(', ', $leaks),
        );

        return $checks;
    }

    /**
     * Walk the custody designation looking for anything that must never be
     * committed.
     *
     * Two independent mechanisms, because either alone has a documented hole.
     * A leaf-key denylist only catches leaks somebody already imagined —
     * security review walked `device_id`, `volume_id`, `contact`,
     * `unlock_hint` and `wrapped_material` straight past the first version.
     * Value-shape detection catches the rest. Custodian entries additionally
     * get a FIELD ALLOWLIST, so an unanticipated key is refused rather than
     * ignored.
     *
     * Matching on the exact leaf key keeps policy vocabulary such as
     * `login_password` and `password_stored_with_key_permitted` from being
     * mistaken for a stored password.
     *
     * @param  array<string,mixed>  $custody
     * @return array<int,string>
     */
    private function custodySecretLeaks(array $custody, string $path = 'custody'): array
    {
        $forbiddenKeys = [
            'serial', 'serial_number', 'hardware_serial', 'device_id', 'volume_id',
            'uuid', 'filesystem_uuid', 'passphrase', 'password', 'key_password',
            'store_password', 'keystore_password', 'secret', 'token', 'credential',
            'credentials', 'private_key', 'recovery_key', 'unlock_hint', 'hint',
            'street', 'street_address', 'home_address', 'postal_address', 'address',
            'phone', 'contact', 'email', 'fingerprint', 'wrapped_material',
        ];

        $leaks = [];

        foreach ($custody as $key => $value) {
            $leafPath = $path.'.'.$key;

            if (in_array(strtolower((string) $key), $forbiddenKeys, true)) {
                $leaks[] = $leafPath;

                continue;
            }

            if (is_array($value)) {
                $leaks = array_merge($leaks, $this->custodySecretLeaks($value, $leafPath));

                continue;
            }

            if (is_string($value) && $this->looksLikeSecretValue($value)) {
                $leaks[] = $leafPath;
            }
        }

        return $leaks;
    }

    /**
     * The same leak walk, over the signing namespace above the custody block.
     *
     * Delegates to `custodySecretLeaks()` rather than reimplementing it, so the
     * denylist and the value-shape patterns can never drift apart — two copies
     * of a security rule is one copy that will be forgotten.
     *
     * @param  array<string,mixed>  $signing
     * @return array<int,string>
     */
    private function signingSecretLeaks(array $signing): array
    {
        $exempt = [];

        foreach (self::SIGNING_PUBLIC_IDENTIFIER_FIELDS as $field) {
            if (array_key_exists($field, $signing)) {
                $exempt[$field] = $signing[$field];
                unset($signing[$field]);
            }
        }

        // Any field nobody declared. This is the half that actually stops an
        // unanticipated leak; the denylist below only catches the shapes
        // somebody already thought of.
        $leaks = array_values(array_map(
            fn (string $field): string => "signing.{$field} (undeclared signing field)",
            array_filter(
                array_map('strval', array_keys($signing)),
                fn (string $field): bool => ! in_array($field, self::SIGNING_PERMITTED_FIELDS, true),
            ),
        ));

        // Exempt from SHAPE detection, never from existing. A fingerprint field
        // holding something that is not a fingerprint is not covered by the
        // "public identifier" justification, so it is checked for shape here
        // and reported like any other leak.
        $leaks = array_merge($leaks, $this->custodySecretLeaks($signing, 'signing'));

        foreach ($exempt as $field => $value) {
            if ($value !== null && ! $this->isCertificateFingerprint($value)) {
                $leaks[] = "signing.{$field} (exempt only as a certificate fingerprint; this is not one)";
            }
        }

        return $leaks;
    }

    /**
     * Fields outside the custodian allowlist, anywhere in the designation.
     *
     * @param  array<string,mixed>  $custodians
     * @return array<int,string>
     */
    private function custodianUnknownFields(array $custodians): array
    {
        $unknown = [];

        foreach ($custodians as $key => $custodian) {
            if (! is_array($custodian)) {
                continue;
            }

            foreach (array_keys($custodian) as $field) {
                if (in_array((string) $field, self::CUSTODIAN_REJECTED_LEGACY_FIELDS, true)) {
                    // Named separately from a merely unrecognised field so the
                    // report says WHY, rather than leaving a reader to think
                    // they mistyped something.
                    $unknown[] = "custodians.{$key}.{$field} (retired: ambiguous encryption claim, "
                        .'use host_full_disk_encryption and primary_secret_storage)';

                    continue;
                }

                if (! in_array((string) $field, self::CUSTODIAN_PERMITTED_FIELDS, true)) {
                    $unknown[] = "custodians.{$key}.{$field}";
                }
            }

            // Descend one level into the primary secret storage record.
            //
            // Without this the nested block would be the only unpoliced
            // surface in the whole designation, which is precisely how
            // `unlock_hint` got in the first time — at the top level, back
            // when nothing walked it either.
            $storage = $custodian['primary_secret_storage'] ?? null;

            if (is_array($storage)) {
                foreach (array_keys($storage) as $field) {
                    if (! in_array((string) $field, self::PRIMARY_SECRET_STORAGE_PERMITTED_FIELDS, true)) {
                        $unknown[] = "custodians.{$key}.primary_secret_storage.{$field}";
                    }
                }
            }
        }

        return $unknown;
    }

    /**
     * Whether a value is a usable certificate SHA-256 fingerprint.
     *
     * One helper so the recorded field, the pin and the report can never drift
     * into disagreeing about what counts as a fingerprint. Case-insensitive
     * because `keytool` prints upper case, and separators are NOT accepted:
     * the verifier compares against a bare lower-case hex string.
     */
    private function isCertificateFingerprint(mixed $value): bool
    {
        // PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1 moved the definition out
        // of this class. It is kept as a named method because the checks read
        // better for it, but it no longer OWNS the rule: the verifier asks the
        // same object, so the report and the control cannot drift apart
        // without AndroidCertificateFingerprint changing.
        return AndroidCertificateFingerprint::isValid($value);
    }

    /**
     * Whether the configured trust anchor is one the real-device preflight
     * could lawfully have left behind.
     *
     * This REPLACES `production_certificate_sha256 === null` in
     * `preflight_unlocks_nothing`, and the replacement is the point. Rule 147
     * is that relaxing a predicate orphans the gap a sibling was quietly
     * covering, so this is not a deletion: the check still asserts STATE, and
     * the state it now demands is strictly narrower than "null" was broad.
     *
     * Two values are containable. Absent — the preflight introduced nothing.
     * Or exactly the RECORDED production certificate — the anchor is the key
     * custody says exists, pinned by the separately authorised pinning task.
     * Anything else is precisely what "the preflight introduced a trust anchor"
     * would look like: a well-formed fingerprint that is not ours, or junk in
     * the field, and both stay red.
     *
     * What it deliberately does NOT try to do is decide whether the pin is
     * authentic. That belongs to `custody_state_machine_consistent`, which
     * already requires the pin to equal the recorded certificate AND the key to
     * be provisioned, and which is a stronger owner of the question than a
     * containment check could be. This one answers only "did the hardware gate
     * move it", and it answers it without reading a private key.
     */
    private function noUnauthorisedTrustAnchor(): bool
    {
        $pin = config('android_release.signing.production_certificate_sha256');

        if ($pin === null) {
            return true;
        }

        return AndroidCertificateFingerprint::matches(
            $pin,
            config('android_release.signing.production_certificate_sha256_recorded'),
        );
    }

    /**
     * Encryption at rest for one custody workstation.
     *
     * Two lawful ways to satisfy it, and exactly two: encrypt the host, or
     * keep signing material in a vault that survives every clause of
     * `primaryVaultEncrypted()`. A recorded vault that is unverified, open at
     * rest, auto-unlocking, keyfile-backed, inside a repository or using an
     * unapproved encryption is NOT a second way — it is a claim, and claims
     * are what put the scanner in this position to begin with.
     *
     * @param  array<string,mixed>  $workstation
     */
    private function workstationEncryptedAtRest(array $workstation): bool
    {
        if (($workstation['host_full_disk_encryption'] ?? null) === true) {
            return true;
        }

        return $this->primaryVaultEncrypted($workstation['primary_secret_storage'] ?? null);
    }

    /**
     * Whether a dedicated signing vault record describes real protection.
     *
     * Every clause is strict identity against the expected value rather than
     * truthiness, so a string `"false"`, a `1`, a `null` or a missing key all
     * fail rather than quietly passing. That is the specific shape of the bug
     * being corrected here: a value that read as true without being true.
     */
    private function primaryVaultEncrypted(mixed $vault): bool
    {
        if (! is_array($vault)) {
            return false;
        }

        return ($vault['type'] ?? null) === 'dedicated_encrypted_vault'
            && in_array($vault['encryption'] ?? null, self::APPROVED_PRIMARY_VAULT_ENCRYPTION, true)
            && ($vault['verified'] ?? null) === true
            && ($vault['default_state'] ?? null) === 'closed'
            && ($vault['auto_unlock'] ?? null) === false
            && ($vault['plaintext_keyfile'] ?? null) === false
            && ($vault['outside_repository'] ?? null) === true;
    }

    /**
     * Value-shape detection. A key-name denylist alone passed a USB serial, a
     * filesystem UUID, a private street address, a phone number and a
     * separator-bearing certificate fingerprint — while the check still
     * printed "no passphrase, serial, identifier or private address".
     *
     * Organisation-level place names ("Cabang Pusat", "Kantor Management
     * Klinik") are deliberately NOT matched; street-level vocabulary is.
     */
    private function looksLikeSecretValue(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        $patterns = [
            '/[A-Fa-f0-9]{16,}/',
            '/(?:[A-Fa-f0-9]{2}[:\-\s]){5,}[A-Fa-f0-9]{2}/',
            '/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/',
            '/\+?\d[\d\s\-]{8,}\d/',
            '/[^\s@]+@[^\s@]+\.[^\s@]+/',
            '/[A-Za-z0-9+\/]{24,}={0,2}/',
            '/\b(?:jalan|jl\.?|gang|gg\.?|blok|perumahan|kelurahan|kecamatan|kode\s*pos)\b/i',
            '/\brt\s*\d|\brw\s*\d/i',
            '/\bno\.?\s*\d+/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phase 3 already pins the no-enforcement contract with its own test. This
     * re-reports it so one command answers "is device enforcement still off?"
     * without a reader having to trust a second file.
     */
    private function enforcementChecks(): array
    {
        $checks = [];

        // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the flag now
        // exists, so "off" is no longer "there is nothing to flip". It is read
        // from the REAL feature-flag registry rather than from this config, so
        // android_release.php cannot assert an off state the application does
        // not actually have. A missing or unreadable flag is a FAIL, not a pass:
        // an absence assertion over nothing proves nothing.
        $flagKey = (string) config('android_release.enforcement.flag_key');
        $flagDefaultOff = null;

        if ($flagKey !== '') {
            $definition = config('feature_flags.flags.'.$flagKey);

            // The key contains dots, so the dotted lookup above cannot reach it;
            // the registry has to be read as a literal array key.
            if (! is_array($definition)) {
                $definition = (array) config('feature_flags.flags', []);
                $definition = is_array($definition[$flagKey] ?? null) ? $definition[$flagKey] : null;
            }

            $flagDefaultOff = is_array($definition) ? (($definition['default'] ?? null) === false) : null;
        }

        $off = config('android_release.enforcement.active') === false
            && config('android_release.enforcement.doctor_browser_login_denied') === false
            && $flagDefaultOff === true;

        $checks[] = $this->check(
            'enforcement_off',
            $off ? 'PASS' : 'FAIL',
            $off
                ? "Device enforcement is off, browser login is not denied, and the '{$flagKey}' flag defaults to false."
                : ($flagDefaultOff === null
                    ? "Enforcement flag '{$flagKey}' is not declared in the feature flag registry."
                    : 'Configuration claims enforcement is active. It must ship off.'),
        );

        $token = (string) config('android_release.scanner.enforcement_coupling_token');
        $allowedSymbols = (array) config('android_release.scanner.enforcement_coupling_allowed_symbols');
        $surfaces = (array) config('android_release.scanner.enforcement_coupling_surfaces');
        $coupled = [];
        $absent = [];

        $watched = (array) config('android_release.scanner.enforcement_coupling_watch_surfaces');

        foreach (array_merge($surfaces, $watched) as $surface) {
            $path = $this->basePath.'/'.$surface;

            $targets = is_dir($path)
                ? $this->phpFilesIn($path)
                : (is_file($path) ? [$path] : []);

            if ($targets === []) {
                // A REQUIRED surface that silently vanished is coverage loss,
                // not a pass — renaming a directory must not quietly reduce
                // this scan to nothing while it keeps reporting PASS.
                //
                // A WATCHED surface is expected to be absent: it is where
                // Phase 4/5 enforcement would land, and it does not exist yet.
                if (in_array($surface, $surfaces, true)) {
                    $absent[] = $surface;
                }

                continue;
            }

            foreach ($targets as $file) {
                $contents = $this->readBounded($file);

                if ($contents === null || ! str_contains($contents, $token)) {
                    continue;
                }

                // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — a
                // reference is only coupling if it is something OTHER than the
                // one sanctioned gate. See the allow-list rationale in
                // config/android_release.php.
                // Whole identifiers, not a prefix match: `EnsureDoctorDeviceSession`
                // must be compared as itself, otherwise the scan reports the
                // fragment `DoctorDeviceSession` and no allow-list entry can
                // ever match the symbol that is actually in the file.
                preg_match_all('/[A-Za-z_]*'.preg_quote($token, '/').'[A-Za-z_]*/', $contents, $matches);

                $unexpected = array_values(array_diff(array_unique($matches[0]), $allowedSymbols));

                if ($unexpected !== []) {
                    $coupled[] = ltrim(str_replace($this->basePath, '', $file), '/')
                        .' ('.implode(', ', $unexpected).')';
                }
            }
        }

        // Armed check, same reasoning as the key-material guard: an absence
        // assertion over zero files passes on nothing.
        $checks[] = $this->check(
            'enforcement_scan_surfaces_present',
            $surfaces !== [] && $absent === [] ? 'PASS' : 'FAIL',
            $surfaces === []
                ? 'No coupling surfaces declared; the enforcement scan would be vacuous.'
                : ($absent === []
                    ? 'All '.count($surfaces).' declared enforcement surfaces exist and were scanned.'
                    : 'Declared enforcement surfaces missing: '.implode(', ', $absent)),
        );

        $checks[] = $this->check(
            'authentication_coupled_only_through_the_gate',
            $coupled === [] && $allowedSymbols !== [] ? 'PASS' : 'FAIL',
            $allowedSymbols === []
                ? 'No allowed coupling symbols declared; this check would pass on anything.'
                : ($coupled === []
                    ? 'Authentication, session and middleware surfaces reference the device registry only through the sanctioned gate.'
                    : 'Unsanctioned enforcement coupling found in: '.implode(', ', $coupled)),
        );

        return $checks;
    }

    /**
     * Who has authorized what, and how far up the ladder it reaches.
     *
     * REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1 — the owner
     * signed off on a Phase 4A PILOT (one doctor, one branch, a named rollback
     * owner) while GLOBAL enforcement stays forbidden until Phase 5. Both
     * halves are checked here rather than only written down, because the
     * dangerous reading of a sign-off is always the broader one: "Phase 4 is
     * approved" quietly becomes "enforcement is approved", and the rung that
     * locks every doctor out of a browser is the same switch one line further
     * down the ladder.
     *
     * Neither check activates anything. They assert that an authorization was
     * recorded and that it is bounded — the ladder position in force is still
     * `off`, and `enforcement_off` above proves that independently.
     *
     * @return array<int,array<string,mixed>>
     */

    /**
     * EVIDENCE-PHASE4A-REAL-DEVICE-KEYINFO-PREFLIGHT-1.
     *
     * The hardware gate for the Phase 4A pilot device.
     *
     * The failure this guards against is a device capability flag being read
     * as proof that the app's key is hardware-backed. It is not: the flag
     * describes the platform, and a specific key can still be issued in
     * software while the flag reads exactly the same. So the accepted proof
     * is the security level of the GENERATED key, and this check refuses a
     * recorded level that is not on the accepted list — which is what a
     * downgrade to SOFTWARE would be.
     *
     * @return array<int,array<string,string>>
     */
    private function realDevicePreflightChecks(): array
    {
        $preflight = (array) config('android_release.real_device_preflight');
        $evidence = (array) ($preflight['evidence'] ?? []);
        $accepted = array_values(array_filter(
            (array) ($preflight['accepted_security_levels'] ?? []),
            'is_string',
        ));
        $rejected = (array) ($preflight['rejected_security_levels'] ?? []);
        $level = is_string($evidence['key_security_level'] ?? null)
            ? $evidence['key_security_level']
            : '';

        $checks = [];

        // 1. The device baseline. A green boot chain behind a locked
        //    bootloader is what makes the keystore's answer worth having; an
        //    emulator result satisfies nothing at all.
        // Both sides must be a real recorded string before they are compared.
        // Every other clause here compares against a hardcoded literal, so it
        // fails when its key is deleted; this one compares config to config,
        // and without the string guards `null === null` would let the check
        // PASS with NO boot state recorded at all — a device whose boot chain
        // is unknown or orange passing by having the field removed rather than
        // answered. Deletion must fail exactly like a wrong value.
        $requiredBoot = $preflight['verified_boot_state_required'] ?? null;
        $recordedBoot = $evidence['verified_boot_state'] ?? null;

        $baseline = ($evidence['physical_device'] ?? null) === true
            && ($evidence['emulator'] ?? null) === false
            && is_string($requiredBoot) && $requiredBoot !== ''
            && is_string($recordedBoot) && $recordedBoot === $requiredBoot
            && ($evidence['bootloader_locked'] ?? null) === true
            && ($evidence['vbmeta_locked'] ?? null) === true
            && is_string($evidence['device_label'] ?? null)
            && ($evidence['device_label'] ?? '') !== '';

        $checks[] = $this->check(
            'real_device_preflight_baseline',
            $baseline ? 'PASS' : 'FAIL',
            $baseline
                ? "Preflight recorded on physical device {$evidence['device_label']} with verified boot {$recordedBoot}, bootloader and vbmeta locked."
                : 'The recorded preflight is not a locked, verified-boot, physical device, records no boot state, or carries no logical device label.',
        );

        // 2. The measurement itself. Both halves are load-bearing: a
        //    hardware-backed key that can be exported is not a device
        //    identity, and an accepted level claimed without the probe having
        //    run is a claim, not a measurement.
        $measured = ($preflight['capability_flag_is_sufficient_proof'] ?? null) === false
            && $accepted !== []
            && in_array($level, $accepted, true)
            && ! in_array($level, $rejected, true)
            && ($preflight['private_key_must_be_non_exportable'] ?? null) === true
            && ($evidence['private_key_exportable'] ?? null) === false
            && ($evidence['hardware_backed_private_key'] ?? null) === true
            && ($evidence['keyinfo_result'] ?? null) === 'PASS'
            // `>= 1` alone is not enough: PHP 8 makes 'many' >= 1 and true >= 1
            // both true, so the one clause separating a MEASUREMENT from an
            // ASSERTION would be satisfiable by an arbitrary string.
            && is_int($evidence['keyinfo_tests_run'] ?? null)
            && $evidence['keyinfo_tests_run'] >= 1
            && ($evidence['keyinfo_tests_failed'] ?? null) === 0;

        $checks[] = $this->check(
            'key_security_level_accepted',
            $measured ? 'PASS' : 'FAIL',
            $measured
                ? "The generated AndroidKeyStore key measured {$level} and is non-exportable; the capability flag is explicitly not accepted as proof."
                : ($level === ''
                    ? 'No key security level is recorded. A capability flag is not a substitute for one.'
                    : "Recorded key security level '{$level}' is not an accepted Phase 4A level, or the key is exportable, or the probe did not pass."),
        );

        // 3. What the pass must NOT have moved. A hardware gate closing is
        //    the single most tempting moment to read the rest as consent, so
        //    the rest is asserted rather than assumed.
        // Every entry must be exactly `false`. A strict search for `true` would
        // miss 1, 1.0, 'true' and 'yes' — each of which reads to a human as
        // unlocked while leaving the check green.
        $unlocks = (array) ($preflight['unlocks'] ?? []);
        $claimed = array_keys(array_filter($unlocks, fn ($v): bool => $v !== false));

        $contained = $unlocks !== []
            && $claimed === []
            && $this->noUnauthorisedTrustAnchor()
            && ($evidence['production_device_identity_created'] ?? null) === false
            && ($evidence['keyinfo_probe_committed'] ?? null) === false
            && config('android_release.enforcement.owner_signoff.pilot_activated') === false
            && config('android_release.enforcement.current_stage') === 'off'
            && config('android_release.enforcement.active') === false;

        $checks[] = $this->check(
            'preflight_unlocks_nothing',
            $contained ? 'PASS' : 'FAIL',
            $contained
                // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 removed the
                // words "no production key" from this line. `$contained`
                // never read the custody key flag — it read the PIN — so the
                // claim was always wider than the evidence, and once a key
                // existed (created by a separate authorised task, not by this
                // preflight) it became simply untrue. A message must describe
                // what its own condition verified.
                //
                // PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1 hit the same
                // wall one field later, and fixing the message a second time
                // would not have been enough: the CONDITION still demanded the
                // pin be null forever, so an authorised pinning decision would
                // have been reported as a preflight that unlocked signing.
                // See `noUnauthorisedTrustAnchor()` for why this is a stricter
                // assertion rather than a removed one.
                ? 'The hardware gate closed and moved nothing else: no trust anchor it had no authority to introduce, no production device identity, no probe committed, and the enforcement ladder is still off.'
                : ($claimed !== []
                    ? 'A real-device preflight pass is recorded as unlocking: '.implode(', ', $claimed).'. It unlocks none of them.'
                    : 'A real-device preflight pass has been read as unlocking signing, enrolment or enforcement, or an unauthorised trust anchor is configured, or the containment record is missing. It unlocks none of them.'),
        );

        return $checks;
    }

    /**
     * Derived from the checks that were actually produced, never asserted
     * independently: a summary line that can disagree with its own checks is
     * a summary line that will eventually lie.
     *
     * @param  array<int,array<string,string>>  $checks
     */
    private function preflightPassed(array $checks): bool
    {
        $ids = ['real_device_preflight_baseline', 'key_security_level_accepted', 'preflight_unlocks_nothing'];

        foreach ($ids as $id) {
            $match = array_values(array_filter($checks, fn (array $c): bool => $c['id'] === $id));

            if ($match === [] || $match[0]['status'] !== 'PASS') {
                return false;
            }
        }

        return true;
    }

    /**
     * Custody readiness is reported from the CHECKS, not straight from the
     * config flag it is derived from. Reading the flag would let a single
     * edited boolean announce readiness while the destinations behind it were
     * unprotected, undesignated or claiming a key that does not exist — which
     * is the whole failure this scanner is here to make impossible.
     *
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function custodyReadyForProvisioning(array $checks): bool
    {
        $ids = [
            'signing_custody_status_recorded',
            'signing_custody_ready_for_provisioning',
            'custody_minimum_custodians_designated',
            'custody_primary_signing_authority_designated',
            'custody_key_generation_hosts_restricted',
            'custody_endpoint_controls_recorded',
            'custody_primary_secret_storage_encrypted',
            'custody_sealed_cold_destination_designated',
            'custody_offsite_destination_designated',
            'custody_media_secret_handling_rules',
            'custody_shared_access_declared',
            'custody_state_machine_consistent',
            'custody_readiness_does_not_claim_provisioning',
            'custody_status_matches_recorded_facts',
            'custody_records_no_secret_material',
        ];

        foreach ($ids as $id) {
            $match = array_values(array_filter($checks, fn (array $c): bool => $c['id'] === $id));

            if ($match === [] || $match[0]['status'] !== 'PASS') {
                return false;
            }
        }

        return true;
    }

    private function enforcementAuthorityChecks(): array
    {
        $signoff = (array) config('android_release.enforcement.owner_signoff');
        $stages = (array) config('android_release.enforcement.stages');
        $globalStage = (string) config('android_release.enforcement.global_enforcement_stage');
        $scope = is_string($signoff['authorized_scope'] ?? null) ? $signoff['authorized_scope'] : '';

        $named = array_values(array_filter(
            ['pilot_doctor', 'pilot_branch', 'rollback_owner', 'rollback_action'],
            fn (string $field): bool => ! is_string($signoff[$field] ?? null) || $signoff[$field] === '',
        ));

        // A pilot with no named rollback owner is not a pilot, it is a launch.
        $recorded = ($signoff['phase_4a_pilot_authorized'] ?? null) === true
            && ($signoff['pilot_activated'] ?? null) === false
            && $named === []
            && $scope !== ''
            && $stages !== []
            && in_array($scope, $stages, true)
            && $scope !== $globalStage;

        $checks = [];

        $checks[] = $this->check(
            'pilot_authority_recorded',
            $recorded ? 'PASS' : 'FAIL',
            $recorded
                ? "Owner authorized the '{$scope}' rung for a named pilot doctor and branch, with a named rollback owner, and it is recorded as authorized rather than activated."
                : ($named !== []
                    ? 'Pilot authorization is missing: '.implode(', ', $named)
                    : ($scope === $globalStage
                        ? 'The recorded pilot scope is the global rung. A pilot authorization cannot be the global one.'
                        : 'Pilot authorization is absent, marks itself activated, or names a rung that is not on the ladder.')),
        );

        $globalBound = config('android_release.enforcement.global_may_be_enabled_in_phase');
        $pilotBound = config('android_release.enforcement.pilot_may_be_enabled_in_phase');
        $retained = config('android_release.enforcement.may_be_enabled_in_phase');
        $retainedScope = config('android_release.enforcement.may_be_enabled_in_phase_scope');

        // The retained key is cited by rules, ADRs and two closure records, so
        // it stays. What it may not do is stay readable two ways: it now
        // declares the scope it bounds, and has to agree with the bound it
        // duplicates. If the two ever drift, the ambiguity is back and this
        // check is what notices.
        $bounded = $globalBound === 5
            && $retained === $globalBound
            && $retainedScope === $globalStage
            && $globalStage !== ''
            && $pilotBound !== $globalBound
            && config('android_release.enforcement.current_stage') === 'off';

        $checks[] = $this->check(
            'global_enforcement_deferred',
            $bounded ? 'PASS' : 'FAIL',
            $bounded
                ? "Global doctor enforcement is bounded to phase {$globalBound}; the pilot rung is bounded to phase {$pilotBound}; the ladder position in force is off."
                : 'The global enforcement bound is missing, disagrees with the retained authority, or the ladder is no longer at off.',
        );

        return $checks;
    }

    /**
     * Ignore rules that actually take effect: comments and blank lines dropped,
     * and a `!` negation removed from the set rather than counted as coverage.
     *
     * @return array<int,string>
     */
    private function effectiveIgnoreRules(): array
    {
        $path = $this->basePath.'/.gitignore';

        if (! is_file($path)) {
            return [];
        }

        $rules = [];

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '!')) {
                // An un-ignore cancels the rule it names. Treating it as
                // coverage would report "keystores are ignored" about a file
                // that guarantees the opposite.
                $rules = array_values(array_diff($rules, [substr($line, 1)]));

                continue;
            }

            $rules[] = $line;
        }

        return $rules;
    }

    /**
     * Read a file, refusing anything past the scan bound.
     *
     * The command is designed to run on untrusted fork pull requests, so an
     * unbounded read is an invitation to commit a multi-gigabyte build file and
     * exhaust the runner.
     */
    private function readBounded(string $path): ?string
    {
        // Guarded rather than suppressed. CICD-CRITICAL-GATE-FILE-GET-CONTENTS-
        // WARN-1 audits an exact set of files that read with the suppression
        // operator and says plainly that the remedy is never to copy that shape
        // into application code — so this uses the exemplary form that contract
        // describes: an is_readable() guard, then an explicit `=== false`
        // check, so a failed read is never folded into empty content.
        //
        // The operator's own name is deliberately not written here: that
        // contract matches raw source, so naming the pattern in the prose
        // explaining why it is avoided would add this file to the inventory of
        // files that use it. Same trap as the Phase 3 TLS scanner.
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $limit = (int) config('android_release.scanner.max_scanned_file_bytes', 1048576);
        $size = filesize($path);

        if ($size === false || $size > $limit) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** @return array<int,string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        // CATCH_GET_CHILD: an unreadable subdirectory must not throw out of the
        // whole command with a stack trace. Coverage loss is reported by the
        // surfaces_present check rather than swallowed.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Validate a Phase 4 release manifest against the recorded metadata policy.
     *
     * Provenance for an artifact nobody can rebuild is the difference between
     * "this is the build we tested" and "this is a build". No secret is read:
     * a certificate fingerprint is a public identifier.
     *
     * @param  array<string,mixed>  $manifest
     * @return array{status:string, missing:array<int,string>}
     */
    public function verifyReleaseManifest(array $manifest): array
    {
        $required = (array) config('android_release.versioning.release_metadata_required');

        // A key present but holding [] or false is not provenance. "Missing"
        // has to mean unusable, not merely unset, or a placeholder manifest
        // gates a release as though it carried a real build identity.
        $missing = array_values(array_filter(
            $required,
            function (string $key) use ($manifest): bool {
                $value = $manifest[$key] ?? null;

                return $value === null || ! is_scalar($value) || trim((string) $value) === '';
            },
        ));

        $digestFields = (array) config('android_release.versioning.release_metadata_sha256_fields');

        $malformed = array_values(array_filter(
            $digestFields,
            function (string $key) use ($manifest, $missing): bool {
                if (in_array($key, $missing, true)) {
                    return false; // already reported as missing
                }

                return preg_match('/^[0-9a-f]{64}$/i', (string) $manifest[$key]) !== 1;
            },
        ));

        return [
            'status' => $missing === [] && $malformed === [] ? 'PASS' : 'FAIL',
            'missing' => $missing,
            'malformed' => $malformed,
        ];
    }

    private function check(string $id, string $status, string $detail, ?string $reason = null): array
    {
        $check = ['id' => $id, 'status' => $status, 'detail' => $detail];

        if ($reason !== null) {
            $check['reason'] = $reason;
        }

        return $check;
    }
}
