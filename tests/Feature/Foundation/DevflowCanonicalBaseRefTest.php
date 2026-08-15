<?php

declare(strict_types=1);

use App\Services\Foundation\DevflowGovernanceService;
use App\Support\Devflow\CanonicalBaseRef;
use App\Support\Devflow\CanonicalBaseRefResolver;
use App\Support\Devflow\DevflowScanner;
use App\Support\Devflow\GitChangeInspector;
use Illuminate\Support\Facades\Artisan;

/**
 * DEVFLOW-FIX-BASE-REF-1 — canonical remote base resolution.
 *
 * These tests build REAL throwaway git repositories (an "origin" bare remote
 * plus one or more clones) so the stale/ahead/diverged local-ref scenarios are
 * exercised against actual git behaviour, not a mock of it. Every fixture is
 * removed in afterEach; nothing is created inside the project repository.
 */

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

/** @var list<string> $GLOBALS['devflow_base_ref_fixtures'] */
beforeEach(function () {
    $GLOBALS['devflow_base_ref_fixtures'] = [];
});

afterEach(function () {
    foreach (($GLOBALS['devflow_base_ref_fixtures'] ?? []) as $dir) {
        if (is_dir($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
    $GLOBALS['devflow_base_ref_fixtures'] = [];
});

function baseRefTempDir(string $suffix): string
{
    $dir = sys_get_temp_dir().'/devflow-fix-base-ref-1-'.$suffix.'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $GLOBALS['devflow_base_ref_fixtures'][] = $dir;

    return $dir;
}

/**
 * Run git in $cwd with an argument array (never a shell string).
 *
 * @param  list<string>  $args
 */
function baseRefGit(string $cwd, array $args): string
{
    $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args));
    $out = [];
    $code = 0;
    exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);

    if ($code !== 0) {
        throw new RuntimeException("git {$args[0]} failed in {$cwd}: ".implode("\n", $out));
    }

    return trim(implode("\n", $out));
}

function baseRefCommit(string $repo, string $file, string $content, string $message): string
{
    $path = $repo.'/'.$file;
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $content);
    baseRefGit($repo, ['add', '--', $file]);
    baseRefGit($repo, ['commit', '-m', $message]);

    return baseRefGit($repo, ['rev-parse', 'HEAD']);
}

/**
 * Build: a bare "origin", plus a clone whose local base branch can be moved
 * independently of the remote-tracking ref.
 *
 * @return array{origin:string,clone:string,branch:string}
 */
function baseRefScenario(string $branch = 'feature/canonical-base'): array
{
    $origin = baseRefTempDir('origin');
    $seed = baseRefTempDir('seed');

    baseRefGit($origin, ['init', '--bare', '--initial-branch=main', '.']);

    baseRefGit($seed, ['init', '--initial-branch=main', '.']);
    baseRefGit($seed, ['config', 'user.email', 'devflow@example.test']);
    baseRefGit($seed, ['config', 'user.name', 'DEVFLOW Fixture']);
    baseRefGit($seed, ['config', 'commit.gpgsign', 'false']);
    baseRefCommit($seed, 'README.md', "seed\n", 'seed: initial');
    baseRefGit($seed, ['checkout', '-b', $branch]);
    baseRefCommit($seed, 'docs/base.md', "base v1\n", 'base: v1');
    baseRefGit($seed, ['remote', 'add', 'origin', $origin]);
    baseRefGit($seed, ['push', '--quiet', 'origin', 'main', $branch]);

    $clone = baseRefTempDir('clone');
    exec('git clone --quiet '.escapeshellarg($origin).' '.escapeshellarg($clone).' 2>&1');
    baseRefGit($clone, ['config', 'user.email', 'devflow@example.test']);
    baseRefGit($clone, ['config', 'user.name', 'DEVFLOW Fixture']);
    baseRefGit($clone, ['config', 'commit.gpgsign', 'false']);
    baseRefGit($clone, ['checkout', '--quiet', '-B', $branch, 'origin/'.$branch]);

    return ['origin' => $origin, 'clone' => $clone, 'branch' => $branch, 'seed' => $seed];
}

