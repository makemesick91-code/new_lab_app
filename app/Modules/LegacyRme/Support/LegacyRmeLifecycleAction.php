<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-OPS-CLI-1 — the closed vocabulary of import lifecycle operations.
 *
 * WHY THIS EXISTS. Before this sprint the four lifecycle operations existed only
 * as four controller methods. An operator recovering an aborted wave over SSH had
 * no canonical entry point, and the tempting alternatives — a direct UPDATE, a
 * Tinker `->update(['status' => ...])` — bypass the transition map, the branch
 * scope, the policy, the quota semantics and the audit trail all at once.
 *
 * Naming the four operations once, together with the permission the HTTP route
 * requires and the policy ability the controller checks, makes the CLI/HTTP
 * parity a property that can be asserted in a test rather than a claim in a
 * comment. Adding a fifth operation here without wiring both surfaces is
 * therefore visible, not silent.
 *
 * There is no native PHP enum anywhere in app/; the established convention is a
 * final class with public constants plus an explicit list, which is what this
 * provides while still being validated at every boundary.
 */
final class LegacyRmeLifecycleAction
{
    public const CANCEL = 'cancel';

    public const REVIEW = 'review';

    public const PUBLISH = 'publish';

    public const RETRY = 'retry';

    /** @var list<string> */
    public const ALL = [
        self::CANCEL,
        self::REVIEW,
        self::PUBLISH,
        self::RETRY,
    ];

    /**
     * The named permission the equivalent HTTP route requires as middleware.
     *
     * Asserted by the lifecycle service BEFORE the policy, so the command line
     * carries the same door the browser does. The policy re-checks the same
     * permission and adds the branch scope and the transition gate on top; this
     * is therefore defence in depth, not the only check.
     *
     * @var array<string, string>
     */
    public const REQUIRED_PERMISSIONS = [
        // Cancelling and retrying re-run the operator's OWN intake, so they
        // carry the intake permission — exactly as the routes declare.
        self::CANCEL => 'create_legacy_rme_imports',
        self::RETRY => 'create_legacy_rme_imports',
        // Reviewing and publishing are separable duties with their own named
        // permissions. That split is what makes a maker/checker pair possible.
        self::REVIEW => 'review_legacy_rme_imports',
        self::PUBLISH => 'publish_legacy_rme_imports',
    ];

    /**
     * The status the operation drives the import towards, where the transition
     * map has one. Reported in a dry run so an operator can see what --apply
     * would attempt without running it.
     *
     * @var array<string, string>
     */
    public const TARGET_STATUSES = [
        self::CANCEL => LegacyRmeImportStatus::CANCELLED,
        self::REVIEW => LegacyRmeImportStatus::REVIEWED,
        self::PUBLISH => LegacyRmeImportStatus::PUBLISHED,
        self::RETRY => LegacyRmeImportStatus::QUEUED,
    ];

    private function __construct() {}

    public static function isValid(?string $action): bool
    {
        return $action !== null && in_array($action, self::ALL, true);
    }

    /**
     * The policy ability guarding the operation. Deliberately the same name as
     * the action: the controller already authorizes `cancel`, `retry`,
     * `review` and `publish` on LegacyRmeImportPolicy, and inventing a second
     * naming scheme for the CLI is how two surfaces drift apart.
     */
    public static function policyAbility(string $action): string
    {
        return $action;
    }

    public static function requiredPermission(string $action): ?string
    {
        return self::REQUIRED_PERMISSIONS[$action] ?? null;
    }

    public static function targetStatus(string $action): ?string
    {
        return self::TARGET_STATUSES[$action] ?? null;
    }
}
