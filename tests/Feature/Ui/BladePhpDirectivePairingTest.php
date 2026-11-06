<?php

// DMS-RME-REVISION-PROGRAM-8A / P8-N3 (rule 226) — no Blade template may
// compile to a mis-paired PHP block.
//
// Blade stores raw PHP blocks before it compiles anything else (and before it
// strips comments), pairing the first PHP directive opener with the next block
// closer. When a template mixes the inline (parenthesised) and the block form,
// the inline opener is captured and the directive literal ends up inside the
// compiled PHP. Two checks, because neither alone covers every shape:
//
//   - an open tag glued to the next character (the P8-N3 shape). With
//     short_open_tag=On it is a ParseError (HTTP 500); with Off — production's
//     php.ini — it is not an open tag at all and the template source is printed
//     as text, so only this signature catches it there;
//   - a real parse of the compiled view (token_get_all TOKEN_PARSE), which
//     catches the spaced shape and a closer hidden in a later Blade comment —
//     both ParseErrors under either setting, and invisible to the regex.
//
// Measured 2026-11-07: 517 templates, 0 hits on either check after the fix,
// under both short_open_tag settings (no false positives).
//
// Helper prefix `p8abp` is unique because the critical gate loads every suite
// into one process.

use Illuminate\Support\Facades\Blade;

/** The reason a compiled view is mis-paired, or null when it is sound. */
function p8abpDefect(string $compiled): ?string
{
    if (preg_match('/<\?php(?=\S)/', $compiled) === 1) {
        return 'open tag glued to the next character';
    }

    try {
        token_get_all($compiled, TOKEN_PARSE);
    } catch (ParseError $e) {
        return 'compiled view does not parse: '.$e->getMessage();
    }

    return null;
}

/** @return array<string, string> template path => defect */
function p8abpScanViews(): array
{
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $defect = p8abpDefect(Blade::compileString(file_get_contents($file->getPathname())));

        if ($defect !== null) {
            $hits[str_replace(base_path().'/', '', $file->getPathname())] = $defect;
        }
    }

    return $hits;
}

/** The directive tokens, assembled so this file holds none Blade could pair. */
function p8abpTokens(): array
{
    return ['@'.'php', '@'.'end'.'php'];
}

it('P8A-BP-01 detects the P8-N3 shape: inline opener glued to its parenthesis, then a block', function () {
    [$open, $close] = p8abpTokens();
    $defect = $open."(\$room = \$room ?? null)\n".$open."\n    \$labels = [];\n".$close."\n";

    expect(p8abpDefect(Blade::compileString($defect)))->not->toBeNull();
});

it('P8A-BP-01b detects the spaced shape that the glued signature alone misses', function () {
    [$open, $close] = p8abpTokens();
    $defect = $open." (\$room = \$room ?? null)\n".$open."\n    \$labels = [];\n".$close."\n";

    expect(p8abpDefect(Blade::compileString($defect)))->toStartWith('compiled view does not parse');
});

it('P8A-BP-01c detects a block closer hidden in a later Blade comment', function () {
    [$open, $close] = p8abpTokens();
    $defect = $open." (\$room = \$room ?? null)\n<p>x</p>\n{{-- note ".$close." --}}\n";

    expect(p8abpDefect(Blade::compileString($defect)))->toStartWith('compiled view does not parse');
});

it('P8A-BP-02 accepts the inline form and the block form when they are not mixed', function () {
    [$open, $close] = p8abpTokens();

    expect(p8abpDefect(Blade::compileString($open.'($a = 1)')))->toBeNull()
        ->and(p8abpDefect(Blade::compileString($open."\n  \$a = 1;\n".$close)))->toBeNull()
        ->and(p8abpDefect(Blade::compileString($open."\n  \$a = 1;\n".$close."\n".$open.'($b = 2)')))->toBeNull();
});

it('P8A-BP-03 finds no template in resources/views that compiles to a mis-paired PHP block', function () {
    expect(p8abpScanViews())->toBe([]);
});
