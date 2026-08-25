<?php

/*
 * FIX-PDF-TEMPFILE-LEAK-1 — the temporary-file ownership contract for the
 * shared PDF inspection helper.
 *
 * THE DEFECT THIS PINS. `tempnam()` does not merely reserve a name — it
 * CREATES the file. Code that derives a second path from the returned one
 * therefore owns TWO filesystem artifacts:
 *
 *     $path = tempnam(sys_get_temp_dir(), 'dms-pdf-').'.pdf';
 *              ^ creates /tmp/dms-pdf-ABC123      ^ writes /tmp/dms-pdf-ABC123.pdf
 *
 * The helper's `finally` removed only the derived `.pdf`, so every single PDF
 * assertion in the suite left one zero-byte orphan behind. Measured on the
 * clean base authority 9576af9f: 55 accumulated orphans, all zero-byte, none
 * carrying the `.pdf` suffix — the derived file was always cleaned, the
 * allocation never was.
 *
 * WHY THE ASSERTIONS ARE SHAPED THIS WAY. A naive guard counts files in the
 * temporary directory before and after. That is not authoritative: any other
 * process on the machine may create or remove files in the same window, so the
 * count can move for reasons that have nothing to do with this helper. The
 * primary assertions here are therefore DETERMINISTIC and concurrency-immune —
 * they interrogate the exact path the helper handed to its callback, and the
 * exact sibling path the old derivation would have stranded next to it. The
 * owned-set delta is asserted too, but as a secondary net for artifacts the
 * named-path checks could not anticipate.
 *
 * WHAT IT IS NOT. Not a runtime fix. `pdfWithTempFile` is test infrastructure;
 * no application code calls it. RUNTIME_BEHAVIOR_CHANGE=false.
 */

/**
 * Paths a leaking derivation could have stranded beside the one path the
 * helper actually handed out.
 *
 * Both directions are covered on purpose. If the helper hands back a path
 * ending in a suffix, the `tempnam()` allocation it was derived FROM is the
 * orphan (the historical defect). If it hands back the bare allocation, a
 * suffixed sibling would be the orphan (the same mistake, mirrored).
 *
 * @return list<string>
 */
function pdfTempSiblingCandidates(string $path): array
{
    $candidates = [$path.'.pdf'];

    if (str_ends_with($path, '.pdf')) {
        $candidates[] = substr($path, 0, -4);
    }

    return $candidates;
}

/**
 * Filenames the helper's prefix could match, as a set, right now.
 *
 * @return array<string, true>
 */
function pdfTempOwnedSet(): array
{
    $set = [];

    foreach ((glob(sys_get_temp_dir().'/dms-pdf-*') ?: []) as $file) {
        $set[$file] = true;
    }

    return $set;
}

/**
 * Every artifact matching the helper's prefix that appeared while $run ran.
 *
 * @return list<string>
 */
function pdfTempArtifactsCreatedBy(callable $run): array
{
    $before = pdfTempOwnedSet();

    $run();

    return array_values(array_diff(array_keys(pdfTempOwnedSet()), array_keys($before)));
}

// ---------------------------------------------------------------------------
// The ownership contract.
// ---------------------------------------------------------------------------

it('hands the callback the one and only artifact it created', function () {
    $seen = null;
    $liveDuringCall = [];

    $before = pdfTempOwnedSet();

    $result = pdfWithTempFile('%PDF-1.4 synthetic', function (string $path) use (&$seen, &$liveDuringCall, $before): string {
        $seen = $path;
        $liveDuringCall = array_values(array_diff(array_keys(pdfTempOwnedSet()), array_keys($before)));

        return 'callback-result';
    });

    expect($result)->toBe('callback-result')
        ->and($seen)->toBeString();

    // The load-bearing assertion. Under the old derivation TWO artifacts were
    // live here — the `tempnam()` allocation and the `.pdf` written beside it.
    expect($liveDuringCall)->toBe([$seen]);
});

it('leaves nothing behind when the callback succeeds', function () {
    $seen = null;

    $created = pdfTempArtifactsCreatedBy(function () use (&$seen) {
        pdfWithTempFile('%PDF-1.4 synthetic', function (string $path) use (&$seen): string {
            $seen = $path;

            expect(file_exists($path))->toBeTrue();

            return 'ok';
        });
    });

    expect(file_exists($seen))->toBeFalse();

    foreach (pdfTempSiblingCandidates($seen) as $sibling) {
        expect(file_exists($sibling))->toBeFalse(
            "temporary artifact stranded beside the handed-out path: {$sibling}"
        );
    }

    expect($created)->toBe([]);
});

it('leaves nothing behind when the callback throws, and preserves the failure', function () {
    $seen = null;

    $created = pdfTempArtifactsCreatedBy(function () use (&$seen) {
        // A regular closure, not an arrow function: arrow functions capture by
        // value, which would silently break the by-reference `$seen` chain and
        // leave the sibling assertions below testing nothing.
        $call = function () use (&$seen) {
            pdfWithTempFile('%PDF-1.4 synthetic', function (string $path) use (&$seen) {
                $seen = $path;

                throw new RuntimeException('inspection blew up');
            });
        };

        expect($call)->toThrow(RuntimeException::class, 'inspection blew up');
    });

    expect(file_exists($seen))->toBeFalse();

    foreach (pdfTempSiblingCandidates($seen) as $sibling) {
        expect(file_exists($sibling))->toBeFalse(
            "temporary artifact stranded beside the handed-out path: {$sibling}"
        );
    }

    expect($created)->toBe([]);
});

it('leaves nothing behind when the pdf itself cannot be read', function () {
    // The deterministic failure this helper can actually suffer: the bytes are
    // not a readable document, so Poppler fails and the page count comes back
    // as 0. `pdfPageCount` returning 0 FAILS a `toBe(1)` assertion rather than
    // passing vacuously — that contract is asserted here alongside the cleanup.
    $created = pdfTempArtifactsCreatedBy(function () {
        expect(pdfPageCount('%PDF-1.4'."\n".'this is not a document'))->toBe(0);
    });

    expect($created)->toBe([]);
})->skip(fn () => ! pdfInfoAvailable(), 'Poppler (pdfinfo) is not installed in this environment.');

it('accumulates nothing across repeated invocations', function () {
    $created = pdfTempArtifactsCreatedBy(function () {
        for ($i = 0; $i < 10; $i++) {
            pdfWithTempFile('%PDF-1.4 synthetic '.$i, fn (string $path): bool => file_exists($path));
        }
    });

    expect($created)->toBe([]);
});

it('keeps allocating temporary paths atomically and unpredictably', function () {
    // Guards the shape of the fix as much as its effect. Replacing `tempnam()`
    // with a fixed filename, or with a predictable `uniqid()` string, would
    // make the delta assertions above pass while introducing collision and
    // race risk between concurrent test processes.
    $paths = [];

    for ($i = 0; $i < 5; $i++) {
        pdfWithTempFile('%PDF-1.4 synthetic', function (string $path) use (&$paths): bool {
            $paths[] = $path;

            return true;
        });
    }

    expect(array_unique($paths))->toHaveCount(5)
        ->and($paths[0])->toStartWith(sys_get_temp_dir().'/dms-pdf-');
});
