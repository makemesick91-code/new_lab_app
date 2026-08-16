<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use RuntimeException;

/**
 * LEGACY-RME-OPS-CLI-1 — a refusal raised before any application service is
 * reached: no `--actor`, an unknown one, or an inactive one.
 *
 * It carries a stable {@see LegacyRmeLifecycleRefusal} code so `--json` output
 * is branchable, and it is deliberately a distinct type from ValidationException
 * so a command-level input problem is never mistaken for a canonical service
 * declining a lifecycle transition. Nothing has been written when this is
 * thrown.
 */
class LegacyRmeCommandRefusal extends RuntimeException
{
    /**
     * Named `refusalCode` rather than `code`: Exception already declares a
     * non-readonly int `$code`, and redeclaring it as a readonly string is a
     * fatal error.
     */
    public readonly string $refusalCode;

    public function __construct(string $refusalCode, string $message)
    {
        parent::__construct($message);

        $this->refusalCode = $refusalCode;
    }
}
