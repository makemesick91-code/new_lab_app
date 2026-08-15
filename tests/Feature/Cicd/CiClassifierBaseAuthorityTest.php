<?php

declare(strict_types=1);

/**
 * DEVFLOW-FIX-BASE-REF-1 — CI classifier base authority.
 *
 * `scripts/ci/resolve-gates.sh` decides which expensive gates run. It must
 * pin an EXACT commit id before diffing, must never let a ref value become a
 * git option, and must resolve every uncertainty to the strongest gate.
 *
 * These tests drive the real script against real throwaway git repositories.
 */
beforeEach(function () {
    $GLOBALS['ci_base_fixtures'] = [];
});

afterEach(function () {
    foreach (($GLOBALS['ci_base_fixtures'] ?? []) as $dir) {
        if (is_dir($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
    $GLOBALS['ci_base_fixtures'] = [];
});

function ciBaseTempDir(): string
{
    $dir = sys_get_temp_dir().'/devflow-fix-base-ref-1-ci-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $GLOBALS['ci_base_fixtures'][] = $dir;

    return $dir;
}

/**
 * @param  list<string>  $args
 */
function ciBaseGit(string $cwd, array $args): string
{
    $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args));
    $out = [];
    $code = 0;
    exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);

    if ($code !== 0) {
        throw new RuntimeException('git failed: '.implode("\n", $out));
    }

    return trim(implode("\n", $out));
}

function ciBaseCommit(string $repo, string $file, string $content, string $message): string
{
    $path = $repo.'/'.$file;
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $content);
    ciBaseGit($repo, ['add', '--', $file]);
    ciBaseGit($repo, ['commit', '-m', $message]);

    return ciBaseGit($repo, ['rev-parse', 'HEAD']);
}

/**
 * A repo with a base commit and a feature commit on top.
 *
 * @return array{repo:string,base:string,head:string}
 */
function ciBaseRepo(string $featureFile = 'docs/note.md'): array
{
    $repo = ciBaseTempDir();
    ciBaseGit($repo, ['init', '--initial-branch=main', '.']);
    ciBaseGit($repo, ['config', 'user.email', 'devflow@example.test']);
    ciBaseGit($repo, ['config', 'user.name', 'DEVFLOW Fixture']);
    ciBaseGit($repo, ['config', 'commit.gpgsign', 'false']);

    $base = ciBaseCommit($repo, 'README.md', "seed\n", 'seed');
    ciBaseGit($repo, ['checkout', '--quiet', '-b', 'feature/work']);
    $head = ciBaseCommit($repo, $featureFile, "content\n", 'work');

    return ['repo' => $repo, 'base' => $base, 'head' => $head];
}

/**
 * Run the real classifier inside $repo.
 *
 * @param  list<string>  $args
 * @return array{code:int,stdout:string,stderr:string}
 */
function runClassifier(string $repo, array $args): array
{
    $script = base_path('scripts/ci/resolve-gates.sh');
    $errFile = ciBaseTempDir().'/stderr.log';

    $cmd = 'cd '.escapeshellarg($repo).' && bash '.escapeshellarg($script).' '
        .implode(' ', array_map('escapeshellarg', $args))
        .' 2>'.escapeshellarg($errFile);

    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    return [
        'code' => $code,
        'stdout' => implode("\n", $out),
        'stderr' => is_file($errFile) ? (string) file_get_contents($errFile) : '',
    ];
}

/** @return array<string,string> */
function classifierKv(string $stdout): array
{
    $kv = [];
    foreach (explode("\n", $stdout) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $kv[trim($k)] = trim($v);
        }
    }

    return $kv;
}

it('pins an exact base sha and reports it as the authority', function () {
    $f = ciBaseRepo();

    $result = runClassifier($f['repo'], ['--base-sha', $f['base'], '--head', $f['head']]);
    $kv = classifierKv($result['stdout']);

    expect($result['code'])->toBe(0)
        ->and($kv['base_source'])->toBe('explicit_sha')
        ->and($kv['base_sha'])->toBe($f['base'])
        ->and($kv['head_sha'])->toBe($f['head'])
        ->and($kv['gate_profile'])->toBe('docs_only')
        ->and($kv['changed_file_count'])->toBe('1');
});

it('pins a branch ref to its exact commit before diffing', function () {
    $f = ciBaseRepo();

    $result = runClassifier($f['repo'], ['--base', 'main', '--head', 'HEAD']);
    $kv = classifierKv($result['stdout']);

    expect($result['code'])->toBe(0)
        ->and($kv['base_source'])->toBe('remote_tracking_ref')
        // The reported authority is the exact commit id, never the name.
        ->and($kv['base_sha'])->toBe($f['base'])
        ->and($kv['head_sha'])->toBe($f['head']);
});

it('classifies a runtime change with the strong gate against the pinned base', function () {
    $f = ciBaseRepo('app/Runtime.php');

    $result = runClassifier($f['repo'], ['--base-sha', $f['base'], '--head', $f['head']]);
    $kv = classifierKv($result['stdout']);

    expect($kv['base_sha'])->toBe($f['base'])
        ->and($kv['gate_profile'])->not->toBe('docs_only')
        ->and($kv['run_critical_tests'])->toBe('true');
});

