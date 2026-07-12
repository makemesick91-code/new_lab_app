<?php

declare(strict_types=1);

namespace App\Modules\Technician\Services;

use App\Models\User;
use App\Modules\Technician\Models\Technician;
use App\Support\AccessControl\AdminLabLabOnlyAuditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * LAB-WORKFLOW-V2-PILOT-UAT-1 — Technician account readiness auditor.
 *
 * Read-only {@see audit()} surfaces whether at least one Technician is eligible
 * for Lab Workflow V2 assignment (the documented pilot blocker) and flags orphan
 * masters, unlinked/mis-roled users, inactive links, and duplicate user links.
 *
 * {@see linkUser()} is the ONLY mutation: it links a master `mst_technicians`
 * row to an existing user that ALREADY holds the Technician role. It is
 * transactional, row-locked, fail-closed, idempotent, and NEVER changes a
 * user's role, deletes history, or links ambiguous rows.
 *
 * Decision convention mirrors {@see AdminLabLabOnlyAuditor}:
 * summary.decision in {GO, WATCH, NO-GO}; anomaly/critical codes are snake_case.
 */
final class TechnicianAccountAuditor
{
    public function __construct(private readonly TechnicianAssignmentEligibility $eligibility) {}

    /**
     * Read-only audit of technician accounts + assignment eligibility.
     *
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        /** @var Collection<int,Technician> $technicians */
        $technicians = Technician::query()->with('user.roles')->orderBy('name')->get();

        $rows = [];
        $eligibleCount = 0;
        $anomalyCodes = [];
        $criticalCodes = [];

        // Duplicate detection: same active user linked to >1 active technician master.
        $userLinkCounts = $technicians
            ->filter(fn (Technician $t) => $t->is_active && $t->user_id !== null)
            ->groupBy('user_id')
            ->map->count();

        foreach ($technicians as $technician) {
            $user = $technician->user; // belongsTo — soft-deleted users resolve to null.
            $issues = [];

            $userLinked = $user !== null;
            $userActive = $userLinked && (bool) $user->is_active;
            $hasRole = $userLinked && $user->hasRole(TechnicianAssignmentEligibility::ROLE);
            $eligible = $this->eligibility->isEligible($technician);

            if ($eligible) {
                $eligibleCount++;
            }

            if ($technician->is_active && $technician->user_id === null) {
                $issues[] = 'orphan_technician_no_user';
            }
            if ($technician->is_active && $technician->user_id !== null && ! $userLinked) {
                // user_id set but the user is missing/soft-deleted.
                $issues[] = 'technician_user_missing_or_soft_deleted';
            }
            if ($technician->is_active && $userLinked && ! $userActive) {
                $issues[] = 'technician_user_inactive';
            }
            if ($technician->is_active && $userLinked && ! $hasRole) {
                $issues[] = 'technician_user_missing_role';
            }
            if ($technician->user_id !== null && ($userLinkCounts[$technician->user_id] ?? 0) > 1) {
                $issues[] = 'duplicate_user_link';
            }

            foreach ($issues as $code) {
                $anomalyCodes[] = $code;
                if ($code === 'duplicate_user_link') {
                    $criticalCodes[] = $code;
                }
            }

            $rows[] = [
                'id' => $technician->id,
                'code' => $technician->code,
                'name' => $technician->name, // operational label — not KTP/NIK; phone/email deliberately omitted.
                'is_active' => (bool) $technician->is_active,
                'user_id' => $technician->user_id,
                'user_linked' => $userLinked,
                'user_active' => $userActive,
                'user_has_technician_role' => $hasRole,
                'eligible' => $eligible,
                'issues' => array_values(array_unique($issues)),
            ];
        }

        if ($eligibleCount === 0) {
            $anomalyCodes[] = 'no_eligible_technician';
            $criticalCodes[] = 'no_eligible_technician';
        }

        $anomalyCodes = array_values(array_unique($anomalyCodes));
        $criticalCodes = array_values(array_unique($criticalCodes));

