<?php

namespace App\Modules\Satusehat\Policies;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;

/**
 * Mapping governance authorization — all abilities require the dedicated
 * manage_satusehat_mappings permission (Super Admin bypasses via Gate::before).
 * Mappings are environment-scoped master data, not branch-owned.
 */
class SatusehatCodeMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage_satusehat_mappings');
    }

    public function view(User $user, SatusehatCodeMapping $mapping): bool
    {
        return $user->can('manage_satusehat_mappings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage_satusehat_mappings');
    }

    public function update(User $user, SatusehatCodeMapping $mapping): bool
    {
        return $user->can('manage_satusehat_mappings');
    }

    public function review(User $user, SatusehatCodeMapping $mapping): bool
    {
        return $user->can('manage_satusehat_mappings');
    }

    public function activate(User $user, SatusehatCodeMapping $mapping): bool
    {
        return $user->can('manage_satusehat_mappings');
    }

    public function deprecate(User $user, SatusehatCodeMapping $mapping): bool
    {
        return $user->can('manage_satusehat_mappings');
    }
}
