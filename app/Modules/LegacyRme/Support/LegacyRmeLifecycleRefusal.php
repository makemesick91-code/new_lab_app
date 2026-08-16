<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-OPS-CLI-1 — stable, machine-readable reasons a lifecycle operation
 * is refused.
 *
 * WHY CODES AND NOT JUST MESSAGES. An operator recovering a stalled wave at 2am
 * pipes `--json` into something. Messages are Indonesian operator prose and will
 * be reworded; a runbook or a script that branches on prose breaks silently the
 * first time someone improves a sentence. These codes are the contract, the
 * messages are the explanation.
 *
 * Every code here means "refused, nothing was written". None of them is ever
 * returned alongside a mutation.
 */
final class LegacyRmeLifecycleRefusal
{
    /** The migration capability is switched off on this deployment. */
    public const FEATURE_DISABLED = 'FEATURE_DISABLED';

    /**
     * The import does not exist, or exists outside the actor's server-resolved
     * branch scope. Deliberately ONE code: distinguishing them would confirm the
     * existence of a row the caller may not see.
     */
    public const IMPORT_NOT_IN_SCOPE = 'IMPORT_NOT_IN_SCOPE';

    /** The actor lacks the named permission the equivalent HTTP route requires. */
    public const PERMISSION_DENIED = 'PERMISSION_DENIED';

    /**
     * The permission is held but the policy refused — branch scope, or the 1A
     * transition gate (a terminal import can never be re-driven).
     */
    public const POLICY_DENIED = 'POLICY_DENIED';

    /**
     * The 1A transition map does not allow this operation from the import's
     * current status.
     *
     * SEPARATE FROM POLICY_DENIED ON PURPOSE. A Super Admin's global
     * `Gate::before` bypass makes every policy answer yes, so for that one
     * account the policy can no longer predict anything — and a dry run that
     * reported "eligible" for a transition the canonical service is certain to
     * refuse would be worse than no dry run at all. This code is what a preflight
     * reports instead, and it is the only blocker derived from the transition map
     * rather than from an authorization gate.
     */
    public const TRANSITION_NOT_ALLOWED = 'TRANSITION_NOT_ALLOWED';

    /**
     * The actor filed this document and separation of duties is enforced on
     * this deployment, so they may not also certify it.
     */
    public const SEPARATION_OF_DUTIES = 'SEPARATION_OF_DUTIES';

    /** The canonical service refused: wrong state, missing source, unusable pages. */
    public const SERVICE_REFUSED = 'SERVICE_REFUSED';

    /** The command was given no `--actor`, an unknown one, or an inactive one. */
    public const ACTOR_REQUIRED = 'ACTOR_REQUIRED';

    public const ACTOR_NOT_FOUND = 'ACTOR_NOT_FOUND';

    public const ACTOR_INACTIVE = 'ACTOR_INACTIVE';

    /** The action word is not one of the four lifecycle operations. */
    public const UNKNOWN_ACTION = 'UNKNOWN_ACTION';

    /** No `--import` was supplied, or it was not a positive integer. */
    public const IMPORT_REQUIRED = 'IMPORT_REQUIRED';

    /**
     * A publish archive label exceeded the same bound the HTTP FormRequest
     * enforces (title 150, description 2000).
     *
     * The command line must not be a way to write a value the browser would have
     * rejected — "HTTP validates it, the CLI does not" is exactly the kind of
     * drift this workstream exists to prevent.
     */
    public const INVALID_ARCHIVE_LABEL = 'INVALID_ARCHIVE_LABEL';

    /** @var list<string> */
    public const ALL = [
        self::FEATURE_DISABLED,
        self::IMPORT_NOT_IN_SCOPE,
        self::PERMISSION_DENIED,
        self::POLICY_DENIED,
        self::TRANSITION_NOT_ALLOWED,
        self::SEPARATION_OF_DUTIES,
        self::SERVICE_REFUSED,
        self::ACTOR_REQUIRED,
        self::ACTOR_NOT_FOUND,
        self::ACTOR_INACTIVE,
        self::UNKNOWN_ACTION,
        self::IMPORT_REQUIRED,
        self::INVALID_ARCHIVE_LABEL,
    ];

    private function __construct() {}

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::ALL, true);
    }
}