        // NO-GO when a critical code is present (no eligible technician = cannot run
        // internal production; duplicate link = ambiguous assignment). WATCH for
        // non-critical master/data gaps. GO otherwise.
        $decision = $criticalCodes !== [] ? 'NO-GO' : ($anomalyCodes !== [] ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'role' => TechnicianAssignmentEligibility::ROLE,
            'technician_count' => $technicians->count(),
            'active_technician_count' => $technicians->where('is_active', true)->count(),
            'linked_technician_count' => $technicians->whereNotNull('user_id')->count(),
            'eligible_technician_count' => $eligibleCount,
            'technicians' => $rows,
            'summary' => [
                'anomalies' => count($anomalyCodes),
                'anomaly_codes' => $anomalyCodes,
                'critical_codes' => $criticalCodes,
                'decision' => $decision,
            ],
        ];
    }

    /**
     * Guarded, transactional mapping of a master technician to an existing user
     * that already holds the Technician role. Fail-closed and idempotent.
     *
     * NEVER changes the user's role (role is a separate RBAC decision), never
     * deletes history, and refuses ambiguous links.
     *
     * @return array<string,mixed> before/after snapshot
     *
     * @throws \RuntimeException on any unsafe/ambiguous condition
     */
    public function linkUser(int|string $technicianRef, int|string $userRef, bool $apply): array
    {
        return DB::transaction(function () use ($technicianRef, $userRef, $apply): array {
            $technician = $this->resolveTechnician($technicianRef, lock: true);
            if ($technician === null) {
                throw new \RuntimeException("Technician not found: {$technicianRef}");
            }
            if (! $technician->is_active) {
                throw new \RuntimeException("Technician '{$technician->code}' is inactive; activate the master record first.");
            }

            $user = $this->resolveUser($userRef, lock: true);
            if ($user === null) {
                throw new \RuntimeException("Active user not found (or soft-deleted): {$userRef}");
            }
            if (! (bool) $user->is_active) {
                throw new \RuntimeException("User #{$user->id} is inactive; activate the account first.");
            }
            if (! $user->hasRole(TechnicianAssignmentEligibility::ROLE)) {
                // Do NOT assign the role here — role is a separate RBAC decision.
                throw new \RuntimeException(
                    "User #{$user->id} does not hold the '".TechnicianAssignmentEligibility::ROLE.
                    "' role. Assign it via role management first, then re-run this command."
                );
            }

            // Ambiguity guards.
            if ($technician->user_id !== null && $technician->user_id !== $user->id) {
                throw new \RuntimeException(
                    "Technician '{$technician->code}' is already linked to user #{$technician->user_id}; ".
                    'refusing to relink to a different user.'
                );
            }
            $conflict = Technician::query()
                ->where('is_active', true)
                ->where('user_id', $user->id)
                ->where('id', '!=', $technician->id)
                ->first();
            if ($conflict !== null) {
                throw new \RuntimeException(
                    "User #{$user->id} is already linked to active technician '{$conflict->code}' (#{$conflict->id}); ".
                    'refusing duplicate link.'
                );
            }

            $before = [
                'technician_id' => $technician->id,
                'technician_code' => $technician->code,
                'user_id' => $technician->user_id,
                'eligible' => $this->eligibility->isEligible($technician),
            ];

            $alreadyLinked = $technician->user_id === $user->id;

            // Project the link in memory so dry-run reports the resulting
            // eligibility; only persist when --apply is passed.
            $technician->user_id = $user->id;

            if ($apply && ! $alreadyLinked) {
                $technician->save();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $technician = $technician->fresh();
            }

            $after = [
                'technician_id' => $technician->id,
                'user_id' => $technician->user_id,
                'eligible' => $this->eligibility->isEligible($technician),
            ];

            return [
                'applied' => $apply && ! $alreadyLinked,
                'idempotent_no_op' => $alreadyLinked,
                'before' => $before,
                'after' => $after,
            ];
        });
    }

    private function resolveTechnician(int|string $ref, bool $lock = false): ?Technician
    {
        $query = Technician::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        if (is_int($ref) || ctype_digit((string) $ref)) {
            return $query->whereKey((int) $ref)->first();
        }

        return $query->where('code', (string) $ref)->first();
    }

    private function resolveUser(int|string $ref, bool $lock = false): ?User
    {
        $query = User::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        if (is_int($ref) || ctype_digit((string) $ref)) {
            return $query->whereKey((int) $ref)->first();
        }

        return $query->where('email', (string) $ref)->first();
    }
}
