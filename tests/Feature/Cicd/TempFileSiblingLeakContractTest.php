<?php

/*
 * FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — the ownership contract for temporary
 * artifacts that must OUTLIVE the function which created them.
 *
 * WHAT THIS IS A SIBLING OF. FIX-PDF-TEMPFILE-LEAK-1 closed one call site,
 * `pdfWithTempFile`, and pinned it in PdfTempFileLifecycleContractTest. That
 * sprint measured — and deliberately did not fix — the same defect shape at
 * four other call sites plus one that never cleaned up at all. Re-measuring on
 * this base authority found the real surface was larger than recorded:
 *
 *     ffcache             79   tempnam(...).'.php'   allocation orphaned
 *     lrme-poppler-       20   tempnam(...).'.pdf'   allocation orphaned
 *     leg                 12   tempnam(...).'.csv'   BOTH artifacts orphaned
 *     ctl3a-home-         51   mkdir, no cleanup at all
 *     ctl3a-bin-          36   mkdir, no cleanup at all
 *     ctl3d-bin-          11   mkdir, no cleanup at all
 *     ctl3c-              40   mkdir, no cleanup at all
 *     rdrs1-             124   mkdir, no cleanup at all
 *     ci-bare-host-       16   mkdir, no cleanup at all
 *     infra-sec-env-1-     3   cleaned on the passing path only
 *
 * TWO DIFFERENT LIFECYCLES, TWO DIFFERENT OWNERS. Where an artifact is created,
 * used and destroyed inside one call, a `finally` is the whole contract and
 * `pdfWithTempFile` remains the pattern. Where it must survive its creator — a
 * stub PATH a child process still has to read, an UploadedFile whose request
 * has not run yet — no `finally` can reach it, and the registry behind
 * `tempArtifactFile()` / `tempArtifactDir()` is the owner instead.
 *
 * WHY THE ASSERTIONS ARE SHAPED THIS WAY. They interrogate the exact paths the
 * allocators handed out and the exact siblings a derivation would have
 * stranded beside them, so they are deterministic and immune to whatever else
 * is happening in the temporary directory. Counting files in /tmp is NOT
 * authoritative on a shared machine, and it would also mistake this run's
 * correctness for the historical orphans of earlier ones — which are measured
 * and preserved, never deleted to make a metric look clean.
 *
 * WHAT IT IS NOT. Not a runtime fix. Every call site is test infrastructure; no
 * application code reaches any of it. RUNTIME_BEHAVIOR_CHANGE=false.
 */

uses()->group('Cicd', 'Ci', 'FoundationGovernance', 'TempFileOwnership');

/**
 * Carry one path from the test that registers it to the test that checks the
 * global `afterEach` drain actually removed it.
 */
function tsl1Handoff(?string $set = null): ?string
{
    static $path = null;

    if ($set !== null) {
        $path = $set;
    }

    return $path;
}

/**
 * Paths a leaking derivation could strand beside the one path handed out.
 *
 * Both directions on purpose: if a helper returns a suffixed path, the
 * `tempnam()` allocation it came from is the orphan (the historical defect);
 * if it returns the bare allocation, a suffixed sibling would be.
 *
 * @return list<string>
 */
function tsl1SiblingCandidates(string $path): array
{
    $candidates = [];

    foreach (['.php', '.pdf', '.csv', '.yml', '.json'] as $suffix) {
        $candidates[] = $path.$suffix;

        if (str_ends_with($path, $suffix)) {
            $candidates[] = substr($path, 0, -strlen($suffix));
        }
    }

    return $candidates;
}

// ---------------------------------------------------------------------------
// One allocation, one artifact.
// ---------------------------------------------------------------------------

it('allocates exactly one artifact per file and strands no sibling beside it', function () {
    $path = tempArtifactFile('tsl1-file-');

    expect(is_file($path))->toBeTrue()
        ->and($path)->toStartWith(sys_get_temp_dir().'/tsl1-file-');

    // The load-bearing assertion. Under a `tempnam(...).SUFFIX` derivation two
    // artifacts would be live here, and the caller would own both.
    foreach (tsl1SiblingCandidates($path) as $sibling) {
        expect(file_exists($sibling))->toBeFalse(
            "temporary artifact stranded beside the handed-out path: {$sibling}"
        );
    }

    expect(releaseTempArtifacts())->toBe(1)
        ->and(file_exists($path))->toBeFalse();
});

