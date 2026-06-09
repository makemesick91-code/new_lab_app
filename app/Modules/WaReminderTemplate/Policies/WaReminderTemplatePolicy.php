<?php

namespace App\Modules\WaReminderTemplate\Policies;

use App\Models\User;
use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;

class WaReminderTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, WaReminderTemplate $template): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, WaReminderTemplate $template): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, WaReminderTemplate $template): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        return $user->canAny([
            'view_clinic_master_data',
            'manage_clinic_master_data',
        ]);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_clinic_master_data');
    }
}
