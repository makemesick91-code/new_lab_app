<?php

namespace Database\Seeders;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * RME-BRANCH-SUN4 — canonical Cabang RME registry.
 *
 * Idempotent and non-destructive by design:
 *   - Existing branches (matched by unique `code`, including soft-deleted rows)
 *     are NEVER renamed or reconfigured — production master data stays owned by
 *     Master Data Cabang. The only exception is SUN4, the sprint target: it is
 *     restored if soft-deleted and re-asserted active + RME-enabled so a re-run
 *     always converges on a selectable Cabang Sunu.
 *   - New rows are created active + RME-enabled. Inventory participation is a
 *     separate business decision (Master Data Cabang), so new rows start with
 *     `is_inventory_enabled = false` instead of inheriting the column default.
 *   - MAIN is never touched here (see BranchSeeder).
 *
 * Safe to run repeatedly: `php artisan db:seed --class=RmeBranchSeeder --force`.
 */
class RmeBranchSeeder extends Seeder
{
    /**
     * Canonical branch code registry (never reuse or renumber these codes).
     *
     * @var array<string, string>
     */
    public const CANONICAL_RME_BRANCHES = [
        'TKM1' => 'Cabang Telkomas',
        'LDK2' => 'Cabang Landak',
        'ATG3' => 'Cabang Antang',
        'SUN4' => 'Cabang Sunu',
    ];

    public function run(): void
    {
        foreach (self::CANONICAL_RME_BRANCHES as $code => $name) {
            $branch = Branch::withTrashed()->firstOrNew(['code' => $code]);

            if (! $branch->exists) {
                $branch->fill([
                    'name' => $name,
                    'address' => null,
                    'phone' => null,
                    'is_active' => true,
                    'is_rme_enabled' => true,
                    'is_inventory_enabled' => false,
                ]);
                $branch->save();

                continue;
            }

            if ($code !== 'SUN4') {
                // Existing sibling branches keep their production configuration.
                continue;
            }

            if ($branch->trashed()) {
                $branch->restore();
            }

            if (! $branch->is_active || ! $branch->is_rme_enabled) {
                $branch->forceFill([
                    'is_active' => true,
                    'is_rme_enabled' => true,
                ])->save();
            }
        }
    }
}
