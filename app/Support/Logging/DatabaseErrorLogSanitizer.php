<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Modules\Observability\Support\TelemetryRedactor;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * DMS-SPN4-READY-PILOT-1 G6 / REM-2b (CG-11) — a database error is written to the
 * application log without the values its query ran with.
 *
 * WHAT LEAKED
 * -----------
 * Laravel's QueryException message ends in "(Connection: …, SQL: …)" with every
 * binding interpolated verbatim: a NIK, a name, a phone number. PostgreSQL's own
 * message adds a DETAIL line that echoes the data ("Key (ktp_number)=(…) already
 * exists", or "Failing row contains (…the whole row…)" for a check or not-null
 * violation). The default reporter logged that message together with the
 * exception object (whose wrapped PDOException carries the same text), and
 * several services log $e->getMessage() of a caught Throwable directly. Rule 244
 * §5.3 had already made the patient service's own unique violations value-free;
 * every other QueryException still carried its bindings into the log.
 *
 * WHAT IS KEPT (enough to diagnose)
 * ---------------------------------
 * The exception class, SQLSTATE and driver code, the connection name, the first
 * line of the driver message with identifiers (column, relation, constraint, …)
 * kept and every value redacted, the SQL TEMPLATE with its ? placeholders (the
 * bindings are never read, only counted), and the file:line trace.
 *
 * This class only describes. It never catches, rethrows or changes an exception,
 * so HTTP responses and transactions behave exactly as before.
 */
final class DatabaseErrorLogSanitizer
{
    public const REDACTED = '[REDACTED]';

    /** A double-quoted token after one of these words is an identifier, not a value. */
    private const IDENTIFIER_KEYWORDS = 'column|relation|table|constraint|index|schema|sequence|database|role|function|view|trigger|type|extension|operator|collation|domain';

    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly TelemetryRedactor $redactor,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /** True when free text carries a database driver error (and so possibly its bindings). */
    public function isDatabaseErrorText(string $text): bool
    {
        return str_contains($text, 'SQLSTATE[')
            || (str_contains($text, ' (Connection: ') && str_contains($text, ', SQL: '));
    }

    /** The QueryException or PDOException in the chain of $exception (itself included), if any. */
    public function databaseCause(Throwable $exception): ?Throwable
    {
        $current = $exception;

        for ($depth = 0; $current !== null && $depth <= self::MAX_DEPTH; $depth++) {
            if ($current instanceof QueryException || $current instanceof PDOException) {
                return $current;
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    /** One line of error text with every value removed and the identifiers kept. */
    public function sanitizeText(string $text): string
    {
        // PostgreSQL continues on later lines (DETAIL, HINT, CONTEXT) with row data.
        $text = (string) (preg_split('/\R/', $text, 2)[0] ?? '');

        // Laravel's interpolated tail, and the value-echoing parts of a driver message.
        foreach ([' (Connection: ', 'DETAIL:', 'Failing row contains', 'HINT:', 'CONTEXT:'] as $marker) {
            $cut = strpos($text, $marker);
            if ($cut !== false) {
                $text = substr($text, 0, $cut);
            }
        }

        // "Key (ktp_number)=(value)".
        $text = (string) preg_replace('/\)=\([^)]*\)/', ')=('.self::REDACTED.')', $text);
        // Single-quoted literals.
        $text = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "'".self::REDACTED."'", $text);
        // Double quotes hold identifiers after a keyword (column "ktp_number") and
        // values elsewhere (invalid input syntax for type bigint: "…").
        $text = (string) preg_replace_callback(
            '/(\b(?:'.self::IDENTIFIER_KEYWORDS.')\s+)?"((?:[^"\\\\]|\\\\.)*)"/i',
            static fn (array $m): string => ($m[1] ?? '') !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/', $m[2]) === 1
                ? $m[0]
                : ($m[1] ?? '').'"'.self::REDACTED.'"',
            $text,
        );
        // Digit runs that can be a NIK, a phone number or a date, separators included.
        $text = (string) preg_replace('/\+?\d(?:[\s.\-\/]?\d){7,}/', self::REDACTED, $text);
        // The canonical masker last (credentials, bearer tokens, e-mail addresses).
        $text = $this->masker->mask($text);

        return mb_substr(trim($text), 0, 500);
    }

    /**
     * A log-safe description of $exception and its chain.
     *
     * @return array<string, mixed>
     */
    public function describe(Throwable $exception, int $depth = 0): array
    {
        $entry = [
            'class' => $exception::class,
            'message' => $this->sanitizeText($exception->getMessage()),
            'file' => ($this->redactor->relativeFile($exception->getFile()) ?? '[unknown]').':'.$exception->getLine(),
        ];

        if ($exception instanceof QueryException) {
            $entry['sqlstate'] = $this->sqlstate($exception->errorInfo[0] ?? $exception->getCode());
            $entry['driver_code'] = is_int($exception->errorInfo[1] ?? null) ? $exception->errorInfo[1] : null;
            $entry['connection'] = (string) $exception->getConnectionName();
            $entry['sql'] = $this->redactor->normalizeSql($exception->getSql());
            $entry['binding_count'] = count($exception->getBindings());
        } elseif ($exception instanceof PDOException) {
            $entry['sqlstate'] = $this->sqlstate($exception->errorInfo[0] ?? $exception->getCode());
        }

        if ($depth === 0) {
            $entry['trace'] = $this->redactor->traceSummary($exception, 15);
        }

        $previous = $exception->getPrevious();
        if ($previous !== null && $depth < self::MAX_DEPTH) {
            $entry['previous'] = $this->describe($previous, $depth + 1);
        }

        return $entry;
    }

    private function sqlstate(mixed $value): string
    {
        return is_string($value) && preg_match('/^[0-9A-Z]{5}$/', $value) === 1 ? $value : 'unknown';
    }
}
