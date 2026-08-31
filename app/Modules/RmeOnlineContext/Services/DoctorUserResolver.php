<?php

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorIdentityResolver;

/**
 * Resolves the link between an authenticated user and a doctor master record
 * for the RME online-context flow.
 *
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1 — identity now comes
 * exclusively from DoctorIdentityResolver, i.e. the explicit
 * `mst_doctors.user_id` link. The previous case-insensitive email fallback was
 * removed: this resolver decides which patients, visits and medical records a
 * doctor account may open (DoctorPatientScopeService) and which doctor a visit
 * is attributed to, so a coincidental or re-used email address must never be
 * able to stand in for identity. An unlinked account now fails closed with the
 * existing "hubungi admin klinik" message instead of silently resolving.
 *
 * The `is_active` filter is unchanged: this flow is about who may practise now,
 * which stays a separate question from who the account is.
 */
class DoctorUserResolver
{
    public function __construct(
        private readonly DoctorIdentityResolver $identity,
    ) {}

    public function resolveForUser(User $user): ?Doctor
    {
        $doctor = $this->identity->resolveForUser($user);

        if ($doctor === null || ! $doctor->is_active) {
            return null;
        }

        return $doctor;
    }

    public function resolveUserForDoctor(Doctor $doctor): ?User
    {
        return $this->identity->resolveUserForDoctor($doctor);
    }
}
