<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * DMS-SPN4-READY-PILOT-1 G6 / REM-2b (CG-11) — removes query values from every log
 * record that carries a database error, whoever wrote it: the exception handler
 * ("exception" => QueryException, message with bindings) or a service that logs
 * $e->getMessage() of a caught Throwable.
 *
 * Records without a database error pass through untouched. The record is still
 * written at its own level, so the ERROR count the monitoring reads is unchanged.
 * Should the redaction itself fail, the record is withheld (replaced by a fixed
 * line at the same level) rather than written raw: fail closed.
 */
final class RedactDatabaseErrorsProcessor implements ProcessorInterface
{
    public const WITHHELD = 'A log record carrying a database error could not be redacted and was withheld (REM-2b).';

    private const MAX_DEPTH = 5;

    public function __construct(private readonly DatabaseErrorLogSanitizer $sanitizer) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $changed = false;
            $context = $this->context($record->context, $changed, 0);
            $message = $record->message;

            if ($this->sanitizer->isDatabaseErrorText($message)) {
                $message = $this->sanitizer->sanitizeText($message);
                $changed = true;
            }

            return $changed ? $record->with(message: $message, context: $context) : $record;
        } catch (Throwable) {
            return $record->with(message: self::WITHHELD, context: [], extra: []);
        }
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function context(array $values, bool &$changed, int $depth): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Throwable) {
                if ($this->sanitizer->databaseCause($value) !== null) {
                    $values[$key] = $this->sanitizer->describe($value);
                    $changed = true;
                }
            } elseif (is_string($value)) {
                if ($this->sanitizer->isDatabaseErrorText($value)) {
                    $values[$key] = $this->sanitizer->sanitizeText($value);
                    $changed = true;
                }
            } elseif (is_array($value) && $depth < self::MAX_DEPTH) {
                $values[$key] = $this->context($value, $changed, $depth + 1);
            }
        }

        return $values;
    }
}
