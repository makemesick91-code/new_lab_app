<?php

namespace App\Support\AccessControl;

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D2 — the merged front-desk role.
 *
 * Cabang Sunu staffs one person at the front desk who both registers patients
 * and takes payment. Before D2 that meant holding two roles, or holding one and
 * being unable to do half the job. `Front Office` is their single role, carrying
 * the measured union of what `Admin Klinik` (20 permissions) and `Kasir` (6)
 * held — 21 distinct permissions, 5 of which they already shared.
 *
 * WHY A CLASS AND NOT A STRING IN ELEVEN PLACES.
 *
 * Both legacy roles are PERMISSION-OVER-GRANTED AND MENU-NARROWED: Admin Klinik
 * holds `manage_rme_billing` but the cashier menu is hidden from it, and Kasir
 * is hidden from Kunjungan, Antrian and Rekam Medis despite holding
 * `view_clinic_visits`. Eleven sites across policies, the online-context
 * resolver, the sidebar and the dashboard encode those narrowings as
 * `hasRole('Admin Klinik')` / `hasRole('Kasir')` literals.
 *
 * A role that matches NEITHER literal therefore fails OPEN at every one of
 * them — silently. Two of those failures are not cosmetic:
 *
 *   - `UserOnlineContextService::requiresAdminClinicContext()` would return
 *     false, so no online context is demanded, so `DailyBranchContextService`
 *     never sees a LOCKED role context and THE DAILY BRANCH LOCK DISAPPEARS.
 *     Measured on production 2026-09-20: users 7, 16 and 17 each locked a
 *     branch on 2026-09-19, with history across branches 1, 3 and 5. This is a
 *     feature in daily use, not a dormant one.
 *   - `BranchContext` would then fall back to `users.branch_id`, which is NULL
 *     for three of the four migrated users, and on to MAIN — which is not
 *     RME-enabled. That is the exact failure recorded in the 2026-07-10
 *     operator-mapping incident.
 *
 * So the tiers are named ONCE, here, and every site asks this class.
 *
 * THE VISIBILITY RULE, WHICH IS DERIVED RATHER THAN CHOSEN.
 *
 * Front Office sees exactly the UNION of what Admin Klinik and Kasir each saw —
 * no more. Applying that to the six menu guards gives an unambiguous answer for
 * every one, with no judgement left over:
 *
 *   Kunjungan / Antrian / Rekam Medis  hidden from Kasir, visible to AK  -> VISIBLE
 *   Kasir menu + Sinkronisasi          hidden from AK, visible to Kasir  -> VISIBLE
 *   Master Data group + Master Data RME  hidden from AK; Kasir lacks the
 *                                        permissions entirely            -> HIDDEN
 *   Audit Data Pasien                    same as above                   -> HIDDEN
 *
 * Merging two front-desk roles does not make the front desk an administrator,
 * which is why the three "hidden" rows keep their narrowing and need this class
 * while the three "visible" rows already resolve correctly without it.
 */
final class FrontOfficeRole
{
    public const NAME = 'Front Office';

    public const LEGACY_ADMIN_CLINIC = 'Admin Klinik';

    public const LEGACY_KASIR = 'Kasir';

    /**
     * The legacy roles Front Office replaces.
     *
     * OWNER DECISION, 2026-09-20 — FRONT_OFFICE_LEGACY_ROLE_POLICY =
     * KEEP_LEGACY_ROLE_DEFINITIONS_FOR_ROLLBACK_AND_COMPATIBILITY.
     *
     * "Inactive legacy role" means exactly one thing:
     *
     *     NO OPERATIONAL USER ASSIGNMENT
     *
     * and explicitly NOT any of:
     *
     *     ROLE DELETED
     *     ROLE PERMISSIONS CLEARED
     *     HISTORICAL model_has_roles DESTROYED
     *
     * So `Admin Klinik` (20 permissions) and `Kasir` (6) keep their definitions
     * and their permission grants in RoleSeeder, untouched. The rationale is
     * the owner's: it preserves the rollback path, preserves the historical
     * RBAC relationships, avoids breaking the legacy role-name checks that are
     * still live across this release, and keeps the migration blast radius
     * small immediately before the Sunu go-live.
     *
     * `Sprint66FrontOfficeLegacyPolicyTest` enforces this rather than trusting
     * the comment: stripping either role's permissions, or deleting it, fails
     * there.
     */
    public const LEGACY = [self::LEGACY_ADMIN_CLINIC, self::LEGACY_KASIR];

    /**
     * Roles resolved through the ADMIN CLINIC online context.
     *
     * Front Office deliberately reuses `UserOnlineContext::ROLE_ADMIN_CLINIC`
     * instead of gaining a context of its own. That single decision is what
     * keeps the daily branch lock working: `admin_clinic` is already inside
     * `DailyBranchContextService::LOCKED_ROLE_CONTEXTS`, already handled by
     * `resolveActiveBranchForAdmin()`, and already the context the two migrated
     * Admin Klinik users hold rows for. A new context constant would have needed
     * all three taught about it, and a miss in any one fails open.
     */
    public const ADMIN_CLINIC_CONTEXT = [self::LEGACY_ADMIN_CLINIC, self::NAME];

    /**
     * Roles for which the Master Data surfaces stay hidden.
     *
     * Front Office inherits `manage patients` and `view_clinic_master_data` from
     * Admin Klinik — the very permissions whose menus were deliberately hidden
     * from Admin Klinik. Inheriting the permission must not silently un-hide the
     * menu, so the narrowing follows the permissions to their new holder.
     */
    public const MASTER_DATA_HIDDEN = [self::LEGACY_ADMIN_CLINIC, self::NAME];

    /**
     * Roles whose clinic-visit authority is front-desk only.
     *
     * Mirrors `ClinicVisitPolicy::isFrontOfficeOnly()`, whose name already
     * described this tier a sprint before the tier had a role of its own.
     */
    public const VISIT_FRONT_DESK_ONLY = [self::LEGACY_ADMIN_CLINIC, self::NAME];
}
