<?php

namespace App\Modules\Technician\Services;

use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * FIX-LAB-TECHNICIAN-ROLE-ASSIGNMENT — single source of truth for WHO may
 * receive a NEW production assignment. Shared by the V2 workflow
 * (LabV2ProductionService) and the legacy path (AssignmentService) plus both
 * assignment dropdowns, so the rule can never drift between entry points.
 *
 * A technician is an eligible assignment TARGET only when ALL hold:
 *  - the master technician row is active and not soft-deleted;
 *  - it is linked to a user account (mst_technicians.user_id);
 *  - that user exists, is not soft-deleted, and is active;
 *  - that user holds the canonical Spatie role `Technician`.
 *
 * Production permissions (e.g. manage_production) are ACTOR overrides and are
 * never a substitute for the Technician role on the assignment target — a
 * supervisor may assign work, but may not BE assigned unless they hold the
 * role. Revoking the role only blocks NEW assignments; existing assignment
 * history stays readable and is never deleted.
 */
class TechnicianAssignmentEligibility
{
    public const ROLE = 'Technician';

    /** Eligible assignment targets (branch/lab is central by design — technicians are lab-wide actors). */
    public function query(): Builder
    {
        return Technician::query()
            ->where('is_active', true)
            ->whereHas('user', fn (Builder $user) => $user
                ->where('is_active', true)
                ->whereHas('roles', fn ($role) => $role->where('name', self::ROLE)))
            ->orderBy('name');
    }

    /** Dropdown source — id/name/user_id only, never email or other sensitive fields. */
    public function listForAssignment(): Collection
    {
        return $this->query()->get(['id', 'name', 'user_id']);
    }

    public function isEligible(?Technician $technician): bool
    {
        if ($technician === null || ! $technician->is_active) {
            return false;
        }

        $user = $technician->user()->first(); // belongsTo → soft-deleted users excluded

        return $user !== null
            && (bool) $user->is_active
            && $user->hasRole(self::ROLE);
    }

    /**
     * Server-side revalidation of a submitted technician_id — the request id
     * is never trusted; a crafted id for an inactive / role-less / unlinked
     * technician is rejected here regardless of what the dropdown showed.
     */
    public function assertAssignable(?int $technicianId): Technician
    {
        $technician = $technicianId ? Technician::query()->find($technicianId) : null;

        if ($technician === null || ! $this->isEligible($technician)) {
            throw ValidationException::withMessages([
                'technician_id' => 'Teknisi harus merupakan akun user aktif dengan role Technician.',
            ]);
        }

        return $technician;
    }
}
