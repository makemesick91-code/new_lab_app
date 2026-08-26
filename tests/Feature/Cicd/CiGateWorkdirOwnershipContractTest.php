<?php

use Illuminate\Support\Facades\Process;

/*
 * FIX-CI-GATE-WORKDIR-TEMPFILE-LEAK-1 — R-22: allocation-API ownership is the
 * authority, a list of known prefixes is not.
 *
 * WHAT R-22 WAS. FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 built the registry behind
 * `tempArtifactFile()` / `tempArtifactDir()` and closed ten prefixes with it.
 * Two call sites in NsfReleaseGateExitPropagationTest never went through that
 * API at all — they built a path from the process temporary directory and
 * called `mkdir()` on it — so no assertion about the API's behaviour could see
 * them. 42 tests passed and stranded 18 directory trees, run after run.
 *
 * WHY A PREFIX CENSUS COULD NOT HAVE CAUGHT IT. The readiness audit that found
 * R-22 also demonstrated the deeper defect: counting a KNOWN list of prefixes
 * before and after a run reported 447 / 447 / delta 0 while an unknown prefix
 * leaked +18 somewhere else entirely. A census can only ever report on names it
 * was told about in advance, so it is structurally incapable of reporting the
 * one thing that matters — an allocation nobody registered. That is not a
 * tuning problem. It is the wrong authority.
 *
 * SO THE AUTHORITY IS THE ALLOCATION API. Every temporary directory in the
 * governed surface must come from the canonical allocator, which registers it
 * with an owner that drains on the passing AND the failing path. A brand-new
 * prefix gets that protection for free, with nothing to register anywhere: the
 * guard below keys on the SHAPE of the allocation, never on its spelling.
 * Prefix counts remain useful as diagnostics and historical accounting, and
 * they are used that way here — never as the verdict.
 *
 * WHAT THIS IS NOT. Not a runtime fix, and not a repository-wide ban on
 * `mkdir()`. Every call site is test infrastructure and no application code
 * reaches any of it. RUNTIME_BEHAVIOR_CHANGE=false.
 */

uses()->group('Cicd', 'Ci', 'FoundationGovernance', 'TempFileOwnership');

/**
 * The governed surface: the CI gate test files, which is where the defect
 * class lives and where the two leaks were.
 *
 * @return list<string>
 */
function cgwl1GovernedFiles(): array
{
    $files = glob(base_path('tests/Feature/Cicd').'/*.php') ?: [];

    sort($files);

    return array_values($files);
}

/**
 * Call sites whose temporary DIRECTORY is not registry-owned but which carry a
 * documented lifecycle of their own, reviewed and re-measured on this base.
 *
 * Membership is a governance decision, kept honest by a test below: an entry
 * that no longer allocates raw must be removed rather than left to rot.
 *
 * @return array<string, string>
 */
function cgwl1DocumentedOwners(): array
{
    return [
        // Its own `$GLOBALS['ci_base_fixtures']` registry, drained in an
        // afterEach that runs on the failing path too. Measured on this base
        // authority: zero standing orphans, so the owner demonstrably reaches
        // every path it hands out.
        'CiClassifierBaseAuthorityTest.php' => "\$GLOBALS['ci_base_fixtures'] drained in afterEach()",
    ];
}

/**
 * Fail CLOSED when the pattern engine faults.
 *
 * `preg_*` returns false and an empty match set on a backtrack/recursion limit,
 * which is indistinguishable from "this file is clean". A detector that treats
 * an engine fault as an all-clear is the same fail-open shape the monitoring
 * correctives kept finding: a control reporting OK without evidence.
 */
function cgwl1AssertPatternEngineHealthy(string $stage): void
{
    if (preg_last_error() !== PREG_NO_ERROR) {
        throw new RuntimeException(
            "temporary-allocation detector faulted during {$stage}: ".preg_last_error_msg()
                .' — treat as UNKNOWN, never as clean'
        );
    }
}

