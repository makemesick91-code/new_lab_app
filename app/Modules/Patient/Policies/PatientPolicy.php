<?php

namespace App\Modules\Patient\Policies;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientService;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage patients');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can('manage patients');
    }

    public function create(User $user): bool
    {
        return $user->can('manage patients');
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4) — register a
     * patient under an old paper-card Nomor RM instead of the automatic one.
     * PatientService::createWithPaperCardNumber() re-asserts it, plus the
     * reason and the number checks that also bind Super Admin.
     */
    public function registerWithPaperCardNumber(User $user): bool
    {
        return $user->can('manage patients') && $user->can(PatientService::PAPER_CARD_PERMISSION);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can('manage patients');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->can('manage patients');
    }
}
