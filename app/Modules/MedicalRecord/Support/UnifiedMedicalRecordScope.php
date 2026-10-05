<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Support;

use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — the actor's read
 * envelope for the unified medical-record index, resolved server-side ONCE
 * per request.
 *
 * Each source carries its OWN canonical scope, never a shared one:
 *
 *  - native  : the RME-enabled branch set the native list always used, plus
 *              the doctor patient scope;
 *  - legacy  : the branch set {@see LegacyRmeWorkspaceScope}
 *              resolves, gated on the legacy RME read permission;
 *  - odonto  : the branch set {@see LegacyOdontogramWorkspaceScope}
 *              resolves, gated on the legacy odontogram read permission.
 *
 * Inclusion in the list can therefore never be wider than what the canonical
 * viewer for that source would let the same actor open. Nothing here is read
 * from the request.
 */
final class UnifiedMedicalRecordScope
{
    /**
     * @param  list<int>  $nativeBranchIds
     * @param  list<int>  $legacyRmeBranchIds
     * @param  list<int>  $legacyOdontogramBranchIds
     * @param  (Closure(Builder): Builder)|null  $patientScope
     */
    public function __construct(
        public readonly array $nativeBranchIds,
        public readonly bool $legacyRmeReadable,
        public readonly array $legacyRmeBranchIds,
        public readonly bool $legacyRmeIncludesUnscoped,
        public readonly bool $legacyOdontogramReadable,
        public readonly array $legacyOdontogramBranchIds,
        public readonly bool $legacyOdontogramIncludesUnscoped,
        public readonly ?Closure $patientScope = null,
    ) {}

    /** Whether the actor may see ANY legacy source at all. */
    public function anyLegacyReadable(): bool
    {
        return $this->legacyRmeVisible() || $this->legacyOdontogramVisible();
    }

    /**
     * Readable AND a non-empty branch set. Mirrors the legacy record
     * repositories' scoped(): an EMPTY id list matches nothing even for a
     * governance actor, so NULL-branch rows are never reachable on their own.
     */
    public function legacyRmeVisible(): bool
    {
        return $this->legacyRmeReadable && $this->legacyRmeBranchIds !== [];
    }

    public function legacyOdontogramVisible(): bool
    {
        return $this->legacyOdontogramReadable && $this->legacyOdontogramBranchIds !== [];
    }
}