/**
 * Raw temporary-DIRECTORY allocations in one PHP source: allocations rooted in
 * the process temporary directory that did NOT come from a canonical allocator.
 *
 * Two shapes, because the defect appeared as the first and could reappear as
 * the second:
 *
 *   $var = <temp root>.'/whatever'; mkdir($var, ...);   // via a variable
 *   mkdir(<temp root>.'/whatever', ...);                // inline
 *
 * Deliberately scoped to DIRECTORIES. `tempnam()` is not this defect, is the
 * correct primitive for a file, and is already governed by the sibling-leak
 * contract — banning it here would reopen call sites that measurement shows are
 * closed.
 *
 * The needles are assembled from fragments so that this file can police itself:
 * a guard whose own synthetic fixtures trip it would have to be excluded from
 * the surface it guards, and an excluded guard guards nothing.
 *
 * @return list<string> a short description per offending allocation
 */
function cgwl1RawTempDirAllocations(string $source): array
{
    $tempRoot = 'sys_get_temp'.'_dir';
    $mk = 'mk'.'dir';

    $found = [];

    // Shape 1 — a variable rooted in the temporary directory that is later
    // handed to mkdir(). A canonical allocator on the right-hand side is the
    // whole point of the API and is never an offence.
    //
    // A failed match returns no matches, which reads exactly like a clean file.
    // That is a fail-OPEN detector, and this programme has repeatedly been bitten
    // by controls that report OK without evidence — so an engine fault is raised
    // rather than absorbed.
    preg_match_all('/\$(\w+)\s*=\s*([^;]*'.$tempRoot.'\s*\([^;]*)/', $source, $matches, PREG_SET_ORDER);
    cgwl1AssertPatternEngineHealthy('temp-rooted assignment scan');

    foreach ($matches as [, $var, $rhs]) {
        // Canonical allocator calls are removed BEFORE asking whether the
        // expression still reaches for the temporary root. Merely CONTAINING a
        // canonical call is not innocence: mutation testing of this guard found
        // that a ternary handing back `tempArtifactDir()` on the passing path
        // and a raw path on the failing one — the exact "cleaned up only on
        // success" defect — earned a free pass under a `str_contains` skip.
        $residue = preg_replace('/tempArtifact(?:Dir|File)\s*\([^)]*\)/', '', $rhs) ?? $rhs;

        if (preg_match('/'.$tempRoot.'\s*\(/', $residue) !== 1) {
            continue;
        }

        if (preg_match('/@?'.$mk.'\s*\(\s*\$'.preg_quote($var, '/').'\b/', $source) === 1) {
            $found[] = '$'.$var;
        }
    }

    // Shape 2 — the same allocation with no variable in between.
    preg_match_all('/@?'.$mk.'\s*\(\s*[^;]*'.$tempRoot.'\s*\(/', $source, $inline);
    cgwl1AssertPatternEngineHealthy('inline allocation scan');

    foreach (($inline[0] ?? []) as $occurrence) {
        $found[] = trim($occurrence);
    }

    return array_values(array_unique($found));
}

// ---------------------------------------------------------------------------
// The guard is prefix-independent, and provably not vacuous.
// ---------------------------------------------------------------------------

it('detects a raw temporary workdir allocation under a prefix it has never seen', function () {
    // The load-bearing control. If this detector only recognised `ctl3b-` and
    // `fix6-fullsuite-`, the guard below would be a census wearing a different
    // hat and the next unknown prefix would leak exactly as R-22 did.
    $unseen = 'never-registered-anywhere-'.bin2hex(random_bytes(4)).'-';

    $tempRoot = 'sys_get_temp'.'_dir';
    $mk = 'mk'.'dir';

    $viaVariable = '<?php $d = '.$tempRoot.'()."/'.$unseen.'".bin2hex(random_bytes(6)); '.$mk.'($d, 0700, true);';
    $inline = '<?php '.$mk.'('.$tempRoot.'()."/'.$unseen.'".uniqid(), 0700, true);';
    $canonical = '<?php $d = tempArtifactDir("'.$unseen.'"); '.$mk.'($d."/storage/ci-evidence", 0700, true);';

    expect(cgwl1RawTempDirAllocations($viaVariable))->not->toBeEmpty(
        'a raw allocation under an unseen prefix went undetected — the guard is a census again'
    )
        ->and(cgwl1RawTempDirAllocations($inline))->not->toBeEmpty(
            'an inline raw allocation under an unseen prefix went undetected'
        )
        // The other half of the contract: a brand-new prefix is perfectly
        // welcome THROUGH the canonical allocator, with nothing to register in
        // any central list to earn cleanup.
        ->and(cgwl1RawTempDirAllocations($canonical))->toBe(
            [],
            'the canonical allocator was flagged; new prefixes would need census registration to be allowed'
        );
});