/** Point the resolver's config at a fixture branch. */
function baseRefUseBranch(string $branch): void
{
    config()->set('devflow.manifest.required_base_branch', $branch);
}

// ---------------------------------------------------------------------------
// Case 1 — the core regression: local base ref is STALE
// ---------------------------------------------------------------------------

it('resolves the canonical remote base when the local base ref is stale', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // Remote advances to B; the clone's LOCAL branch stays pinned at A.
    $localA = baseRefGit($s['clone'], ['rev-parse', 'refs/heads/'.$s['branch']]);
    baseRefCommit($s['seed'], 'docs/base.md', "base v2\n", 'base: v2');
    baseRefGit($s['seed'], ['push', '--quiet', 'origin', $s['branch']]);
    $remoteB = baseRefGit($s['seed'], ['rev-parse', 'HEAD']);

    expect($localA)->not->toBe($remoteB);

    // Work on a feature branch off the stale local base.
    baseRefGit($s['clone'], ['checkout', '--quiet', '-b', 'feature/work']);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();

    expect($base->resolved)->toBeTrue()
        ->and($base->sha)->toBe($remoteB)
        ->and($base->sha)->not->toBe($localA)
        ->and($base->source)->toBe(CanonicalBaseRef::SOURCE_REMOTE_TRACKING_REF)
        ->and($base->localSha)->toBe($localA)
        ->and($base->localStale)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Case 2 — local base is AHEAD / diverged: authority still wins
// ---------------------------------------------------------------------------

it('uses the canonical remote even when the local base branch is ahead', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    $remoteB = baseRefGit($s['clone'], ['rev-parse', 'refs/remotes/origin/'.$s['branch']]);

    // Local base gets extra commits that were never pushed.
    baseRefCommit($s['clone'], 'docs/local-only.md', "local\n", 'local: unpushed');
    $localAhead = baseRefGit($s['clone'], ['rev-parse', 'refs/heads/'.$s['branch']]);
    expect($localAhead)->not->toBe($remoteB);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();

    // Canonical is about AUTHORITY, not commit recency or graph height.
    expect($base->sha)->toBe($remoteB)
        ->and($base->sha)->not->toBe($localAhead)
        ->and($base->localStale)->toBeTrue();
});

it('uses the canonical remote when the local base branch has diverged', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // Remote moves one way...
    baseRefCommit($s['seed'], 'docs/base.md', "remote side\n", 'base: remote side');
    baseRefGit($s['seed'], ['push', '--quiet', 'origin', $s['branch']]);
    $remoteB = baseRefGit($s['seed'], ['rev-parse', 'HEAD']);

    // ...and the local base moves a different way (true divergence).
    baseRefCommit($s['clone'], 'docs/local.md', "local side\n", 'base: local side');
    $localC = baseRefGit($s['clone'], ['rev-parse', 'refs/heads/'.$s['branch']]);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();

    expect($base->resolved)->toBeTrue()
        ->and($base->sha)->toBe($remoteB)
        ->and($base->sha)->not->toBe($localC);
});

// ---------------------------------------------------------------------------
// Case 3 — explicit exact SHA outranks everything
// ---------------------------------------------------------------------------

it('prefers an authoritative explicit exact SHA over the remote branch', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    $pinned = baseRefGit($s['clone'], ['rev-parse', 'HEAD']);

    baseRefCommit($s['seed'], 'docs/base.md', "moved on\n", 'base: moved on');
    baseRefGit($s['seed'], ['push', '--quiet', 'origin', $s['branch']]);
    $remoteB = baseRefGit($s['seed'], ['rev-parse', 'HEAD']);
    expect($remoteB)->not->toBe($pinned);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve($pinned);

    expect($base->resolved)->toBeTrue()
        ->and($base->sha)->toBe($pinned)
        ->and($base->source)->toBe(CanonicalBaseRef::SOURCE_EXPLICIT_SHA);
});

// ---------------------------------------------------------------------------
// Fail-closed matrix
// ---------------------------------------------------------------------------

