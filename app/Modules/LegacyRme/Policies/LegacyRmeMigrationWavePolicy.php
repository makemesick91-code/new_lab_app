<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Policies;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;

/**
 * LEGACY-RME-PDF-ROLL-4 — authorization for the migration operations surface.
 *
 * THREE CAPABILITIES, DELIBERATELY SEPARATE:
 *
 *   view    — read the operations dashboard. Counts and codes; no clinical
 *             content, so it is safe to give to an owner who wants oversight
 *             without any ability to change the rollout.
 *   manage  — create waves, enroll branches, assign operators, set quotas,
 *             pause, resume, drain, sign a branch off.
 *   approve — sign a wave as governance-approved.
 *
 * `approve` is split from `manage` so the separation-of-duties rule has
 * something to enforce. With `require_separate_approver` on, the approver must
 * also differ from the creator; the assessed risk of running with it off during
 * the pilot is recorded in config/legacy_rme_operations.php.
 *
 * NO BRANCH SCOPE HERE, AND THAT IS INTENTIONAL. A wave is a rollout-wide
 * governance object, not a clinical row — it holds counts and branch labels, no
 * patient data. Per-branch confinement lives where it belongs: on the
 * INGESTION path, where `LegacyRmeOperationsGateService` requires an assignment
 * for the specific RM-derived branch. Adding a second, weaker branch check here
 * would imply the dashboard is the boundary, and it is not.
 *
 * NO before() HOOK. The application has exactly one global bypass (Super Admin,
 * in RepositoryServiceProvider); a second one here would shadow it and make the
 * real boundary harder to audit.
 */
class LegacyRmeMigrationWavePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'view_legacy_rme_migration_operations',
            'manage_legacy_rme_migration_operations',
        ]);
    }

    public function view(User $user, LegacyRmeMigrationWave $wave): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->can('manage_legacy_rme_migration_operations');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, LegacyRmeMigrationWave $wave): bool
    {
        // A closed wave is governance evidence. Reopening it by editing would
        // erase the record of what was actually authorized and migrated.
        return $this->manage($user) && ! $wave->isTerminal();
    }

    public function approve(User $user, LegacyRmeMigrationWave $wave): bool
    {
        return $user->can('approve_legacy_rme_migration_wave') && ! $wave->isTerminal();
    }

    /**
     * Waves are never deleted through the application. A wave that accepted
     * documents is the provenance of clinical evidence, and the migration that
     * produced an archive must stay reconstructable.
     */
    public function delete(User $user, LegacyRmeMigrationWave $wave): bool
    {
        return false;
    }
}