it('never lets a ref value become a git option', function (string $hostile) {
    $f = ciBaseRepo();

    $result = runClassifier($f['repo'], ['--base', $hostile, '--head', 'HEAD']);
    $kv = classifierKv($result['stdout']);

    // Rejected -> strongest gate, and no exact base is ever published.
    expect($result['code'])->toBe(0)
        ->and($kv['gate_profile'])->toBe('unknown_high_risk')
        ->and($kv['run_critical_tests'])->toBe('true')
        ->and($kv['base_sha'])->toBe('UNRESOLVED')
        ->and($kv['base_source'])->toBe('none');

    // And nothing was written outside the fixture.
    expect(file_exists('/tmp/devflow-base-ref-pwned'))->toBeFalse();
})->with([
    '--upload-pack=touch /tmp/devflow-base-ref-pwned',
    '--output=/tmp/devflow-base-ref-pwned',
    '-o',
    'main..HEAD',
    'main;touch /tmp/devflow-base-ref-pwned',
    'main$(touch /tmp/devflow-base-ref-pwned)',
    'main space',
]);

it('falls back to the strongest gate when the base sha is not an exact commit id', function (string $bad) {
    $f = ciBaseRepo();

    $result = runClassifier($f['repo'], ['--base-sha', $bad, '--head', 'HEAD']);
    $kv = classifierKv($result['stdout']);

    expect($kv['gate_profile'])->toBe('unknown_high_risk')
        ->and($kv['run_critical_tests'])->toBe('true')
        ->and($kv['base_sha'])->toBe('UNRESOLVED');
})->with(['HEAD', 'HEAD~1', 'main', 'deadbee', '--help', 'not-a-sha']);

it('falls back to the strongest gate when the base object does not exist', function () {
    $f = ciBaseRepo();
    $absent = str_repeat('a', 39).'b';

    $result = runClassifier($f['repo'], ['--base-sha', $absent, '--head', 'HEAD']);
    $kv = classifierKv($result['stdout']);

    expect($kv['gate_profile'])->toBe('unknown_high_risk')
        ->and($kv['run_critical_tests'])->toBe('true')
        ->and($kv['base_sha'])->toBe('UNRESOLVED');
});

it('falls back to the strongest gate when no base is supplied at all', function () {
    $f = ciBaseRepo();

    $result = runClassifier($f['repo'], ['--head', 'HEAD']);
    $kv = classifierKv($result['stdout']);

    expect($kv['gate_profile'])->toBe('unknown_high_risk')
        ->and($kv['run_critical_tests'])->toBe('true')
        ->and($kv['base_source'])->toBe('none');
});

it('reports the base authority in json output too', function () {
    $f = ciBaseRepo();

    $result = runClassifier($f['repo'], ['--base-sha', $f['base'], '--head', $f['head'], '--json']);
    $json = json_decode($result['stdout'], true);

    expect($json)->toBeArray()
        ->and($json['base_source'])->toBe('explicit_sha')
        ->and($json['base_sha'])->toBe($f['base'])
        ->and($json['head_sha'])->toBe($f['head']);
});

it('keeps the docs-only safety invariant while pinning an exact base', function () {
    $f = ciBaseRepo('docs/only.md');

    $result = runClassifier($f['repo'], ['--base-sha', $f['base'], '--head', $f['head']]);
    $kv = classifierKv($result['stdout']);

    // docs_only remains the ONLY profile allowed to skip the critical step.
    expect($kv['gate_profile'])->toBe('docs_only')
        ->and($kv['run_critical_tests'])->toBe('false')
        ->and($kv['base_sha'])->toBe($f['base']);

    expect((array) config('ci_runtime_control.skip_critical_profiles'))->toBe(['docs_only'])
        ->and((string) config('ci_runtime_control.default_profile'))->toBe('unknown_high_risk');
});

it('produces the same classification for the same pinned base regardless of branch movement', function () {
    $f = ciBaseRepo('app/Runtime.php');

    $first = classifierKv(runClassifier($f['repo'], ['--base-sha', $f['base'], '--head', $f['head']])['stdout']);

    // A branch advances; the pinned SHA classification must not change.
    ciBaseGit($f['repo'], ['checkout', '--quiet', 'main']);
    ciBaseCommit($f['repo'], 'app/Moved.php', "<?php\n", 'main moved on');
    ciBaseGit($f['repo'], ['checkout', '--quiet', 'feature/work']);

    $second = classifierKv(runClassifier($f['repo'], ['--base-sha', $f['base'], '--head', $f['head']])['stdout']);

    expect($second['base_sha'])->toBe($first['base_sha'])
        ->and($second['gate_profile'])->toBe($first['gate_profile'])
        ->and($second['changed_file_count'])->toBe($first['changed_file_count']);
});

it('wires the immutable pull-request base sha into the workflow', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    // The PR event carries an immutable base sha for THIS run; use it.
    expect($workflow)->toContain('github.event.pull_request.base.sha')
        ->and($workflow)->toContain('--base-sha')
        // The authority must be published as evidence.
        ->and($workflow)->toContain('BASE_SOURCE=')
        ->and($workflow)->toContain('BASE_SHA=')
        ->and($workflow)->toContain('base_sha: ${{ steps.resolve.outputs.base_sha }}');

    // The classifier job still checks out full history so the base is reachable.
    expect($workflow)->toContain('fetch-depth: 0');
});