it('detects a raw allocation hidden beside a canonical one in the same expression', function () {
    // Found by mutating this sprint's own fix: owning the workdir only on the
    // passing path leaves the failing path raw, and a detector that skipped any
    // expression MENTIONING the canonical allocator would have called that
    // clean. The failing path is exactly the path a leak guard exists for.
    $tempRoot = 'sys_get_temp'.'_dir';
    $mk = 'mk'.'dir';

    $mixed = '<?php $w = $exit === 0 ? tempArtifactDir("p-") : '.$tempRoot.'()."/p-".uniqid(); '.$mk.'($w, 0700, true);';

    expect(cgwl1RawTempDirAllocations($mixed))->not->toBeEmpty(
        'a raw allocation escaped by sharing an expression with a canonical one'
    );
});

it('fails closed when the pattern engine faults', function () {
    // HONEST SCOPE. This proves the MECHANISM, not a live path. The detector's
    // own patterns are linear: measured, driving `pcre.backtrack_limit` down to
    // 20 does not fault them, which is a stronger property than failing closed.
    // The check is defence in depth for a future pattern edit that is not.
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '10');

    try {
        // Catastrophic by construction, and deliberately NOT one of the
        // detector's patterns.
        @preg_match('/(a+)+$/', str_repeat('a', 200).'b');

        expect(preg_last_error())->not->toBe(
            PREG_NO_ERROR,
            'could not induce an engine fault; this control proves nothing as written'
        )
            ->and(fn () => cgwl1AssertPatternEngineHealthy('induced fault'))
            ->toThrow(RuntimeException::class);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    // A healthy engine stays silent, and the detector still works afterwards.
    @preg_match('/x/', 'x');
    cgwl1AssertPatternEngineHealthy('healthy engine');

    expect(cgwl1RawTempDirAllocations('<?php $x = 1;'))->toBe([]);
});

it('lets no governed CI gate test allocate a temporary workdir outside the canonical owner', function () {
    $files = cgwl1GovernedFiles();

    expect($files)->not->toBeEmpty('the governed surface resolved to nothing; this guard would pass vacuously');

    // The file this sprint repaired must actually be inside the surface, or the
    // guard proves nothing about the leak it exists to prevent.
    expect(array_map('basename', $files))
        ->toContain('NsfReleaseGateExitPropagationTest.php')
        ->toContain(basename(__FILE__));

    $offenders = [];

    foreach ($files as $file) {
        if (array_key_exists(basename($file), cgwl1DocumentedOwners())) {
            continue;
        }

        $raw = cgwl1RawTempDirAllocations((string) file_get_contents($file));

        if ($raw !== []) {
            $offenders[basename($file)] = $raw;
        }
    }

    expect($offenders)->toBe([], 'temporary directories allocated outside the canonical owner: '.json_encode($offenders));
});

it('keeps the documented-owner allowlist honest', function () {
    foreach (cgwl1DocumentedOwners() as $name => $why) {
        $path = base_path('tests/Feature/Cicd/'.$name);

        expect(file_exists($path))->toBeTrue("allowlisted file no longer exists: {$name}")
            ->and($why)->not->toBe('')
            // A stale exemption is how a guard rots into decoration.
            ->and(cgwl1RawTempDirAllocations((string) file_get_contents($path)))->not->toBeEmpty(
                "{$name} no longer allocates raw; remove it from the allowlist rather than leaving a standing exemption"
            );
    }
});

