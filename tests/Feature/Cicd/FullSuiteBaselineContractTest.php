<?php

/*
 * CICD-BASELINE-REVERIFY-1 — the Full Suite baseline contract.
 *
 * The repository carried a documented residual of "9 pre-existing Full Suite
 * failures" from CICD-CTRL-3. Those nine were real, were catalogued, and were
 * closed by CICD-FIX-6 (`fe36f06`): run 31293873172 reported `9 failed` and the
 * very next full suite, run 31335720157, reported none. The current expected
 * Full Suite failure baseline is therefore ZERO.
 *
 * A retired baseline is only safe if two properties hold, and both are pinned
 * here rather than left to prose:
 *
 *   1. No expected-failure allowance survives anywhere in the CI or governance
 *      surface. The nine were only ever an evidence note — they were never
 *      encoded as a machine-readable allowlist — and that must stay true, or a
 *      future red suite could be subtracted down to green.
 *
 *   2. The suite is deterministic. A baseline of zero is worthless if the suite
 *      reddens at random, because the first false red teaches everyone to
 *      ignore it. The one proven source of non-determinism found during this
 *      revalidation was an assertion comparing a faker-generated name against a
 *      Blade-escaped response body, so the escaping contract is pinned too.
 *
 * The exit-status half of the contract — that a Pest failure actually reddens
 * the gate instead of being swallowed by `| tee` — is already pinned by
 * NsfReleaseGateExitPropagationTest and is deliberately not duplicated here.
 */

use Illuminate\Support\Facades\File;

/**
 * Every PHP test file in the suite, as path => contents.
 *
 * This file is excluded from its own scan. It has to quote the offending
 * patterns verbatim in order to explain and match them, which would otherwise
 * make the guard report itself — the same self-scan trap that forced the
 * deployment scanners to keep their literals in config rather than in source.
 *
 * @return array<string, string>
 */
function baselineTestSources(): array
{
    static $sources = null;

    if ($sources !== null) {
        return $sources;
    }

    $sources = [];
    $self = str_replace('\\', '/', __FILE__);

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_replace('\\', '/', $file->getPathname()) === $self) {
            continue;
        }

        $sources[$file->getRelativePathname()] = (string) file_get_contents($file->getPathname());
    }

    return $sources;
}

