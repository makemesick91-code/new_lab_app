<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Services\BranchTariffAuthorityService;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the audit vocabulary of the tariff
 * master, which is now PRICE AUTHORITY (D21): who changed which branch's price
 * of which treatment, from what to what, effective when.
 *
 * Written INSIDE the transaction that writes the tariff, so a committed change
 * always has its row. Payloads carry ids, cents, dates and flags — never the
 * free-text `notes`.
 */
final class TariffAuditEvent
{
    public const ENTITY = 'mst_tariffs';

    public const CREATED = 'RME_TARIFF_CREATED';

    public const UPDATED = 'RME_TARIFF_UPDATED';

    public const DELETED = 'RME_TARIFF_DELETED';

    public const IMPORTED = 'RME_TARIFF_IMPORTED';

    /**
     * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — an existing tariff whose price
     * already equals the owner-approved list received its approval provenance;
     * the price, date and row are untouched.
     */
    public const PROVENANCE_ATTESTED = 'RME_TARIFF_PROVENANCE_ATTESTED';

    /**
     * The approval reference is a non-PHI governance label, so it is part of the
     * state: a master-data edit that withdraws it shows reference → null.
     *
     * @return array<string, mixed>
     */
    public static function state(Tariff $tariff): array
    {
        return [
            'tariff_id' => (int) $tariff->id,
            'branch_id' => (int) $tariff->branch_id,
            'treatment_id' => (int) $tariff->treatment_id,
            'price_cents' => BranchTariffAuthorityService::toCents($tariff->price),
            'effective_date' => $tariff->effective_date?->toDateString(),
            'is_active' => (bool) $tariff->is_active,
            'approval_reference' => $tariff->hasApprovalProvenance() ? (string) $tariff->approval_reference : null,
        ];
    }
}
