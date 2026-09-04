<?php

use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\KotlinSourceScanner;
use Illuminate\Support\Facades\Process;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1.
 *
 * Two separate things are pinned here, and they are unrelated except that the
 * same revision shipped them.
 *
 * FIRST — the release-readiness gate has to be runnable by the identity that
 * actually operates production. INFRA-SEC-RUNTIME-1 made the deployed tree
 * root-owned and the application runtime `daengtisiams`, which is the intended
 * boundary. Git's foreign-ownership refusal then made two checks red under the
 * runtime identity while they were green under root, and the messages blamed
 * the repository rather than the refusal: `gradle_wrapper_executable` said the
 * wrapper "is not tracked by git" when git had simply declined to look. A gate
 * that is only green for root is a gate operators learn to run as root.
 *
 * The fix must not be the obvious one. Trusting every repository, disabling the
 * ownership check, or running the audit as root would all turn the symptom off
 * and the control with it, so the tests below constrain the SHAPE of the fix as
 * hard as its effect: trust exactly this repository, only for the duration of
 * the invocation, and keep every other git failure closed.
 *
 * SECOND — the owner has authorized a Phase 4A PILOT of doctor device
 * enforcement (drg Karmila, Cabang Sunu, with a named rollback owner) while
 * GLOBAL enforcement stays forbidden until Phase 5. Recording an authorization
 * is not the same act as using it, and the tests keep those apart: after this
 * revision every enforcement switch is still off.
 */
function runtimeIdentityScanner(string $basePath): AndroidReleaseGovernanceScanner
{
    return new AndroidReleaseGovernanceScanner(new KotlinSourceScanner, $basePath);
}

function runtimeIdentityCheck(array $checks, string $id): array
{
    $found = collect($checks)->firstWhere('id', $id);

    expect($found)->not->toBeNull("Check {$id} disappeared from the scanner.");

    return $found;
}

/**
 * A throwaway repository with its own isolated git configuration.
 *
 * The isolation is the point, not tidiness. A developer who has already run
 * `git config --global --add safe.directory ...` for this checkout would
 * otherwise mask the very failure under test, and the suite would go green on
 * a machine-specific accident. GIT_CONFIG_GLOBAL and GIT_CONFIG_SYSTEM are
 * pointed at /dev/null so the only trust in play is the trust the scanner
 * itself passes.
 */
function runtimeIdentityRepo(array $trackedFiles): string
{
    $dir = sys_get_temp_dir().'/rev4a-git-'.bin2hex(random_bytes(8));

    mkdir($dir, 0755, true);

    foreach ($trackedFiles as $relative => $contents) {
        $path = $dir.'/'.$relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
    }

    $env = 'GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null GIT_TERMINAL_PROMPT=0';
    $who = '-c user.email=rev4a@test.invalid -c user.name=rev4a';

    shell_exec("env {$env} git -C ".escapeshellarg($dir).' init -q 2>&1');
    shell_exec("env {$env} git -C ".escapeshellarg($dir).' add -A 2>&1');
    shell_exec("env {$env} git {$who} -C ".escapeshellarg($dir).' commit -qm seed 2>&1');

    return $dir;
}

