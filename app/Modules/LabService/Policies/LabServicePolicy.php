<?php

namespace App\Modules\LabService\Policies;

use App\Models\User;
use App\Modules\LabService\Models\LabService;

class LabServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage lab services');
    }

    public function view(User $user, LabService $labService): bool
    {
        return $user->can('manage lab services');
    }

    public function create(User $user): bool
    {
        return $user->can('manage lab services');
    }

    public function update(User $user, LabService $labService): bool
    {
        return $user->can('manage lab services');
    }

    public function delete(User $user, LabService $labService): bool
    {
        return $user->can('manage lab services');
    }
}