it('fails closed when the canonical remote cannot be resolved and no exact SHA is supplied', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // Local branch still exists and is perfectly usable — the whole point is
    // that we refuse it. Remove the remote-tracking ref and break the remote.
    baseRefGit($s['clone'], ['update-ref', '-d', 'refs/remotes/origin/'.$s['branch']]);
    baseRefGit($s['clone'], ['remote', 'set-url', 'origin', $s['clone'].'/definitely-not-a-repo']);

    $localSha = baseRefGit($s['clone'], ['rev-parse', 'refs/heads/'.$s['branch']]);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();

    expect($base->resolved)->toBeFalse()
        ->and($base->sha)->toBeNull()
        ->and($base->source)->toBe(CanonicalBaseRef::SOURCE_UNAVAILABLE)
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_AUTHORITY_UNAVAILABLE)
        // The local ref is reported as a diagnostic but never adopted.
        ->and($base->localSha)->toBe($localSha)
        ->and($base->failureReason)->toContain('Refusing to fall back');
});

it('rejects revision expressions and options offered as an exact SHA', function (string $bad) {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve($bad);

    expect($base->resolved)->toBeFalse()
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_INVALID_SHA);
})->with([
    'not-a-sha',
    'HEAD',
    'HEAD~1',
    'HEAD^{commit}',
    '--help',
    '--upload-pack=touch /tmp/pwned',
    'main',
    'refs/heads/main',
    'deadbee', // abbreviated: "exact" means exact
    '; rm -rf /',
    '$(whoami)',
]);

it('fails closed when a syntactically valid SHA names an object that does not exist', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    $absent = str_repeat('a', 39).'b';

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve($absent);

    expect($base->resolved)->toBeFalse()
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_OBJECT_MISSING);
});

it('fails closed when the supplied SHA is not a commit object', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // A real tree object: valid id, valid object, wrong type.
    $treeSha = baseRefGit($s['clone'], ['rev-parse', 'HEAD^{tree}']);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve($treeSha);

    expect($base->resolved)->toBeFalse()
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_NOT_A_COMMIT);
});

it('rejects an unsafe base branch name before it can reach a git argument', function (string $branch) {
    $s = baseRefScenario();
    baseRefUseBranch($branch);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();

    expect($base->resolved)->toBeFalse()
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_INVALID_BRANCH);
})->with([
    '--upload-pack=evil',
    '-o',
    'feature/x..main',
    'feature/x^{commit}',
    'feature/x~1',
    'feature/x:main',
    'feature/with space',
    'feature/x@{upstream}',
]);

it('fails closed when the configured canonical remote does not exist', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);
    config()->set('devflow.base_resolution.remote', 'upstream'); // present in config, absent in repo

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();

    expect($base->resolved)->toBeFalse()
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_REMOTE_AMBIGUOUS);
});

it('never auto-selects a remote when several are configured', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // Add a second remote; `origin` stays the explicitly configured authority.
    baseRefGit($s['clone'], ['remote', 'add', 'upstream', $s['origin']]);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();
    expect($base->resolved)->toBeTrue()->and($base->remote)->toBe('origin');

    // With an unconfigured remote name, it fails closed rather than guessing.
    config()->set('devflow.base_resolution.remote', '');
    $ambiguous = (new CanonicalBaseRefResolver($s['clone']))->resolve();
    expect($ambiguous->resolved)->toBeFalse()
        ->and($ambiguous->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_REMOTE_AMBIGUOUS);
});

// ---------------------------------------------------------------------------
// Pinning: a moving remote must not change an in-flight run
// ---------------------------------------------------------------------------

