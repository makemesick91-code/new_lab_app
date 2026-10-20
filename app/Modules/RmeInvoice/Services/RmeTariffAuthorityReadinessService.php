<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Services\BranchTariffAuthorityService;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Tariff\Support\TariffProvenance;
use App\Modules\Tariff\Support\TariffResolution;
use App\Support\Clinical\ClinicalClock;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — read-only measurement of the branch
 * tariff master: for every active RME-enabled branch, its mode, how many of the
 * ACTIVE treatments have a positive tariff in force today, which do not, and how
 * its billed lines were priced.
 *
 * REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21, revised 2026-10-20): the
 * pricing authority is MANUAL cashier entry and the tariff master is OPTIONAL
 * reference data. Everything below is INFORMATIONAL — coverage, approval
 * provenance and readiness never block or change billing. Every branch reports
 * mode MANUAL, so the former BRANCH_TRANSITIONAL WATCH no longer arises: manual
 * pricing is the policy, not a transition (D21a NOT_APPLICABLE).
 *
 * WHAT "BILLABLE" MEANS HERE. The treatment master is GLOBAL — there is no
 * per-branch list of billable treatments — so every active treatment is
 * billable in every branch. A branch is TARIFF_READY only when ALL of them have
 * a positive tariff in force; some tariffs make it TARIFF_INCOMPLETE, never ready.
 *
 * APPROVAL PROVENANCE (FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1). Coverage and
 * approval are separate questions. Every branch also reports whether each tariff
 * IN FORCE carries an owner-approval reference (VERIFIED / UNVERIFIED / NONE),
 * and `strict_eligible` is true only when the branch is TARIFF_READY AND every
 * tariff in force is VERIFIED — production TLK1's seeded placeholder prices are
 * coverage, not approval. Readiness still never changes a mode.
 *
 * Writes nothing; never changes a mode. The decision is:
 *   FAIL   (LEGACY strict only — unreachable while the source-controlled strict
 *          list is empty) a configured strict code names no active RME branch,
 *          a strict branch is not TARIFF_READY, or a strict branch has a tariff
 *          in force without approval provenance;
 *   WATCH  the optional tariff master holds a tariff in force without approval
 *          provenance (tariff-master hygiene, rule 193 — never a billing block);
 *   GO     otherwise.
 */
class RmeTariffAuthorityReadinessService
{
    public const GO = 'GO';

    public const WATCH = 'WATCH';

    public const FAIL = 'FAIL';

