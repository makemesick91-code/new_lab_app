<?php

namespace App\Modules\Branch\Support;

use Illuminate\Support\Facades\Config;

/**
 * Centralized module branch-scope rule (Sprint 23 Phase 23.5).
 *
 * Only RME and Inventory are multi-branch. Laboratory is single / global and
 * must never be branch-filtered or branch-enforced. Read the rule here instead
 * of hard-coding module names so the business rule stays in one place.
 */
final class ModuleBranchScope
{
    public const MULTI_BRANCH = 'multi_branch';

    public const SINGLE_BRANCH = 'single_branch';

    public static function scope(string $module): string
    {
        return Config::get(
            'module_branch_scope.'.strtolower($module),
            self::SINGLE_BRANCH,
        );
    }

    public static function isMultiBranch(string $module): bool
    {
        return self::scope($module) === self::MULTI_BRANCH;
    }

    public static function isSingleBranch(string $module): bool
    {
        return ! self::isMultiBranch($module);
    }

    /**
     * Whether a module should expose / enforce a branch filter in
     * dashboards, lists and reports.
     */
    public static function usesBranchFilter(string $module): bool
    {
        return self::isMultiBranch($module);
    }
}