it('allocates exactly one artifact per directory', function () {
    $dir = tempArtifactDir('tsl1-dir-');

    expect(is_dir($dir))->toBeTrue()
        ->and(tempArtifactStore())->toHaveCount(1);

    expect(releaseTempArtifacts())->toBe(1)
        ->and(file_exists($dir))->toBeFalse();
});

it('keeps the owner-only mode tempnam assigns', function () {
    // Fixtures carry synthetic secrets and synthetic patient-shaped rows. A
    // derived write path would have been created under the umask — typically
    // 0664, world-readable — where the allocation itself is 0600.
    $path = tempArtifactFile('tsl1-mode-');

    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');

    releaseTempArtifacts();
});

// ---------------------------------------------------------------------------
// Cleanup on every path that exists.
// ---------------------------------------------------------------------------

it('releases everything registered when the work succeeds', function () {
    $file = tempArtifactFile('tsl1-success-');
    $dir = tempArtifactDir('tsl1-success-');
    file_put_contents($dir.'/payload.txt', 'synthetic');

    expect(releaseTempArtifacts())->toBe(2)
        ->and(file_exists($file))->toBeFalse()
        ->and(file_exists($dir))->toBeFalse()
        ->and(tempArtifactStore())->toBe([]);
});

it('releases everything registered when the work throws, and preserves the failure', function () {
    // A regular closure, not an arrow function: arrow functions capture by
    // value, which would silently break the by-reference chain and leave the
    // assertions below testing nothing.
    $created = [];

    $call = function () use (&$created) {
        $created[] = tempArtifactFile('tsl1-throw-');
        $created[] = tempArtifactDir('tsl1-throw-');

        throw new RuntimeException('fixture consumer blew up');
    };

    expect($call)->toThrow(RuntimeException::class, 'fixture consumer blew up');
    expect($created)->toHaveCount(2);

    // Nothing in the allocator's own code runs after the throw. The drain is
    // the owner precisely because it does not depend on the caller surviving.
    expect(releaseTempArtifacts())->toBe(2);

    foreach ($created as $path) {
        expect(file_exists($path))->toBeFalse();
    }
});

it('releases everything registered when a child process fails', function () {
    $bin = tempArtifactDir('tsl1-proc-', 0o777);
    file_put_contents($bin.'/failing', "#!/usr/bin/env bash\nexit 7\n");
    chmod($bin.'/failing', 0o755);

    $code = 0;
    @exec(escapeshellarg($bin.'/failing').' 2>/dev/null', $out, $code);

    // The failure is still reported — cleanup must not launder it.
    expect($code)->toBe(7);

    expect(releaseTempArtifacts())->toBe(1)
        ->and(file_exists($bin))->toBeFalse();
});

it('releases the fixture when the file written into it cannot be parsed', function () {
    $path = tempArtifactFile('tsl1-parse-');
    file_put_contents($path, 'this is not json');

    expect(json_decode((string) file_get_contents($path), true))->toBeNull();

    expect(releaseTempArtifacts())->toBe(1)
        ->and(file_exists($path))->toBeFalse();
});

it('accumulates nothing across repeated invocations', function () {
    $paths = [];

    for ($i = 0; $i < 10; $i++) {
        $paths[] = tempArtifactFile('tsl1-repeat-');
        $paths[] = tempArtifactDir('tsl1-repeat-');
        releaseTempArtifacts();
    }

    expect($paths)->toHaveCount(20)
        ->and(array_unique($paths))->toHaveCount(20)
        ->and(tempArtifactStore())->toBe([]);

    foreach ($paths as $path) {
        expect(file_exists($path))->toBeFalse();
    }

    // The delta over the whole loop, as a secondary net for anything the named
    // paths above could not anticipate.
    expect(glob(sys_get_temp_dir().'/tsl1-repeat-*') ?: [])->toBe([]);
});

// ---------------------------------------------------------------------------
// Safety properties of the remover.
// ---------------------------------------------------------------------------

it('never follows a symlink out of the fixture it is removing', function () {
    // `ci-bare-host-` deliberately links real system binaries onto a stub PATH.
    // A remover that descended through a link would delete /bin/bash. The link
    // must go; its target must not.
    $outside = tempArtifactFile('tsl1-target-');
    file_put_contents($outside, 'must survive');

    $dir = tempArtifactDir('tsl1-link-');
    symlink($outside, $dir.'/link-to-target');

    expect(is_link($dir.'/link-to-target'))->toBeTrue();

    // Remove only the directory; the registry still holds the target.
    tempArtifactRemove($dir);

    expect(file_exists($dir))->toBeFalse()
        ->and(is_file($outside))->toBeTrue()
        ->and(file_get_contents($outside))->toBe('must survive');

    releaseTempArtifacts();

    expect(file_exists($outside))->toBeFalse();
});

