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
                'signing_custody_ready_for_provisioning' => $this->custodyReadyForProvisioning($checks),
                'signing_custody_backups_created' => config('android_release.signing.custody.backup_1_key_copy_created') === true
                    || config('android_release.signing.custody.backup_2_key_copy_created') === true,
                'signing_custody_recovery_verified' => config('android_release.signing.custody.recovery_verified') === true,
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

        $readyForProvisioning = $status === 'ready_for_provisioning' && $notReady === [];

        $checks[] = $this->check(
            'signing_custody_ready_for_provisioning',
            $readyForProvisioning ? 'PASS' : 'FAIL',
            $readyForProvisioning
                ? 'All three custody destinations are designated and prepared. Key provisioning is permitted to start.'
                : ($notReady !== []
                    ? 'Destinations not marked ready: '.implode(', ', $notReady)
                    : "Custody state is '{$status}', not ready_for_provisioning."),
        );

        // ---- 3. Enough destinations to satisfy the standing policy. --------
        $minimum = (int) config('android_release.signing.minimum_custodians');
        $designated = count($custodians);

        $checks[] = $this->check(
            'custody_minimum_custodians_designated',
            $designated >= $minimum && $minimum > 0 ? 'PASS' : 'FAIL',
            $designated >= $minimum && $minimum > 0
                ? "{$designated} custody destinations designated against a minimum of {$minimum}."
                : "Only {$designated} custody destinations designated; policy requires {$minimum}.",
        );

        // ---- 4. Exactly one primary signing authority, and it is #1. -------
        $primaries = array_keys(array_filter(
            $custodians,
            fn ($c): bool => is_array($c) && ($c['role'] ?? null) === 'primary_signing_authority',
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

        // A flag alone is not trusted: the medium it sits on is cross-checked
        // against the permitted and forbidden lists, so a backup destination
        // cannot promote itself by flipping one boolean.
        $illegalGenerators = array_values(array_filter($generators, function (string $key) use ($custodians, $permittedMedia, $forbiddenMedia): bool {
            $medium = $custodians[$key]['media'] ?? null;

            return ! is_string($medium)
                || in_array($medium, $forbiddenMedia, true)
                || ! in_array($medium, $permittedMedia, true);
        }));

        $generationOk = $generators === ['custodian_1']
            && $illegalGenerators === []
            && $permittedMedia !== []
            && $forbiddenMedia !== [];

        $checks[] = $this->check(
            'custody_key_generation_hosts_restricted',
            $generationOk ? 'PASS' : 'FAIL',
            $generationOk
                ? 'Only the primary IT workstation may generate the first production key. VPS, CI, backup destinations, USB and the clinic device are all excluded.'
                : ($illegalGenerators !== []
                    ? 'Key generation authority claimed on a forbidden or unlisted medium: '.implode(', ', $illegalGenerators)
                    : ($generators === []
                        ? 'No key generation authority is designated.'
                        : 'Key generation authority is not restricted to custodian 1: '.implode(', ', $generators))),
        );

        // ---- 6. Endpoint controls on every workstation holding a copy. -----
        $workstations = array_filter(
            $custodians,
            fn ($c): bool => is_array($c) && ($c['medium_type'] ?? null) === 'workstation',
        );

        $unprotected = [];

        foreach ($workstations as $key => $workstation) {
            foreach (['disk_encryption', 'login_password', 'screen_lock'] as $control) {
                if (($workstation[$control] ?? null) !== true) {
                    $unprotected[] = "{$key}.{$control}";
                }
            }
        }

        $endpointOk = $workstations !== [] && $unprotected === [];

        $checks[] = $this->check(
            'custody_endpoint_controls_recorded',
            $endpointOk ? 'PASS' : 'FAIL',
            $endpointOk
                ? count($workstations).' custody workstations record disk encryption, a login password and a screen lock.'
                : ($workstations === []
                    ? 'No custody workstation was scanned; the endpoint control check would be vacuous.'
                    : 'Missing endpoint controls: '.implode(', ', $unprotected)),
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

        // Three media held by one party is not three custodians. Whichever way
        // that is answered it must be answered explicitly, and answering "yes"
        // costs compensating controls.
        $sharedOk = is_bool($shared) && ($shared === false || $controls !== []);

        $checks[] = $this->check(
            'custody_shared_access_declared',
            $sharedOk ? 'PASS' : 'FAIL',
            $sharedOk
                ? ($shared
                    ? 'One party can reach all copies; declared, with '.count($controls).' compensating controls recorded.'
                    : 'No single party can reach all copies.')
                : (is_bool($shared)
                    ? 'One party can reach all copies and no compensating control is recorded.'
                    : 'Whether one party can reach every copy is undeclared. It must be answered either way.'),
        );

        // ---- 11. The lifecycle cannot be claimed out of order. -------------
        $certificate = config('android_release.signing.production_certificate_sha256');
        $keyProvisioned = ($custody['production_signing_key_provisioned'] ?? null) === true;
        $pinned = is_string($certificate) && $certificate !== '';

        $anyBackup = ($custody['backup_1_key_copy_created'] ?? null) === true
            || ($custody['backup_2_key_copy_created'] ?? null) === true
            || ($custody['sealed_cold_backup_created'] ?? null) === true
            || ($custody['offsite_backup_created'] ?? null) === true;

        $recovered = ($custody['recovery_verified'] ?? null) === true;

        $inconsistencies = [];

        // A key that exists has a certificate. Claiming one without the other
        // means one of the two facts is fiction.
        if ($keyProvisioned && ! $pinned) {
            $inconsistencies[] = 'key claimed provisioned while no certificate fingerprint is recorded';
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

        $readinessHonest = $status !== 'ready_for_provisioning'
            || ($premature === [] && ! $pinned);

        $checks[] = $this->check(
            'custody_readiness_does_not_claim_provisioning',
            $readinessHonest ? 'PASS' : 'FAIL',
            $readinessHonest
                ? 'Custody is ready for provisioning and claims no key, no backup copy and no verified recovery. Those remain false.'
                : 'Custody is still ready_for_provisioning yet claims: '
                    .implode(', ', $premature !== [] ? $premature : ['a pinned production certificate'])
                    .'. Readiness is a decision, not an artifact.',
        );

        // ---- 13. No secret or locating material in a committed file. -------
        $leaks = $this->custodySecretLeaks($custody);

        $checks[] = $this->check(
            'custody_records_no_secret_material',
            $leaks === [] ? 'PASS' : 'FAIL',
            $leaks === []
                ? 'The custody designation records roles, media classes and locations only — no passphrase, serial, identifier or private address.'
                : 'Secret or locating material recorded in committed custody config: '.implode(', ', $leaks),
        );

        return $checks;
    }

    /**
     * Walk the custody designation looking for anything that must never be
     * committed. Matching is on the exact leaf key, so policy booleans such as
     * `login_password` and `password_stored_with_key_permitted` are not
     * mistaken for a stored password.
     *
     * @param  array<string,mixed>  $custody
     * @return array<int,string>
     */
    private function custodySecretLeaks(array $custody, string $path = 'custody'): array
    {
        $forbiddenKeys = [
            'serial', 'serial_number', 'hardware_serial', 'uuid', 'filesystem_uuid',
            'passphrase', 'password', 'key_password', 'store_password', 'keystore_password',
            'secret', 'token', 'credential', 'credentials', 'private_key', 'recovery_key',
            'street', 'street_address', 'home_address', 'postal_address', 'phone', 'email',
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

            // A long unbroken hex or base64-ish run in a governance file is a
            // fingerprint, a serial or a key. None of them belong here.
            if (is_string($value) && preg_match('/^[A-Fa-f0-9]{16,}$/', $value) === 1) {
                $leaks[] = $leafPath;
            }
        }

        return $leaks;
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
            && config('android_release.signing.production_certificate_sha256') === null
            && ($evidence['production_device_identity_created'] ?? null) === false
            && ($evidence['keyinfo_probe_committed'] ?? null) === false
            && config('android_release.enforcement.owner_signoff.pilot_activated') === false
            && config('android_release.enforcement.current_stage') === 'off'
            && config('android_release.enforcement.active') === false;

        $checks[] = $this->check(
            'preflight_unlocks_nothing',
            $contained ? 'PASS' : 'FAIL',
            $contained
                ? 'The hardware gate closed and moved nothing else: no production key, no certificate pin, no enrolment, no probe committed, and the enforcement ladder is still off.'
                : ($claimed !== []
                    ? 'A real-device preflight pass is recorded as unlocking: '.implode(', ', $claimed).'. It unlocks none of them.'
                    : 'A real-device preflight pass has been read as unlocking signing, enrolment or enforcement, or the containment record is missing. It unlocks none of them.'),
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
            'custody_sealed_cold_destination_designated',
            'custody_offsite_destination_designated',
            'custody_media_secret_handling_rules',
            'custody_shared_access_declared',
            'custody_state_machine_consistent',
            'custody_readiness_does_not_claim_provisioning',
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
