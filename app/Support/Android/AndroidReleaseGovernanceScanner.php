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
    private function governanceDecisionChecks(): array
    {
        $decisions = [
            'signing_authority_decided' => config('android_release.signing.app_signing_authority'),
            'upload_key_custody_decided' => config('android_release.signing.upload_key_authority'),
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
                ? 'versionCode is monotonic; rollback is halt-then-forward-fix.'
                : 'versionCode policy permits reuse or decrement, which Play rejects.',
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

        $off = config('android_release.enforcement.active') === false
            && config('android_release.enforcement.doctor_browser_login_denied') === false
            && config('android_release.enforcement.flag_exists') === false;

        $checks[] = $this->check(
            'enforcement_off',
            $off ? 'PASS' : 'FAIL',
            $off
                ? 'Device enforcement is off, browser login is not denied, and no flag exists to flip.'
                : 'Configuration claims enforcement is active. Phase 3.5 must end with it off.',
        );

        $token = (string) config('android_release.scanner.enforcement_coupling_token');
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

                if ($contents !== null && str_contains($contents, $token)) {
                    $coupled[] = ltrim(str_replace($this->basePath, '', $file), '/');
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
            'authentication_not_coupled_to_device_registry',
            $coupled === [] ? 'PASS' : 'FAIL',
            $coupled === []
                ? 'No authentication, session, middleware-registration or module-middleware surface references the device registry.'
                : 'Enforcement coupling found in: '.implode(', ', $coupled),
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
