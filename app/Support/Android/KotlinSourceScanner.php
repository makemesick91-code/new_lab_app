<?php

namespace App\Support\Android;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5 — comment-aware Kotlin
 * source reading for the release-governance guards.
 *
 * Phase 3 found this the expensive way. Two TLS guards scanned Kotlin for a
 * forbidden call and matched the comment that explained why the call was
 * forbidden — the prose is byte-identical to the code. The app already obeyed
 * the rule; the guard fired on its own reasoning. A control that goes red on a
 * codebase that obeys it teaches people to delete the control.
 *
 * So: strip comments, and ONLY comments.
 *
 * Strings are preserved deliberately, not overlooked. The tokens this scanner
 * looks for — `signingConfigs.getByName("debug")` — contain a string literal.
 * Blanking string contents would make every forbidden token unmatchable and the
 * guard would pass on a genuinely broken build file. Literal awareness here
 * means "a // or /* inside a string does not open a comment", not "delete the
 * string".
 *
 * Kotlin block comments nest, which the naive `/*` .. `*``/` scan gets wrong:
 * an outer comment containing an inner one would be closed early and the tail
 * of the outer comment would be read as code.
 */
class KotlinSourceScanner
{
    /**
     * Remove Kotlin comments, leaving code and string/char literals intact.
     *
     * Newlines inside removed comments are preserved so reported line numbers
     * still line up with the file on disk.
     */
    public function stripComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            // Raw string: """ ... """ — no escapes, so only the closing triple
            // quote ends it.
            if (substr($source, $i, 3) === '"""') {
                $end = strpos($source, '"""', $i + 3);
                $end = $end === false ? $length : $end + 3;
                $out .= substr($source, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $consumed = $this->consumeLiteral($source, $i, $char);
                $out .= $consumed;
                $i += strlen($consumed);

                continue;
            }

            if ($char === '/' && $next === '/') {
                $end = strpos($source, "\n", $i);
                $i = $end === false ? $length : $end; // keep the newline itself

                continue;
            }

            if ($char === '/' && $next === '*') {
                $i = $this->skipNestedBlockComment($source, $i, $out);

                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * Body of a nested `a { b { c { ... } } }` path, each level brace-matched
     * inside the previous one.
     *
     * A file-wide search for the innermost name is not safe here. String
     * literals are preserved on purpose (the forbidden tokens contain one), so
     * a plain `release {` sitting inside any earlier string — `val notes =
     * "release { see the wiki }"` — wins the match and the scan reads a decoy
     * while the real build type goes unexamined. Walking `android` →
     * `buildTypes` → `release` means a decoy has to be inside `buildTypes` to
     * matter at all, and the caller's emptiness check catches that.
     *
     * @param  array<int,string>  $path
     */
    public function nestedBlockBody(string $strippedSource, array $path): ?string
    {
        $scope = $strippedSource;

        foreach ($path as $blockName) {
            $scope = $this->blockBody($scope, $blockName);

            if ($scope === null) {
                return null;
            }
        }

        return $scope;
    }

    /**
     * Body of a `name { ... }` block, brace-matched and literal-aware.
     *
     * Returns null when the block is absent — the caller decides whether that
     * is a missing block or a moved one, because those are different failures.
     */
    public function blockBody(string $strippedSource, string $blockName): ?string
    {
        if (! preg_match('/\b'.preg_quote($blockName, '/').'\s*\{/', $strippedSource, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $open = $match[0][1] + strlen($match[0][0]) - 1;
        $depth = 0;
        $length = strlen($strippedSource);

        for ($i = $open; $i < $length; $i++) {
            $char = $strippedSource[$i];

            if (substr($strippedSource, $i, 3) === '"""') {
                $end = strpos($strippedSource, '"""', $i + 3);
                $i = ($end === false ? $length : $end + 2);

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i += strlen($this->consumeLiteral($strippedSource, $i, $char)) - 1;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($strippedSource, $open + 1, $i - $open - 1);
                }
            }
        }

        // Unbalanced braces. Returning the tail would let a truncated read look
        // like a clean one, so say nothing was found.
        return null;
    }

    /**
     * Consume a single- or double-quoted literal INCLUDING its delimiters,
     * honouring backslash escapes. An unterminated literal consumes to EOF
     * rather than silently re-entering code mode.
     */
    private function consumeLiteral(string $source, int $start, string $quote): string
    {
        $length = strlen($source);
        $i = $start + 1;

        while ($i < $length) {
            if ($source[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($source[$i] === $quote) {
                return substr($source, $start, $i - $start + 1);
            }

            $i++;
        }

        return substr($source, $start);
    }

    /**
     * Skip a nesting block comment, appending only its newlines so line numbers
     * survive.
     */
    private function skipNestedBlockComment(string $source, int $start, string &$out): int
    {
        $length = strlen($source);
        $depth = 0;
        $i = $start;

        while ($i < $length) {
            $pair = substr($source, $i, 2);

            if ($pair === '/*') {
                $depth++;
                $i += 2;

                continue;
            }

            if ($pair === '*/') {
                $depth--;
                $i += 2;

                if ($depth === 0) {
                    return $i;
                }

                continue;
            }

            if ($source[$i] === "\n") {
                $out .= "\n";
            }

            $i++;
        }

        return $length;
    }
}