it('pins the base for the whole invocation even if the remote moves afterwards', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    $resolver = new CanonicalBaseRefResolver($s['clone']);
    $first = $resolver->resolve();
    expect($first->resolved)->toBeTrue();

    // Remote advances mid-run.
    baseRefCommit($s['seed'], 'docs/base.md', "moved during run\n", 'base: moved during run');
    baseRefGit($s['seed'], ['push', '--quiet', 'origin', $s['branch']]);
    $movedTo = baseRefGit($s['seed'], ['rev-parse', 'HEAD']);
    expect($movedTo)->not->toBe($first->sha);

    // Same instance == same invocation: the pinned SHA must not change.
    expect($resolver->resolve()->sha)->toBe($first->sha);

    // A NEW invocation legitimately observes the newer authority.
    expect((new CanonicalBaseRefResolver($s['clone']))->resolve()->sha)->toBe($movedTo);
});

// ---------------------------------------------------------------------------
// Wrong checkout / worktree isolation
// ---------------------------------------------------------------------------

it('compares the checkout it was pointed at, not another unrelated checkout', function () {
    $primary = baseRefScenario('feature/primary-base');
    $task = baseRefScenario('feature/task-base');

    // Simulate the historical defect: the "primary checkout" sits on an
    // unrelated branch while the task worktree is what we actually audit.
    baseRefGit($primary['clone'], ['checkout', '--quiet', '-b', 'ci-evidence/unrelated']);
    baseRefCommit($primary['clone'], 'unrelated/huge.md', str_repeat("noise\n", 50), 'unrelated: noise');

    baseRefUseBranch($task['branch']);
    baseRefGit($task['clone'], ['checkout', '--quiet', '-b', 'feature/task-work']);
    baseRefCommit($task['clone'], 'app/Task.php', "<?php // task\n", 'task: one file');

    $changed = (new GitChangeInspector($task['clone']))->changedFiles();

    expect($changed['resolved'])->toBeTrue()
        ->and($changed['files'])->toContain('app/Task.php')
        // The unrelated primary checkout must not leak in.
        ->and($changed['files'])->not->toContain('unrelated/huge.md');

    // And each resolver reports its own repository root.
    expect((new CanonicalBaseRefResolver($task['clone']))->repositoryRoot())->toBe(realpath($task['clone']))
        ->and((new CanonicalBaseRefResolver($primary['clone']))->repositoryRoot())->toBe(realpath($primary['clone']));
});

it('fails closed when pointed at a path that is not the repository root it claims', function () {
    $outside = baseRefTempDir('outside');

    $base = (new CanonicalBaseRefResolver($outside))->resolve();

    expect($base->resolved)->toBeFalse()
        ->and($base->failureCode)->toBe(CanonicalBaseRefResolver::FAILURE_WRONG_REPOSITORY);
});

// ---------------------------------------------------------------------------
// Scope regression: a stale local base must not widen the change set
// ---------------------------------------------------------------------------

it('reports only the branch changes when the stale local base would have widened the diff', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // Remote gains several files the local base does not know about.
    baseRefCommit($s['seed'], 'app/Unrelated1.php', "<?php // 1\n", 'base: unrelated 1');
    baseRefCommit($s['seed'], 'app/Unrelated2.php', "<?php // 2\n", 'base: unrelated 2');
    baseRefGit($s['seed'], ['push', '--quiet', 'origin', $s['branch']]);
    baseRefGit($s['clone'], ['fetch', '--quiet', 'origin']);

    // Feature branch is cut from the CURRENT canonical remote tip...
    baseRefGit($s['clone'], ['checkout', '--quiet', '-b', 'feature/work', 'refs/remotes/origin/'.$s['branch']]);
    baseRefCommit($s['clone'], 'app/OnlyMine.php', "<?php // mine\n", 'work: one file');

    // ...while the LOCAL base ref is still far behind.
    $localSha = baseRefGit($s['clone'], ['rev-parse', 'refs/heads/'.$s['branch']]);
    $remoteSha = baseRefGit($s['clone'], ['rev-parse', 'refs/remotes/origin/'.$s['branch']]);
    expect($localSha)->not->toBe($remoteSha);

    $changed = (new GitChangeInspector($s['clone']))->changedFiles();

    // Correct, narrow scope. A stale-local diff would have wrongly included the
    // two unrelated base files and inflated the module count.
    expect($changed['resolved'])->toBeTrue()
        ->and($changed['files'])->toBe(['app/OnlyMine.php'])
        ->and($changed['base'])->toBe($remoteSha);
});