function runtimeIdentityRemove(string $dir): void
{
    if ($dir === '' || ! str_starts_with($dir, sys_get_temp_dir().'/rev4a-git-')) {
        return;
    }

    shell_exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Force git's foreign-ownership refusal without needing root.
 *
 * Returns a restore closure. A test that cannot actually provoke the refusal
 * must skip rather than pass, because a green assertion over a condition that
 * never occurred proves nothing about the condition.
 */
function runtimeIdentityForceForeignOwnership(string $repo): ?Closure
{
    $wanted = [
        'GIT_TEST_ASSUME_DIFFERENT_OWNER' => '1',
        // A developer who already trusts this checkout globally would
        // otherwise never see the refusal, and the suite would be green for a
        // reason that does not exist on the machine that matters.
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_SYSTEM' => '/dev/null',
    ];

    $previous = [];

    foreach (array_keys($wanted) as $key) {
        $previous[$key] = [
            'env' => getenv($key),
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'server_set' => array_key_exists($key, $_SERVER),
        ];
    }

    foreach ($wanted as $key => $value) {
        // Both, deliberately. Symfony builds a child environment from
        // getenv() INTERSECTED with $_SERVER, so a putenv-only variable is
        // filtered straight back out and never reaches git.
        putenv($key.'='.$value);
        $_SERVER[$key] = $value;
    }

    $restore = function () use ($previous): void {
        foreach ($previous as $key => $state) {
            $state['env'] === false ? putenv($key) : putenv($key.'='.$state['env']);

            if ($state['server_set']) {
                $_SERVER[$key] = $state['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }
    };

    // Presence check: prove the refusal is really happening on THIS git before
    // asserting that the scanner survives it.
    $plain = Process::path($repo)->run(['git', 'ls-files']);

    if ($plain->successful()) {
        $restore();

        return null;
    }

    return $restore;
}

// ---------------------------------------------------------------------------
// The git trust boundary
// ---------------------------------------------------------------------------

it('scopes git trust to this one repository and never to a wildcard', function () {
    Process::fake();

    app(AndroidReleaseGovernanceScanner::class)->scan();

    $trustValues = [];
    $sawGit = false;

    Process::assertRan(function ($process) use (&$trustValues, &$sawGit): bool {
        $command = $process->command;

        if (! is_array($command) || ($command[0] ?? null) !== 'git') {
            return false;
        }

        $sawGit = true;

        foreach ($command as $index => $argument) {
            if ($argument === '-c') {
                $trustValues[] = (string) ($command[$index + 1] ?? '');
            }
        }

        return true;
    });

    expect($sawGit)->toBeTrue('The scanner stopped invoking git, so the index checks are vacuous.');
    expect($trustValues)->not->toBeEmpty('No invocation-scoped configuration was passed to git.');

    $expected = 'safe.directory='.base_path();

    foreach ($trustValues as $value) {
        // Exactly this repository. Not a wildcard, not a parent, not a second
        // key smuggled in beside it.
        expect($value)->toBe($expected);
    }
});

it('passes the repository path as its own argument so a hostile path cannot inject', function () {
    Process::fake();

    // A path is not user input today, and this test is what keeps that from
    // becoming load-bearing: an argument array never reaches a shell, so even
    // this path is passed through verbatim rather than interpreted.
    $hostile = '/tmp/rev4a; touch /tmp/rev4a-pwned $(id)';

    runtimeIdentityScanner($hostile)->scan();

    Process::assertRan(function ($process) use ($hostile): bool {
        $command = $process->command;

        return is_array($command)
            && in_array('safe.directory='.$hostile, $command, true);
    });

    expect(file_exists('/tmp/rev4a-pwned'))->toBeFalse('The path reached a shell.');
});

it('reads the index under a foreign-ownership refusal that plain git rejects', function () {
    $repo = runtimeIdentityRepo(['README.md' => "seed\n"]);

    try {
        $restore = runtimeIdentityForceForeignOwnership($repo);

        if ($restore === null) {
            runtimeIdentityRemove($repo);
            $this->markTestSkipped('This git build does not honour the forced foreign-ownership switch.');
        }

        try {
            // This is the production symptom, reproduced: root sees a clean
            // index, the runtime identity is refused outright.
            $checks = runtimeIdentityScanner($repo)->scan()['checks'];

            $keyMaterial = runtimeIdentityCheck($checks, 'no_committed_key_material');

            expect($keyMaterial['status'])->toBe('PASS');
            expect($keyMaterial['reason'])->toBe(AndroidReleaseGovernanceScanner::REASON_CLEAN);
        } finally {
            $restore();
        }
    } finally {
        runtimeIdentityRemove($repo);
    }
});

it('still detects tracked key material under that same refusal', function () {
    // The fix must restore the check, not remove it. A scanner that can be
    // made to overlook a committed keystore by changing who runs it is worse
    // than one that refuses to run.
    $repo = runtimeIdentityRepo([
        'README.md' => "seed\n",
        'android/app/upload.jks' => "not a real key\n",
    ]);

    try {
        $restore = runtimeIdentityForceForeignOwnership($repo);

        if ($restore === null) {
            runtimeIdentityRemove($repo);
            $this->markTestSkipped('This git build does not honour the forced foreign-ownership switch.');
        }

        try {
            $checks = runtimeIdentityScanner($repo)->scan()['checks'];
            $keyMaterial = runtimeIdentityCheck($checks, 'no_committed_key_material');

            expect($keyMaterial['status'])->toBe('FAIL');
            expect($keyMaterial['reason'])
                ->toBe(AndroidReleaseGovernanceScanner::REASON_FORBIDDEN_KEY_MATERIAL_FOUND);
            expect($keyMaterial['detail'])->toContain('android/app/upload.jks');
        } finally {
            $restore();
        }
    } finally {
        runtimeIdentityRemove($repo);
    }
});

it('fails closed when the index cannot be read for any other reason', function () {
    // Every git failure other than the one narrow ownership case must stay
    // closed. "We could not look" is never "there is nothing there".
    $notARepo = sys_get_temp_dir().'/rev4a-git-'.bin2hex(random_bytes(8));
    mkdir($notARepo, 0755, true);

    try {
        $checks = runtimeIdentityScanner($notARepo)->scan()['checks'];

        $keyMaterial = runtimeIdentityCheck($checks, 'no_committed_key_material');

        expect($keyMaterial['status'])->toBe('FAIL');
        expect($keyMaterial['reason'])
            ->toBe(AndroidReleaseGovernanceScanner::REASON_INDEX_ACCESS_ERROR);
    } finally {
        runtimeIdentityRemove($notARepo);
    }
});

it('separates an unreadable index from a wrapper that is genuinely untracked', function () {
    // The production message said the Gradle wrapper "is not tracked by git"
    // when git had refused to read the index at all. Both are FAIL, but they
    // send an operator to two completely different places, and the gate has to
    // say which one happened.
    $notARepo = sys_get_temp_dir().'/rev4a-git-'.bin2hex(random_bytes(8));
    mkdir($notARepo, 0755, true);

    $repo = runtimeIdentityRepo(['README.md' => "seed\n"]);

    try {
        $accessError = runtimeIdentityCheck(
            runtimeIdentityScanner($notARepo)->scan()['checks'],
            'gradle_wrapper_executable',
        );

        expect($accessError['status'])->toBe('FAIL');
        expect($accessError['reason'])
            ->toBe(AndroidReleaseGovernanceScanner::REASON_INDEX_ACCESS_ERROR);
        expect($accessError['detail'])->not->toContain('is not tracked by git');

        // A readable index that genuinely does not carry the wrapper is the
        // other verdict, and it must still be reachable.
        $untracked = runtimeIdentityCheck(
            runtimeIdentityScanner($repo)->scan()['checks'],
            'gradle_wrapper_executable',
        );

        expect($untracked['status'])->toBe('FAIL');
        expect($untracked['reason'])
            ->toBe(AndroidReleaseGovernanceScanner::REASON_NOT_TRACKED);
    } finally {
        runtimeIdentityRemove($notARepo);
        runtimeIdentityRemove($repo);
    }
});

it('classifies a clean governed tree rather than leaving the verdict implicit', function () {
    $checks = app(AndroidReleaseGovernanceScanner::class)->scan()['checks'];

    expect(runtimeIdentityCheck($checks, 'no_committed_key_material')['reason'])
        ->toBe(AndroidReleaseGovernanceScanner::REASON_CLEAN);
    expect(runtimeIdentityCheck($checks, 'gradle_wrapper_executable')['reason'])
        ->toBe(AndroidReleaseGovernanceScanner::REASON_TRACKED);
});

it('introduces no persistent or wildcard git trust anywhere in the shipped tree', function () {
    // The shortcut this revision exists to avoid. A wildcard exemption, or a
    // persistent global entry written by a script, would make every repository
    // on the host trusted forever — including one an attacker can drop into a
    // world-writable directory.
    $roots = ['app', 'config', 'scripts', '.github/workflows', 'deploy'];
    $scanned = 0;
    $offenders = [];

    foreach ($roots as $root) {
        $path = base_path($root);

        if (! is_dir($path)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'safe'.'.directory')) {
                continue;
            }

            $scanned++;

            $wildcard = preg_match('/safe\.directory\s*=?\s*[\'"]?\*/', $contents) === 1;
            $persistent = preg_match('/--(global|system)\s+--add\s+safe\.directory/', $contents) === 1;

            if ($wildcard || $persistent) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    // Presence assertion: the scanner does pass a scoped trust, so a zero
    // scan count would mean this search found nothing at all and proved
    // nothing at all.
    expect($scanned)->toBeGreaterThan(0, 'No file referencing a git trust directive was found.');
    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------
// Owner Phase 4A pilot authority — recorded, not exercised
// ---------------------------------------------------------------------------

it('records the owner Phase 4A pilot authorization with a named rollback owner', function () {
    $signoff = config('android_release.enforcement.owner_signoff');

    expect($signoff)->toBeArray();
    expect($signoff['phase_4a_pilot_authorized'])->toBeTrue();
    expect($signoff['pilot_doctor'])->toBe('drg Karmila');
    expect($signoff['pilot_branch'])->toBe('Cabang Sunu');
    expect($signoff['rollback_owner'])->toBe('Custodian 1 / Raushan Fikri Ridha / IT');

    // A rollback owner with no rollback is a name on a form.
    expect($signoff['rollback_action'])->toBeString()->not->toBe('');
});

it('authorizes the pilot rung of the ladder and nothing above it', function () {
    $signoff = (array) config('android_release.enforcement.owner_signoff');
    $stages = (array) config('android_release.enforcement.stages');
    $global = (string) config('android_release.enforcement.global_enforcement_stage');

    // The scope has to be a real rung, or it authorizes nothing that exists.
    expect($stages)->toContain($signoff['authorized_scope']);
    expect($signoff['authorized_scope'])->toBe('pilot_branch_or_device');
    expect($signoff['authorized_scope'])->not->toBe($global);

    expect($global)->toBe('global_doctor_enforcement');
    expect($stages)->toContain($global);
});

it('keeps global doctor enforcement bounded to phase 5 with no second reading', function () {
    $global = config('android_release.enforcement.global_may_be_enabled_in_phase');
    $pilot = config('android_release.enforcement.pilot_may_be_enabled_in_phase');

    expect($global)->toBe(5);
    expect($pilot)->toBe('4A');

    // The pre-existing key stays, because docs and rules cite it — but it can
    // no longer be read two ways. It is the GLOBAL bound, it says so, and it
    // has to agree with the bound it duplicates. An authority that can be read
    // as "global is fine in Phase 4" is exactly the ambiguity this revision
    // was asked to remove.
    expect(config('android_release.enforcement.may_be_enabled_in_phase'))->toBe($global);
    expect(config('android_release.enforcement.may_be_enabled_in_phase_scope'))
        ->toBe((string) config('android_release.enforcement.global_enforcement_stage'));
});

it('records an authorization without activating one thing', function () {
    // Sign-off is permission to act later. If recording it moved any switch,
    // the record itself would be the deployment.
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.doctor_browser_login_denied'))->toBeFalse();
    expect(config('android_release.enforcement.current_stage'))->toBe('off');
    expect(config('android_release.enforcement.owner_signoff.pilot_activated'))->toBeFalse();

    expect(app(FeatureFlagService::class)
        ->enabled((string) config('android_release.enforcement.flag_key')))->toBeFalse();

    expect(config('feature_flags.flags')['doctor.trusted_device_enforcement']['default'])->toBeFalse();
});

it('surfaces the pilot authority and the global deferral as gate checks', function () {
    $checks = app(AndroidReleaseGovernanceScanner::class)->scan()['checks'];

    expect(runtimeIdentityCheck($checks, 'pilot_authority_recorded')['status'])->toBe('PASS');
    expect(runtimeIdentityCheck($checks, 'global_enforcement_deferred')['status'])->toBe('PASS');
});

it('provisions no production key and pins no certificate', function () {
    // Phase 4A authorization changes nothing about signing. Custody is still
    // partial: one custodian established, the offsite and sealed-cold controls
    // deferred by the owner, and no key in existence to hold.
    expect(config('android_release.signing.production_certificate_sha256'))->toBeNull();
    expect(config('android_release.signing.production_certificate_pin_required_before_install'))->toBeTrue();

    $summary = app(AndroidReleaseGovernanceScanner::class)->scan()['summary'];

    expect($summary['production_signing_key_provisioned'])->toBeFalse();
    expect($summary['real_device_validation'])->toBeFalse();
    expect($summary['device_enforcement_active'])->toBeFalse();
});

it('registers this suite in the critical gate so something selects it', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidRuntimeIdentityReadinessTest.php');
});
