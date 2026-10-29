<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CI-PERF-03 — every test helper a file calls resolves from that file alone
|--------------------------------------------------------------------------
| A serial Pest run loads EVERY test file before the first test starts, so a
| file that calls a helper declared in a sibling *Test.php file passes by
| accident. A parallel worker loads only the files it is handed: the same call
| is "Call to undefined function" whenever the sibling ran in another worker,
| or never ran at all. Measured on PostgreSQL 16: the two androidCheck() tests
| in DoctorDeviceAndroidDirectApkReleaseTest failed in every 2- and 4-worker
| critical run, and 14 suites fail outright when run on their own.
|
| The property: a global function declared anywhere under tests/ other than
| the always-loaded tests/Pest.php may be called only from the file that
| declares it, or from a file that requires the declaring file — directly or
| through another helper. Shared fixtures therefore live in a directory's
| helpers.php (function_exists()-guarded, required explicitly), never in a
| *Test.php file.
|
| The scan uses the PHP tokenizer, so comments, strings, methods, method calls
| and static calls are never mistaken for global calls.
*/

/** @return list<string> every PHP file under $root, as real paths */
function helperResolutionFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getRealPath();
        }
    }
    sort($files);

    return $files;
}

/**
 * The global functions one file declares and calls, and the files it requires through `__DIR__.'…'`.
 *
 * @return array{declares: list<string>, calls: list<string>, requires: list<string>}
 */
function helperResolutionScan(string $path): array
{
    if (! is_readable($path)) {
        throw new RuntimeException("helperResolutionScan() cannot read {$path}");
    }
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("helperResolutionScan() failed to read {$path}");
    }

    $tokens = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token) => ! $token->isIgnorable()));
    $declares = $calls = $requires = [];
    $depth = 0;
    $classBodies = [];
    $classPending = false;

    foreach ($tokens as $i => $token) {
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;

        if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ! $previous?->is(T_DOUBLE_COLON)) {
            $classPending = true;
        } elseif ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
            if ($classPending && $token->is('{')) {
                $classBodies[] = $depth;
                $classPending = false;
            }
        } elseif ($token->is('}')) {
            if ($classBodies !== [] && end($classBodies) === $depth) {
                array_pop($classBodies);
            }
            $depth--;
        } elseif ($token->is(T_FUNCTION) && $classBodies === []) {
            $name = $next?->is('&') || $next?->is(T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) ? ($tokens[$i + 2] ?? null) : $next;
            if ($name?->is(T_STRING)) {
                $declares[] = $name->text;
            }
        } elseif ($token->is(T_STRING) && $next?->is('(')
            && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_ATTRIBUTE, '&', T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])) {
            $calls[] = $token->text;
        } elseif ($token->is([T_REQUIRE_ONCE, T_REQUIRE, T_INCLUDE_ONCE, T_INCLUDE])) {
            $offset = ($next?->is('(')) ? 2 : 1;
            $dir = $tokens[$i + $offset] ?? null;
            $dot = $tokens[$i + $offset + 1] ?? null;
            $literal = $tokens[$i + $offset + 2] ?? null;
            if ($dir?->is(T_DIR) && $dot?->is('.') && $literal?->is(T_CONSTANT_ENCAPSED_STRING)) {
                $target = realpath(dirname($path).trim($literal->text, '\'"'));
                if ($target !== false) {
                    $requires[] = $target;
                }
            }
        }
    }

    return ['declares' => $declares, 'calls' => array_values(array_unique($calls)), 'requires' => $requires];
}

/**
 * Every call to a test-declared global function that the calling file cannot reach on its own.
 *
 * @param  list<string>  $alwaysLoaded  real paths Pest loads before any test file
 * @return list<string>
 */