// ---------------------------------------------------------------------------
// Why the census is diagnostic and the owner is authoritative.
// ---------------------------------------------------------------------------

it('shows a known-prefix census reporting delta zero while an unknown prefix leaks', function () {
    // Everything happens inside a temporary root this test OWNS, handed to the
    // child as its temporary directory. The measurement is therefore exact: no
    // other process on the machine can perturb it, and nothing outside the root
    // is read, written or counted.
    $root = tempArtifactDir('cgwl1-probe-');

    $census = fn (): int => count(glob($root.'/ctl3b-*') ?: [])
        + count(glob($root.'/fix6-fullsuite-*') ?: []);
    $everything = fn (): int => count(array_diff(scandir($root) ?: [], ['.', '..']));

    $censusBefore = $census();
    $ownedBefore = $everything();

    $unseen = 'unattributed-'.bin2hex(random_bytes(4)).'-';

    // `sys_get_temp_dir()` is resolved once per process, so a child is the only
    // way to point an allocation at a private root. Assembled from fragments
    // for the same reason as the detector above.
    $childCode = '$d = '.'sys_get_temp'.'_dir'.'()."/".getenv("CGWL1_PREFIX").bin2hex(random_bytes(6)); '
        .'mk'.'dir($d, 0700, true); echo $d;';

    $result = Process::env(['TMPDIR' => $root, 'CGWL1_PREFIX' => $unseen])
        ->run([PHP_BINARY, '-r', $childCode]);

    expect($result->successful())->toBeTrue('the probe child failed: '.$result->errorOutput());

    // The whole point, in two numbers.
    expect($census() - $censusBefore)->toBe(
        0,
        'the census saw the unknown prefix; this control no longer demonstrates anything'
    )
        ->and($everything() - $ownedBefore)->toBe(
            1,
            'the ownership audit missed an unattributed allocation'
        );

    // And the owner reaches all of it, including what the child left behind.
    releaseTempArtifacts();

    expect(file_exists($root))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Lifecycle: the drain does not depend on the happy path.
// ---------------------------------------------------------------------------

it('drains an owned workdir even when the body throws after allocating it', function () {
    $root = null;

    try {
        $root = tempArtifactDir('cgwl1-throw-');
        mkdir($root.'/storage/ci-evidence', 0o700, true);

        throw new RuntimeException('deliberate: the drain must not need a return');
    } catch (RuntimeException) {
        // Swallowed on purpose. Registration happens at ALLOCATION, so there is
        // no window in which an owned path exists unowned.
    }

    expect(is_dir($root))->toBeTrue()
        ->and(is_dir($root.'/storage/ci-evidence'))->toBeTrue();

    releaseTempArtifacts();

    expect(file_exists($root))->toBeFalse('an artifact allocated before a throw was stranded');
});

it('never follows a symlink to a directory out of the owned root when draining', function () {
    // The destructive target is a disposable directory this test allocated and
    // still owns — never a repository file, never anything on the host. A
    // confinement mutation in the previous sprint deleted composer.json by
    // pointing at the working tree; that mistake is not repeatable here.
    $outside = tempArtifactDir('cgwl1-outside-');
    file_put_contents($outside.'/must-survive.txt', 'canary');

    $owned = tempArtifactDir('cgwl1-linkroot-');
    symlink($outside, $owned.'/escape');

    expect(is_link($owned.'/escape'))->toBeTrue()
        // It really does resolve to a directory — a link to a FILE would not
        // exercise the dangerous shape.
        ->and(is_dir($owned.'/escape'))->toBeTrue();

    tempArtifactRemove($owned);

    expect(file_exists($owned))->toBeFalse()
        ->and(is_dir($outside))->toBeTrue('the drain followed a symlink out of the owned root')
        ->and(file_get_contents($outside.'/must-survive.txt'))->toBe('canary');
});

// ---------------------------------------------------------------------------
// A control that exists but is never selected is not a control.
// ---------------------------------------------------------------------------

it('is itself declared mandatory so every critical gate variant must select it', function () {
    expect((array) config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/Cicd/CiGateWorkdirOwnershipContractTest.php');
});