    public function __construct(
        private readonly BranchTariffAuthorityService $authority,
        private readonly TariffRepositoryInterface $tariffs,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly BranchService $branches,
        private readonly ClinicalClock $clinicalClock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $date = $this->clinicalClock->todayString();
        $treatments = $this->tariffs->activeTreatmentCodes();
        $rmeBranches = $this->branches->listRmeEnabled();
        $exposure = $this->invoices->priceSourceCountsByBranch(
            $rmeBranches->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $branchRows = [];
        $findings = [];

        // Resolve every branch first, then read the approval provenance of every
        // tariff in force in ONE query.
        $resolved = [];
        $inForceIds = [];
        foreach ($rmeBranches as $branch) {
            $resolved[(int) $branch->id] = $this->authority->resolveMany((int) $branch->id, array_keys($treatments), $date)->all();
            foreach ($resolved[(int) $branch->id] as $resolution) {
                if ($resolution->isResolved() && $resolution->tariffId !== null) {
                    $inForceIds[] = (int) $resolution->tariffId;
                }
            }
        }
        $references = $this->tariffs->approvalReferencesFor($inForceIds);

        foreach ($rmeBranches as $branch) {
            /** @var Branch $branch */
            $branchId = (int) $branch->id;
            $resolutions = $resolved[$branchId];

            $missing = [];
            $zero = [];
            $unverified = [];
            $tariffed = 0;
            $approved = 0;
            foreach ($treatments as $id => $code) {
                $resolution = $resolutions[$id] ?? null;
                if ($resolution?->isResolved()) {
                    $tariffed++;
                    if (($references[(int) $resolution->tariffId] ?? null) !== null) {
                        $approved++;
                    } else {
                        $unverified[] = $code;
                    }
                } elseif ($resolution?->outcome === TariffResolution::ZERO_PRICE) {
                    $zero[] = $code;
                } else {
                    $missing[] = $code;
                }
            }

            $readiness = match (true) {
                $treatments !== [] && $tariffed === count($treatments) => TariffPricingMode::READY,
                $tariffed > 0 => TariffPricingMode::INCOMPLETE,
                default => TariffPricingMode::NO_TARIFFS,
            };

            $provenance = TariffProvenance::classify($tariffed, $approved);
            $strictEligible = $readiness === TariffPricingMode::READY && $provenance === TariffProvenance::VERIFIED;

            $mode = $this->authority->modeFor($branchId);
            $strict = $mode === TariffPricingMode::STRICT_ENFORCED;
            $strictNotReady = $strict && $readiness !== TariffPricingMode::READY;

            if ($strictNotReady) {
                $findings[] = ['level' => self::FAIL, 'code' => 'STRICT_BRANCH_NOT_READY', 'branch' => (string) $branch->code];
            } elseif ($mode === TariffPricingMode::TRANSITIONAL) {
                $findings[] = ['level' => self::WATCH, 'code' => 'BRANCH_TRANSITIONAL', 'branch' => (string) $branch->code];
            }

            if ($unverified !== []) {
                $findings[] = $strict
                    ? ['level' => self::FAIL, 'code' => 'STRICT_BRANCH_TARIFF_PROVENANCE_UNVERIFIED', 'branch' => (string) $branch->code]
                    : ['level' => self::WATCH, 'code' => 'TARIFF_PROVENANCE_UNVERIFIED', 'branch' => (string) $branch->code];
            }

            $lines = $exposure[$branchId] ?? [];
            $manual = 0;
            $verified = 0;
            $transitional = 0;
            foreach ($lines as $source => $count) {
                if (in_array($source, RmeInvoicePriceSource::MANUAL, true)) {
                    $manual += $count;
                } elseif (in_array($source, RmeInvoicePriceSource::TARIFF_VERIFIED, true)) {
                    $verified += $count;
                } elseif ($source !== 'NOT_RECORDED') {
                    $transitional += $count;
                }
            }

            $branchRows[] = [
                'branch_id' => $branchId,
                'code' => (string) $branch->code,
                'name' => (string) $branch->name,
                'mode' => $mode,
                'readiness' => $readiness,
                'strict_not_ready' => $strictNotReady,
                'active_treatments' => count($treatments),
                'tariffed_treatments' => $tariffed,
                'missing_tariff_codes' => $missing,
                'zero_tariff_codes' => $zero,
                'provenance' => $provenance,
                'approved_tariffs' => $approved,
                'unverified_tariff_codes' => $unverified,
                'strict_eligible' => $strictEligible,
                'billed_lines' => array_sum($lines),
                'billed_lines_manual' => $manual,
                'billed_lines_tariff_verified' => $verified,
                'billed_lines_transitional' => $transitional,
                'billed_lines_not_recorded' => (int) ($lines['NOT_RECORDED'] ?? 0),
                'billed_lines_by_source' => $lines,
            ];
        }

        $knownCodes = $rmeBranches->map(fn (Branch $b) => BranchCodeAlias::canonicalize((string) $b->code))->filter()->all();
        foreach ($this->authority->strictBranchCodes() as $code) {
            if (! in_array($code, $knownCodes, true)) {
                $findings[] = ['level' => self::FAIL, 'code' => 'STRICT_CODE_NOT_AN_RME_BRANCH', 'branch' => $code];
            }
        }

        $levels = array_column($findings, 'level');
        $decision = in_array(self::FAIL, $levels, true) ? self::FAIL
            : (in_array(self::WATCH, $levels, true) ? self::WATCH : self::GO);

        return [
            'decision' => $decision,
            'pricing_authority' => RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY,
            'clinical_date' => $date,
            'strict_branch_codes' => $this->authority->strictBranchCodes(),
            'price_exceptions_enabled' => $this->authority->exceptionsEnabled(),
            'branches' => $branchRows,
            'findings' => $findings,
            'notes' => [
                'treatment_master_is_global' => true,
                'readiness_never_changes_mode' => true,
                'no_tariff_invented_or_copied' => true,
                'coverage_is_not_approval' => true,
                'tariff_master_is_optional_reference' => true,
                'tariff_never_blocks_billing' => true,
            ],
        ];
    }
}
