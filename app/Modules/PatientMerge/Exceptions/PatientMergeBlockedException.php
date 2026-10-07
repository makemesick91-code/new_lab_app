<?php

namespace App\Modules\PatientMerge\Exceptions;

use RuntimeException;

/**
 * A merge (or a step towards one) refused for a stated reason. Callers branch
 * on the CODE, never on the message. Messages are operator-facing Indonesian
 * and carry no patient identifiers beyond what the screen already shows.
 */
class PatientMergeBlockedException extends RuntimeException
{
    /**
     * @param  array<int, array{code: string, message: string}>  $blockers
     */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly array $blockers = [],
    ) {
        parent::__construct($message);
    }

    /** @param  array<int, array{code: string, message: string}>  $blockers */
    public static function fromBlockers(array $blockers): self
    {
        $first = $blockers[0] ?? ['code' => 'BLOCKED', 'message' => 'Penggabungan tidak dapat dilanjutkan.'];

        return new self($first['code'], $first['message'], $blockers);
    }
}
