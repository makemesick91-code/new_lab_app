<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;

/**
 * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — WHERE an ON flag
 * reaches.
 *
 * `rme.invoice_correction` is a global switch. The owner approved a pilot for
 * ONE branch (SPN4, 2026-10-21), so every correction transition that can create
 * or change money — submit, approve (which applies, D1a) and the legacy apply —
 * additionally requires the invoice's branch to be on the source-controlled
 * allowlist `config/rme_invoice_correction.php`.
 *
 * FAIL CLOSED, in every direction this class can be asked:
 *   - a missing, empty or non-array list admits nothing;
 *   - MAIN is never admitted;
 *   - a listed code whose branch is inactive, deleted or not RME-enabled admits
 *     nothing (it is resolved against the live active RME set, one query);
 *   - the declared code is canonicalized (SUN4 → SPN4); the live branch must
 *     carry that canonical code EXACTLY — an alias-coded branch is never
 *     admitted, and no prefix ever matches.
 *
 * Reject and cancel are deliberately NOT gated here (rule 195 §14): they only
 * de-escalate, and they are how a correction left outside a narrowed pilot is
 * resolved before the flag is switched off.
 */
final class RmeInvoiceCorrectionPilotScope
{
    public const CONFIG_KEY = 'rme_invoice_correction.pilot.branch_codes';

    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /**
     * The canonical codes the allowlist names, de-duplicated, MAIN removed.
     * A code that names no live branch is still returned — readiness reports it.
     *
     * @return list<string>
     */
    public function declaredCodes(): array
    {
        $raw = config(self::CONFIG_KEY, []);

        if (! is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $canonical = BranchCodeAlias::canonicalize($value);
            if ($canonical === null || $canonical === Branch::MAIN_CODE) {
                continue;
            }

            $codes[$canonical] = true;
        }

        return array_keys($codes);
    }

    /**
     * Ids of the active RME-enabled branches the allowlist admits.
     *
     * @return list<int>
     */
    public function admittedBranchIds(): array
    {
        $declared = $this->declaredCodes();

        if ($declared === []) {
            return [];
        }

        $ids = [];
        foreach ($this->branches->listRmeEnabled() as $branch) {
            // Only the DECLARED side is canonicalized. The live branch must carry
            // the canonical code itself: a branch created with a historical alias
            // code (e.g. a second branch coded SUN4) must never ride on the SPN4
            // approval — widening the pilot is a reviewed commit, never a
            // master-data edit (security review L1).
            $code = BranchCodeAlias::normalize((string) $branch->code);

            if ($code !== null && $code !== Branch::MAIN_CODE && in_array($code, $declared, true)) {
                $ids[] = (int) $branch->id;
            }
        }

        sort($ids);

        return $ids;
    }

    public function admits(int $branchId): bool
    {
        return in_array($branchId, $this->admittedBranchIds(), true);
    }

    /**
     * @throws RmeInvoiceCorrectionDenied
     */
    public function assertAdmitted(int $branchId, ?int $correctionId = null, ?int $invoiceId = null): void
    {
        if (! $this->admits($branchId)) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT,
                'Koreksi tagihan belum diaktifkan untuk cabang tagihan ini.',
                $correctionId,
                $invoiceId,
            );
        }
    }
}
