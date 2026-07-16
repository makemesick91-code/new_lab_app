<?php

namespace App\Modules\Satusehat\Policies;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;

/**
 * Entity identifier governance authorization — all abilities require the
 * dedicated manage_satusehat_settings permission (Super Admin bypasses via
 * Gate::before). Identifiers are environment-scoped configuration.
 */
class SatusehatEntityIdentifierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage_satusehat_settings');
    }

    public function view(User $user, SatusehatEntityIdentifier $identifier): bool
    {
        return $user->can('manage_satusehat_settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage_satusehat_settings');
    }

    public function update(User $user, SatusehatEntityIdentifier $identifier): bool
    {
        return $user->can('manage_satusehat_settings');
    }
}