it('returns an unresolved change set instead of an empty one when the base is unavailable', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    baseRefGit($s['clone'], ['update-ref', '-d', 'refs/remotes/origin/'.$s['branch']]);
    baseRefGit($s['clone'], ['remote', 'set-url', 'origin', $s['clone'].'/definitely-not-a-repo']);

    $changed = (new GitChangeInspector($s['clone']))->changedFiles();

    // An empty-but-"resolved" set is the dangerous outcome: it passes every
    // contradiction check. It must be reported as UNRESOLVED instead.
    expect($changed['resolved'])->toBeFalse()
        ->and($changed['base'])->toBe('UNRESOLVED')
        ->and($changed['reason'])->toContain('BASE_AUTHORITY_UNAVAILABLE');
});

// ---------------------------------------------------------------------------
// Working-tree parsing: modified tracked paths must not be truncated
// ---------------------------------------------------------------------------

it('reports modified tracked paths intact, not shifted by the porcelain status column', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    // Commit files in the exact directories the contradiction checks key off.
    baseRefCommit($s['clone'], 'app/Policies/ExamplePolicy.php', "<?php // v1\n", 'seed policy');
    baseRefCommit($s['clone'], 'database/migrations/2026_01_01_000000_example.php', "<?php // v1\n", 'seed migration');
    baseRefGit($s['clone'], ['push', '--quiet', 'origin', $s['branch']]);
    baseRefGit($s['clone'], ['fetch', '--quiet', 'origin']);

    // Modify them WITHOUT committing -> porcelain emits " M <path>".
    file_put_contents($s['clone'].'/app/Policies/ExamplePolicy.php', "<?php // v2\n");
    file_put_contents($s['clone'].'/database/migrations/2026_01_01_000000_example.php', "<?php // v2\n");

    $changed = (new GitChangeInspector($s['clone']))->changedFiles();

    // A one-character shift ("pp/Policies/...", "atabase/migrations/...") would
    // silently stop matching the security/schema contradiction patterns.
    expect($changed['files'])->toContain('app/Policies/ExamplePolicy.php')
        ->and($changed['files'])->toContain('database/migrations/2026_01_01_000000_example.php')
        ->and($changed['files'])->not->toContain('pp/Policies/ExamplePolicy.php')
        ->and($changed['files'])->not->toContain('atabase/migrations/2026_01_01_000000_example.php');

    // And the contradiction checks therefore actually fire.
    foreach ($changed['files'] as $file) {
        if (str_starts_with($file, 'app/Policies/')) {
            expect(preg_match('#^app/Policies/#', $file))->toBe(1);
        }
    }
});

it('keeps paths intact for staged, untracked and renamed entries', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    baseRefCommit($s['clone'], 'app/Http/Middleware/Old.php', "<?php\n", 'seed middleware');
    baseRefGit($s['clone'], ['push', '--quiet', 'origin', $s['branch']]);
    baseRefGit($s['clone'], ['fetch', '--quiet', 'origin']);

    // staged modification
    file_put_contents($s['clone'].'/app/Http/Middleware/Old.php', "<?php // staged\n");
    baseRefGit($s['clone'], ['add', '--', 'app/Http/Middleware/Old.php']);

    // untracked
    @mkdir($s['clone'].'/app/Policies', 0700, true);
    file_put_contents($s['clone'].'/app/Policies/Fresh.php', "<?php\n");

    // rename
    baseRefGit($s['clone'], ['mv', 'README.md', 'READ_ME.md']);

    $changed = (new GitChangeInspector($s['clone']))->changedFiles();

    expect($changed['files'])->toContain('app/Http/Middleware/Old.php')
        ->and($changed['files'])->toContain('app/Policies/Fresh.php')
        // Both sides of a rename are real changes.
        ->and($changed['files'])->toContain('READ_ME.md')
        ->and($changed['files'])->toContain('README.md');

    // Nothing lost its first character.
    foreach ($changed['files'] as $file) {
        expect($file)->not->toStartWith('pp/')
            ->and($file)->not->toStartWith('EAD');
    }
});

