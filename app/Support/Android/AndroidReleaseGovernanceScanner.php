<?php

namespace App\Support\Android;

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
    public function __construct(
        private readonly KotlinSourceScanner $kotlin,
        private readonly string $basePath,
    ) {}

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
            $this->directApkDistributionChecks(),
            $this->enforcementChecks(),
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
                // Deliberately reported, never inferred. Phase 3.5 ends with no
                // production key in existence, and saying otherwise would be
                // the single most damaging thing this command could print.
                'production_signing_key_provisioned' => false,
                'real_device_validation' => false,
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

        $result = Process::path($this->basePath)->run(['git', 'ls-files']);

        if (! $result->successful()) {
            // Append rather than return: discarding the armed check above would
            // silently shrink the reported check count on this path. The
            // ignore-file check does not need git, so it still runs below.
            $checks[] = $this->check('no_committed_key_material', 'FAIL', 'Could not read the git index to verify no key material is tracked.');

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

        $result = Process::path($this->basePath)->run(['git', 'ls-files', '-s', '--', $relative]);
        $output = trim($result->output());

        if (! $result->successful() || $output === '') {
            return [$this->check('gradle_wrapper_executable', 'FAIL', "{$relative} is not tracked by git.")];
        }

        $mode = explode(' ', $output)[0];

        return [$this->check(
            'gradle_wrapper_executable',
            $mode === $expected ? 'PASS' : 'FAIL',
            $mode === $expected
                ? 'The Gradle wrapper is committed executable.'
                : "The Gradle wrapper is committed {$mode}; CI needs {$expected}.",
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

    private function check(string $id, string $status, string $detail): array
    {
        return ['id' => $id, 'status' => $status, 'detail' => $detail];
    }
}
