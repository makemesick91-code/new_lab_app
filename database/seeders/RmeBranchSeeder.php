<?php

namespace Database\Seeders;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
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
        BranchCodeAlias::TELKOMAS_CANONICAL => 'Cabang Telkomas',
        'LDK2' => 'Cabang Landak',
        'ATG3' => 'Cabang Antang',
        'SUN4' => 'Cabang Sunu',
    ];

    public function run(): void
    {
        foreach (self::CANONICAL_RME_BRANCHES as $code => $name) {
            // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — match on EVERY code
            // that names this branch, canonical and deprecated alike, before
            // deciding the branch is missing.
            //
            // Cabang Telkomas' canonical code became TLK1 while production still
            // held rows, identifiers and an approved rollout wave issued under
            // TKM1. Looking the branch up by its canonical code alone would find
            // nothing in a deployment that had not been migrated yet and would
            // then CREATE a second "Cabang Telkomas" — splitting one clinic's
            // patients across two branch ids and, because branch id is the
            // isolation boundary, hiding half of each operator's own data. A
            // seeder whose stated purpose is idempotence must not be the thing
            // that duplicates a branch.
            //
            // Renaming a matched row is deliberately NOT done here: converting a
            // deprecated code to the canonical one is a one-time data migration
            // that has to check for collisions first, and it lives in the
            // migration that owns that transaction. This seeder only guarantees
            // it never creates a duplicate.
            $branch = Branch::withTrashed()
                ->whereIn('code', BranchCodeAlias::equivalentCodes($code))
                ->first()
                ?? Branch::withTrashed()->firstOrNew(['code' => $code]);

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