function helperResolutionViolations(string $root, array $alwaysLoaded): array
{
    $scans = [];
    foreach (helperResolutionFiles($root) as $file) {
        $scans[$file] = helperResolutionScan($file);
    }

    $declaredIn = [];
    foreach ($scans as $file => $scan) {
        foreach ($scan['declares'] as $name) {
            $declaredIn[strtolower($name)][] = $file;
        }
    }

    $relative = fn (string $file): string => ltrim(substr($file, strlen($root)), '/');
    $violations = [];

    foreach ($scans as $file => $scan) {
        $reachable = array_merge([$file], $alwaysLoaded);
        for ($i = 0; $i < count($reachable); $i++) {
            foreach ($scans[$reachable[$i]]['requires'] ?? [] as $required) {
                if (! in_array($required, $reachable, true)) {
                    $reachable[] = $required;
                }
            }
        }

        $available = [];
        foreach ($reachable as $reached) {
            foreach ($scans[$reached]['declares'] ?? [] as $name) {
                $available[strtolower($name)] = true;
            }
        }

        foreach ($scan['calls'] as $call) {
            $key = strtolower($call);
            if (isset($declaredIn[$key]) && ! isset($available[$key])) {
                $violations[] = $relative($file)." calls {$call}() declared only in "
                    .implode(', ', array_map($relative, $declaredIn[$key]));
            }
        }
    }
    sort($violations);

    return $violations;
}

/** @param  array<string, string>  $files  relative path => source */
function helperResolutionFixture(array $files): string
{
    $root = tempArtifactDir('ci-helper-resolution-');
    foreach ($files as $relative => $source) {
        $path = "{$root}/{$relative}";
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, $source);
    }

    return realpath($root);
}

it('resolves every test helper a file calls from that file alone', function () {
    $violations = helperResolutionViolations(realpath(base_path('tests')), [realpath(base_path('tests/Pest.php'))]);

    expect($violations)->toBe([], "A parallel worker that never loaded the declaring file fails with 'Call to undefined function'. "
        ."Move the helper into the directory's helpers.php and require it:\n".implode("\n", $violations));
});

it('flags a helper borrowed from a sibling test file, whatever its case', function () {
    $root = helperResolutionFixture([
        'Feature/Shop/OwnerTest.php' => "<?php\nfunction fxShared(): int { return 1; }\nit('owns', fn () => expect(fxShared())->toBe(1));\n",
        'Feature/Shop/BorrowerTest.php' => "<?php\nit('borrows', fn () => expect(FXSHARED())->toBe(1));\n",
    ]);

    expect(helperResolutionViolations($root, []))
        ->toBe(['Feature/Shop/BorrowerTest.php calls FXSHARED() declared only in Feature/Shop/OwnerTest.php']);
});

it('accepts a helper reached through a required helpers file, directly or transitively', function () {
    $root = helperResolutionFixture([
        'Feature/Shop/helpers.php' => "<?php\nif (! function_exists('fxShared')) {\n    function fxShared(): int { return 1; }\n}\n",
        'Feature/Mid/helpers.php' => "<?php\nrequire_once __DIR__.'/../Shop/helpers.php';\n",
        'Feature/Shop/DirectTest.php' => "<?php\nrequire_once __DIR__.'/helpers.php';\nit('direct', fn () => expect(fxShared())->toBe(1));\n",
        'Feature/Other/TransitiveTest.php' => "<?php\nrequire_once(__DIR__.'/../Mid/helpers.php');\nit('transitive', fn () => expect(fxShared())->toBe(1));\n",
    ]);

    expect(helperResolutionViolations($root, []))->toBe([]);
});

it('ignores methods, method and static calls, strings and comments', function () {
    $root = helperResolutionFixture([
        'Feature/Shop/OwnerTest.php' => "<?php\nfunction fxShared(): int { return 1; }\n",
        'Browser/PageTest.php' => <<<'PHP'
            <?php
            class PageTest
            {
                private function fxShared(): int { return 2; }
                public function run(): array
                {
                    // fxShared() in a comment
                    return [$this->fxShared(), self::fxShared(), 'fxShared()', function_exists('fxShared'), new class { public function fxShared() {} }];
                }
            }
            PHP,
    ]);

    expect(helperResolutionViolations($root, []))->toBe([]);
});

it('treats the always-loaded Pest.php as visible from every file', function () {
    $root = helperResolutionFixture([
        'Pest.php' => "<?php\nfunction fxGlobal(): int { return 1; }\n",
        'Feature/Shop/CallerTest.php' => "<?php\nit('calls', fn () => expect(fxGlobal())->toBe(1));\n",
    ]);

    expect(helperResolutionViolations($root, []))->toHaveCount(1)
        ->and(helperResolutionViolations($root, ["{$root}/Pest.php"]))->toBe([]);
});