it('encodes no expected-failure allowance in the CI or governance surface', function () {
    /*
     * Tokens that would mean "this many failures are acceptable". Searching for
     * the mechanism rather than the number keeps the guard useful after the
     * count changes: a future baseline of two would be just as unsafe to encode
     * as the historical nine.
     */
    $forbidden = [
        'expected_failures',
        'expected-failures',
        'failure_baseline',
        'failure-baseline',
        'baseline_failures',
        'allowed_failures',
        'allowed-failures',
        'known_failures',
        'accepted_failures',
    ];

    $surface = [
        '.github/workflows/foundation-evidence-gates.yml',
        'scripts/ci/resolve-gates.sh',
        'config/ci_runner.php',
        'config/ci_runtime_control.php',
    ];

    foreach ($surface as $relative) {
        $path = base_path($relative);

        if (! File::exists($path)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        foreach ($forbidden as $token) {
            expect(str_contains($contents, $token))->toBeFalse(
                "{$relative} declares '{$token}' — the Full Suite baseline is zero and must not carry a failure allowance."
            );
        }
    }
});

it('leaves no raw response-body assertion against a dynamic value', function () {
    /*
     * `expect($response->content())->toContain($var)` compares against the raw
     * rendered HTML, so any value Blade escapes will not match. Laravel's
     * `assertSee()` escapes the expected value by default and is the correct
     * tool. This is the exact shape that reddened run 31928614428.
     */
    $offenders = [];

    foreach (baselineTestSources() as $relative => $contents) {
        if (preg_match('/(?:getContent|content)\(\)\)?->toContain\(\s*\$/', $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'These tests assert a dynamic value against the raw response body; use assertSee(), which escapes like Blade does.'
    );
});

it('escapes the dynamic half of every unescaped assertSee', function () {
    /*
     * `assertSee($value, false)` switches escaping off. That is legitimate when
     * the assertion targets raw markup — `value="…"`, a URL, an id — but the
     * dynamic value interpolated into it still has to be escaped by hand with
     * `e()`, because the view escaped it on the way out.
     *
     * Only property reads are flagged. Formatting helpers and casts produce
     * digits and are not escapable.
     */
    $offenders = [];

    foreach (baselineTestSources() as $relative => $contents) {
        /*
         * Deliberately line-bounded. A `/s` match would run from one
         * `assertSee(` to a `, false)` several statements later and read a
         * property out of an unrelated call — reporting a file that is fine,
         * or swallowing one that is not. These calls are single-line.
         */
        preg_match_all('/assertSee\(([^\n]*?),\s*false\s*\)/', $contents, $matches);

        foreach ($matches[1] as $argument) {
            // A property read such as ->name / ->description that is not wrapped in e().
            $readsProperty = preg_match('/\$\w+(?:->\w+)*->(?:name|description|title|address|notes)\b/', $argument) === 1;

            /*
             * `e(` must be matched as a call, not as a substring: plain
             * `str_contains($argument, 'e(')` is satisfied by the trailing
             * `e(` of `assertSee(` itself, so every offender would look
             * escaped. The lookbehind requires a non-word character before it.
             */
            $isEscaped = preg_match('/(?<!\w)e\(/', $argument) === 1;

            if ($readsProperty && ! $isEscaped) {
                $offenders[] = $relative.': '.trim($argument);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Escaping is off for these assertions but the interpolated value is not passed through e().'
    );
});

it('matches an escaped name the way the view renders it', function () {
    /*
     * The behavioural half of the contract. A name carrying an HTML-special
     * character must be found by the escaping-aware assertion and must NOT be
     * present verbatim in the body — which is precisely why the raw comparison
     * was unreliable.
     */
    $name = "Oswaldo O'Kon & Sons";

    $rendered = (string) app('blade.compiler')->render('<span>{{ $value }}</span>', ['value' => $name]);

    expect($rendered)->not->toContain($name)
        ->and($rendered)->toContain(e($name))
        ->and(e($name))->toContain('&#039;')
        ->and(e($name))->toContain('&amp;');
});

/*
|--------------------------------------------------------------------------
| The third shape: contiguity across PDF line-wrapping
|--------------------------------------------------------------------------
|
| FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1.
|
| The two guards above pin comparisons against a rendered HTML BODY. Neither
| matches a comparison against PDF-EXTRACTED TEXT, which is why run 32700184849
| — the one authorised consolidated Full Suite — still reddened, on
| `expect($text)->toContain($visit->patient->name)` in RmeReceiptOnePageTest.
|
| `pdftotext -layout` serialises the visual layout. A value that overflows its
| cell wraps, and the columns beside it are interleaved into the same lines, so
| a semantically continuous value is NOT a contiguous substring:
|
|   Nama Pasien     Miss Marcella O'Conner     No. Rekam Medis     MRN-U8XPPPBS
|                   DVM
|
| The receipt was correct — nothing was clipped, nothing was mis-escaped. The
| assertion was asserting a layout property while claiming to assert a content
| property, and it failed on roughly 6% of faker names.
|
| So the forbidden shape is narrow and specific: a free-text value compared for
| CONTAINMENT against PDF-extracted text. Literals, identifiers, formatted money
| and values routed through the wrap-tolerant helper are all still fine.
*/

/**
 * The same file with its comments blanked out, line numbering preserved.
 *
 * A comment is prose, not an assertion. Without this, any file that DOCUMENTS
 * the forbidden shape in order to warn about it reports itself — and the
 * cheapest way to silence the guard becomes deleting the explanation. The
 * tokenizer is used rather than a regex so that a `//` inside a string literal
 * is not mistaken for the start of a comment.
 */
function baselineStripComments(string $contents): string
{
    $stripped = '';

    foreach (token_get_all($contents) as $token) {
        if (! is_array($token)) {
            $stripped .= $token;

            continue;
        }

        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            // Keep the newlines so the line-bounded scan stays aligned.
            $stripped .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $stripped .= $token[1];
    }

    return $stripped;
}

/**
 * Every argument passed to a `toContain(...)` call, with nesting respected.
 *
 * A plain regex would either stop at the first inner `)` or run greedily to the
 * last one on the line. The second is the dangerous failure: a line holding one
 * safe and one unsafe assertion would look safe because the safe half mentions
 * the approved helper. Counting parentheses keeps the arguments separate.
 *
 * @return array<int, string>
 */
function baselineToContainArguments(string $contents): array
{
    $arguments = [];
    $call = 'toContain(';

    // Deliberately line-bounded, for the same reason the assertSee guard is.
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $offset = 0;

        while (($found = strpos($line, $call, $offset)) !== false) {
            $start = $found + strlen($call);
            $depth = 1;
            $index = $start;
            $length = strlen($line);

            while ($index < $length && $depth > 0) {
                if ($line[$index] === '(') {
                    $depth++;
                } elseif ($line[$index] === ')') {
                    $depth--;
                }

                $index++;
            }

            $arguments[] = substr($line, $start, max(0, $index - $start - 1));
            $offset = $index;
        }
    }

    return $arguments;
}

/**
 * Files asserting a wrap-capable value against PDF text without going through
 * the wrap-tolerant helper.
 *
 * Scoped to files that actually extract PDF text, and to the same free-text
 * property list the assertSee guard uses — those are the fields a user can make
 * arbitrarily long. Identifiers and formatted money are fixed-width by
 * construction and are deliberately not flagged.
 *
 * @param  array<string, string>  $sources
 * @return array<int, string>
 */
function baselinePdfContiguityOffenders(array $sources): array
{
    $offenders = [];

    foreach ($sources as $relative => $contents) {
        if (! str_contains($contents, 'pdfExtractText')) {
            continue;
        }

        foreach (baselineToContainArguments(baselineStripComments($contents)) as $argument) {
            $readsFreeText = preg_match(
                '/\$\w+(?:->\w+)*->(?:name|description|title|address|notes)\b/',
                $argument
            ) === 1;

            // As in the assertSee guard, the helper must be matched as a CALL:
            // a bare substring test would be satisfied by unrelated text.
            $isWrapTolerant = preg_match('/(?<!\w)pdfNormalizeText\(/', $argument) === 1;

            if ($readsFreeText && ! $isWrapTolerant) {
                $offenders[] = $relative.': '.trim($argument);
            }
        }
    }

    return $offenders;
}

it('leaves no contiguous PDF-text assertion against a wrap-capable value', function () {
    expect(baselinePdfContiguityOffenders(baselineTestSources()))->toBe(
        [],
        'These tests require a wrap-capable value to be a contiguous substring of `pdftotext -layout` output. '
        .'Read the field with pdfLayoutFieldValue()/pdfLayoutColumnText(), or normalise it with pdfNormalizeText().'
    );
});

it('flags the reintroduced contiguous PDF-text shape and nothing else', function () {
    /*
     * The behavioural half. A guard that reports zero offenders is worthless
     * unless it is also shown to report the offender it exists for — this file
     * is excluded from its own scan precisely so the shape can be quoted here.
     */
    $forbidden = [
        'the historical failure' => '<?php $text = pdfExtractText($bytes); expect($text)->toContain($visit->patient->name);',
        'wrapped in a cast' => '<?php $text = pdfExtractText($bytes); expect($text)->toContain(strtoupper($visit->patient->name));',
        'a description' => '<?php $text = pdfExtractText($bytes); expect($text)->toContain($item->description);',
        'a negated form' => '<?php $text = pdfExtractText($bytes); expect($text)->not->toContain($branch->address);',
    ];

    foreach ($forbidden as $label => $source) {
        expect(baselinePdfContiguityOffenders(['Offender.php' => $source]))
            ->toHaveCount(1, "The guard missed {$label}.");
    }

    $allowed = [
        'a literal' => '<?php $text = pdfExtractText($bytes); expect($text)->toContain(\'LUNAS\');',
        'a fixed-width identifier' => '<?php $text = pdfExtractText($bytes); expect($text)->toContain($invoice->invoice_number);',
        'formatted money' => '<?php $text = pdfExtractText($bytes); expect($text)->toContain(number_format($item->subtotal, 0, \',\', \'.\'));',
        'the wrap-tolerant helper' => '<?php $text = pdfExtractText($bytes); expect($column)->toContain(pdfNormalizeText($item->description));',
        'field equality' => '<?php $text = pdfExtractText($bytes); expect(pdfLayoutFieldValue($text, \'Nama Pasien\'))->toBe($visit->patient->name);',
        'the shape quoted inside a comment' => '<?php $text = pdfExtractText($bytes); /* expect($text)->toContain($visit->patient->name); */',
        'a test that renders no PDF' => '<?php expect($response->content())->toContain($visit->patient->name);',
    ];

    foreach ($allowed as $label => $source) {
        expect(baselinePdfContiguityOffenders(['Fine.php' => $source]))
            ->toBe([], "The guard wrongly flagged {$label}.");
    }

    /*
     * The greedy-regex trap: one safe and one unsafe assertion on a single
     * line. Mentioning the approved helper anywhere on the line must not
     * launder the offender beside it.
     */
    $mixed = '<?php $text = pdfExtractText($bytes); expect($a)->toContain(pdfNormalizeText($item->description))->and($b)->toContain($visit->patient->name);';

    expect(baselinePdfContiguityOffenders(['Mixed.php' => $mixed]))->toHaveCount(1);
});
