<?php

namespace App\Modules\Observability\Support;

use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — the ONE place telemetry text is made
 * safe to store. Every recorder path calls this; nothing copies its rules.
 *
 * It composes the ENT-7 SensitiveValueMasker (credentials, bearer tokens,
 * emails, KTP-shaped digit runs) and adds what an exception or SQL statement
 * specifically leaks:
 *
 *   - quoted string literals          -> '[REDACTED]'
 *   - phone-shaped digit runs (>= 9)  -> [REDACTED]
 *   - PostgreSQL "Key (col)=(value)"  -> (col)=([REDACTED])
 *   - QueryException's interpolated "(Connection: ..., SQL: ...)" tail,
 *     which carries bindings verbatim, is dropped entirely.
 *
 * It redacts aggressively on purpose: an over-redacted message costs a
 * developer a minute; an under-redacted one puts a patient's NIK on a screen.
 */
final class TelemetryRedactor
{
    public const REDACTED = '[REDACTED]';

    public function __construct(private readonly SensitiveValueMasker $masker) {}

    /**
     * A route TEMPLATE when the request matched a route (`rme/visits/{clinicVisit}`),
     * otherwise the raw path with every id-shaped segment collapsed. Never a
     * query string.
     */
    public function pathTemplate(Request $request): string
    {
        $route = $request->route();
        if ($route !== null && method_exists($route, 'uri')) {
            return $this->truncate('/'.ltrim((string) $route->uri(), '/'), 255);
        }

        $segments = array_values(array_filter(explode('/', trim($request->path(), '/')), fn ($s) => $s !== ''));
        $safe = array_map(function (string $segment): string {
            // Anything carrying a digit, or long enough to be a token / slug
            // derived from a person, is an identifier.
            if (preg_match('/\d/', $segment) || strlen($segment) > 32) {
                return '{x}';
            }

            return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $segment);
        }, $segments);

        return $this->truncate('/'.implode('/', $safe), 255);
    }

    public function exceptionMessage(Throwable $exception, ?string $pathTemplate = null): string
    {
        // A validation message quotes the values that failed (a patient's
        // birth date, a Nomor RM, a document date). Only the field KEYS and a
        // count are kept — never the message.
        if ($exception instanceof ValidationException) {
            $fields = array_keys($exception->errors());

            return $this->truncate('Validasi gagal ('.count($fields).' field): '.implode(', ', array_slice($fields, 0, 20)), 500);
        }

        // Laravel's unmatched-route message embeds the RAW path, undoing the
        // template collapse. It is rebuilt from the redacted template instead.
        if ($exception instanceof NotFoundHttpException && str_starts_with($exception->getMessage(), 'The route ')) {
            return 'Route tidak ditemukan: '.($pathTemplate ?? '[path]');
        }

        $message = $exception->getMessage();

        // Applied to EVERY message, not only QueryException: a wrapped query
        // error carries the same interpolated tail.
        foreach ([' (Connection:', 'DETAIL:', 'Failing row contains'] as $marker) {
            $cut = strpos($message, $marker);
            if ($cut !== false) {
                $message = substr($message, 0, $cut);
            }
        }

        // PostgreSQL errors continue on later lines (DETAIL, HINT, CONTEXT)
        // with row data; only the first line is ever informative enough.
        if ($exception instanceof QueryException) {
            $message = strtok($message, "\n") ?: $message;
        }

        return $this->text(trim($message), 500);
    }

    /**
     * Redact free text. Order matters: structural leaks first, then the
     * shared masker, then the digit-run backstop.
     */
    public function text(?string $text, int $limit = 500): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = (string) preg_replace('/\)=\([^)]*\)/', ')=('.self::REDACTED.')', $text);
        $text = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'".self::REDACTED."'", $text);
        $text = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '"'.self::REDACTED.'"', $text);
        $text = $this->masker->mask($text);
        // Nomor RM (DG-{BRANCH}-{YEAR}-{SEQ}) identifies a patient.
        $text = (string) preg_replace('/\b[A-Z]{2,4}-[A-Z0-9]{3,6}-\d{4}-\d+\b/', self::REDACTED, $text);
        // Calendar dates (birth dates, document dates) in either order.
        $text = (string) preg_replace('/\b\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}\b/', self::REDACTED, $text);
        // Phone / NIK, including separator-formatted "+62 812-3456-7890".
        $text = (string) preg_replace('/\+?\d(?:[\s.\-]?\d){8,}/', self::REDACTED, $text);

        return $this->truncate($text, $limit);
    }

    public function relativeFile(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        $base = rtrim(base_path(), '/').'/';
        $relative = str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);

        return $this->truncate($relative, 255);
    }

    /**
     * A short, argument-free frame list. Arguments are never read: they are
     * where request payloads and model attributes live.
     *
     * @return list<string>
     */
    public function traceSummary(Throwable $exception, int $frames = 8): array
    {
        $summary = [];
        foreach (array_slice($exception->getTrace(), 0, $frames) as $frame) {
            $where = isset($frame['file'])
                ? $this->relativeFile((string) $frame['file']).':'.($frame['line'] ?? '?')
                : '[internal]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            // PHP >= 8.4 names closures "{closure:/abs/path/file.php:31}" —
            // the absolute server path must not leak through the function name.
            $call = str_replace(rtrim(base_path(), '/').'/', '', $call);
            $summary[] = $this->truncate($where.($call !== '' ? '  '.$call.'()' : ''), 255);
        }

        return $summary;
    }

    /**
     * Normalize SQL so it can be stored and grouped: every literal becomes
     * "?", placeholder lists collapse, whitespace folds. Combined with never
     * reading bindings, no value a query was run with is ever persisted.
     */
    public function normalizeSql(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/\$\d+/', '?', $sql);
        $sql = (string) preg_replace('/(?<![A-Za-z_"])-?\b\d+(?:\.\d+)?\b/', '?', $sql);
        $sql = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));

        return $this->truncate($sql, (int) config('observability_console.slow_query.max_sql_length', 2000));
    }

    public function fingerprint(string $normalizedSql): string
    {
        return sha1(strtolower($normalizedSql));
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