it('keeps a path containing spaces intact', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    @mkdir($s['clone'].'/docs/with space', 0700, true);
    file_put_contents($s['clone'].'/docs/with space/note.md', "spaced\n");

    $changed = (new GitChangeInspector($s['clone']))->changedFiles();

    expect($changed['files'])->toContain('docs/with space/note.md');
});

// ---------------------------------------------------------------------------
// Output contract + posture
// ---------------------------------------------------------------------------

it('reports the base authority as machine-readable key=value metadata', function () {
    $s = baseRefScenario();
    baseRefUseBranch($s['branch']);

    $base = (new CanonicalBaseRefResolver($s['clone']))->resolve();
    $lines = implode("\n", $base->toKeyValueLines());

    expect($lines)->toContain('BASE_SOURCE=remote_tracking_ref')
        ->and($lines)->toContain('BASE_BRANCH='.$s['branch'])
        ->and($lines)->toContain('BASE_SHA='.$base->sha)
        ->and($lines)->toContain('HEAD_SHA=')
        ->and($base->toArray())->toHaveKeys(['base_source', 'base_sha', 'head_sha', 'local_base_stale']);
});

it('keeps the fail-closed base-resolution posture intact', function () {
    $posture = app(DevflowScanner::class)->baseResolutionPosture();

    expect($posture['ok'])->toBeTrue()
        ->and($posture['issues'])->toBe([])
        ->and($posture['remote'])->not->toBe('');

    // The prohibition is declared in config, not merely implied by code.
    $forbidden = (array) config('devflow.base_resolution.forbidden_fallbacks');
    expect($forbidden)->toContain('local_branch_ref', 'main', 'master');
});

it('publishes the canonical base rules and the base-authority governance check', function () {
    $rules = DevflowGovernanceService::rules();
    $ids = array_column($rules, 'id');

    expect($ids)->toContain('DEVFLOW-R011', 'DEVFLOW-R012', 'DEVFLOW-R013', 'DEVFLOW-R014', 'DEVFLOW-R015');

    $collected = app(DevflowGovernanceService::class)->collect();
    $checkIds = array_column($collected['checks'], 'check_id');

    expect($checkIds)->toContain('DEVFLOW-BASE-AUTHORITY');

    $baseCheck = collect($collected['checks'])->firstWhere('check_id', 'DEVFLOW-BASE-AUTHORITY');
    expect($baseCheck['status'])->toBe('passed');
});

// ---------------------------------------------------------------------------
// The real repository this test runs in
// ---------------------------------------------------------------------------

it('resolves this repository against the canonical remote, never the local ref', function () {
    $resolver = app(CanonicalBaseRefResolver::class);
    $base = $resolver->resolve();

    // In an offline/CI-detached environment this may legitimately fail closed —
    // what must NEVER happen is a silent local fallback presented as resolved.
    if (! $base->resolved) {
        expect($base->source)->toBe(CanonicalBaseRef::SOURCE_UNAVAILABLE)
            ->and($base->sha)->toBeNull();

        return;
    }

    expect($base->source)->toBeIn([
        CanonicalBaseRef::SOURCE_REMOTE_TRACKING_REF,
        CanonicalBaseRef::SOURCE_EXPLICIT_SHA,
    ])->and($base->sha)->toMatch('/^[0-9a-f]{40}$/');

    // If the local base ref is stale, the resolved SHA must be the remote one.
    if ($base->localStale && $base->source === CanonicalBaseRef::SOURCE_REMOTE_TRACKING_REF) {
        expect($base->sha)->not->toBe($base->localSha);
    }
});

it('exposes base-sha and base-branch options on the governance commands', function () {
    foreach (['sprint:manifest-check', 'sprint:scope-audit'] as $command) {
        $definition = Artisan::all()[$command]->getDefinition();

        expect($definition->hasOption('base-sha'))->toBeTrue("{$command} must accept --base-sha")
            ->and($definition->hasOption('base-branch'))->toBeTrue("{$command} must accept --base-branch");
    }
});
