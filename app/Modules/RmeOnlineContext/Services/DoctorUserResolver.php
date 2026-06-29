<?php

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;

/**
 * Resolves the link between an authenticated user and a doctor master record.
 */
class DoctorUserResolver
{
    public function resolveForUser(User $user): ?Doctor
    {
        $byUserId = Doctor::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($byUserId !== null) {
            return $byUserId;
        }

        if ($user->email === null || $user->email === '') {
            return null;
        }

        return Doctor::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->first();
    }

    public function resolveUserForDoctor(Doctor $doctor): ?User
    {
        if ($doctor->user_id) {
            return User::query()->find($doctor->user_id);
        }

        if ($doctor->email === null || $doctor->email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($doctor->email)])
            ->first();
    }
}