it('refuses to remove anything outside the temporary directory', function () {
    // Confinement is a property of the code, not of every future caller's
    // discipline. base_path() is a real, populated directory that must survive.
    expect(tempArtifactRemove(base_path('composer.json')))->toBeFalse()
        ->and(is_file(base_path('composer.json')))->toBeTrue();

    expect(tempArtifactRemove(rtrim(sys_get_temp_dir(), '/').'/'))->toBeFalse()
        ->and(is_dir(sys_get_temp_dir()))->toBeTrue();
});

it('leaves artifacts it does not own alone', function () {
    // A concurrent test process's file, and the historical orphans of earlier
    // runs, share the prefixes but not the registry. The drain must not be a
    // prefix sweep.
    $foreign = tempnam(sys_get_temp_dir(), 'tsl1-foreign-');
    $owned = tempArtifactFile('tsl1-foreign-');

    try {
        expect(releaseTempArtifacts())->toBe(1)
            ->and(file_exists($owned))->toBeFalse()
            ->and(is_file($foreign))->toBeTrue();
    } finally {
        @unlink($foreign);
    }
});

it('keeps allocating temporary paths atomically and unpredictably', function () {
    // Guards the shape of the fix as much as its effect. A fixed filename, or a
    // predictable `uniqid()` string, would satisfy the delta assertions above
    // while reintroducing collision and pre-plant risk between concurrent test
    // processes.
    $files = [];
    $dirs = [];

    for ($i = 0; $i < 5; $i++) {
        $files[] = tempArtifactFile('tsl1-atomic-');
        $dirs[] = tempArtifactDir('tsl1-atomic-');
    }

    expect(array_unique($files))->toHaveCount(5)
        ->and(array_unique($dirs))->toHaveCount(5);

    foreach (array_merge($files, $dirs) as $path) {
        $random = substr(basename($path), strlen('tsl1-atomic-'));

        expect(strlen($random))->toBeGreaterThanOrEqual(6);
    }

    releaseTempArtifacts();
});

// ---------------------------------------------------------------------------
// The drain is wired globally, so a call site cannot forget it.
// ---------------------------------------------------------------------------

it('registers an artifact and deliberately does not clean it up', function () {
    // Paired with the test below. Nothing here releases anything: the whole
    // point is to prove the global afterEach is what removes it.
    $path = tempArtifactFile('tsl1-handoff-');
    tsl1Handoff($path);

    expect(is_file($path))->toBeTrue();
});

it('finds the previous test\'s artifact already gone', function () {
    $path = tsl1Handoff();

    expect($path)->toBeString()
        ->and(file_exists($path))->toBeFalse(
            'the global afterEach drain is not wired: a registered artifact survived its test'
        );
});

// ---------------------------------------------------------------------------
// The defect shape itself.
// ---------------------------------------------------------------------------

it('has no call site left that derives a path from a tempnam allocation', function () {
    // `tempnam()` is not the defect and is not banned — it is the correct
    // atomic primitive. What is banned is deriving a SECOND path from it on the
    // same expression, because that silently makes the caller the owner of two
    // artifacts while every reviewer reads one. Both prior sprints in this
    // family were caused by exactly this shape.
    //
    // There is no allowlist: after this sprint the shape appears nowhere. A
    // future caller that genuinely needs an extension should move the
    // allocation onto the final name rather than write beside it.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Tokenised, not grepped. A textual scan matches this file's own
        // census table and failure message, so the guard would report itself —
        // and a guard that cannot describe the defect it guards is worse than
        // none. Comments and string literals are not code and are skipped.
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'tempnam') {
                continue;
            }

            $depth = 0;
            $cursor = $index + 1;

            for ($end = count($tokens); $cursor < $end; $cursor++) {
                $current = $tokens[$cursor];

                if ($current === '(') {
                    $depth++;
                } elseif ($current === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $cursor++;
                        break;
                    }
                }
            }

            // The first significant token after the call closes.
            while (isset($tokens[$cursor]) && is_array($tokens[$cursor])
                && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $cursor++;
            }

            if (($tokens[$cursor] ?? null) === '.') {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$token[2];
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['a path derived from a tempnam() allocation owns TWO artifacts:'],
        $offenders,
    )));
});
