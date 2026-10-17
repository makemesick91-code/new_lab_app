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
use App\Modules\Tariff\Support\TariffResolution;
use App\Support\Clinical\ClinicalClock;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — read-only measurement of branch tariff
 * authority (owner D21): for every active RME-enabled branch, its mode, how many
 * of the ACTIVE treatments have a positive tariff in force today, which do not,
 * and how its billed lines were priced.
 *
 * WHAT "BILLABLE" MEANS HERE. The treatment master is GLOBAL — there is no
 * per-branch list of billable treatments — so every active treatment is
 * billable in every branch. A branch is TARIFF_READY only when ALL of them have
 * a positive tariff in force; some tariffs make it TARIFF_INCOMPLETE, never ready.
 *
 * Writes nothing; never changes a mode. The decision is:
 *   FAIL   a configured strict code names no active RME branch, or a strict
 *          branch is not TARIFF_READY (its cashiers are being refused);
 *   WATCH  any branch is still TRANSITIONAL (D21 transition is open);
 *   GO     every RME branch is strict and ready.
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

        foreach ($rmeBranches as $branch) {
            /** @var Branch $branch */
            $branchId = (int) $branch->id;
            $resolutions = $this->authority->resolveMany($branchId, array_keys($treatments), $date)->all();

            $missing = [];
            $zero = [];
            $tariffed = 0;
            foreach ($treatments as $id => $code) {
                $resolution = $resolutions[$id] ?? null;
                if ($resolution?->isResolved()) {
                    $tariffed++;
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

            $mode = $this->authority->modeFor($branchId);
            $strictNotReady = $mode === TariffPricingMode::STRICT_ENFORCED && $readiness !== TariffPricingMode::READY;

            if ($strictNotReady) {
                $findings[] = ['level' => self::FAIL, 'code' => 'STRICT_BRANCH_NOT_READY', 'branch' => (string) $branch->code];
            } elseif ($mode === TariffPricingMode::TRANSITIONAL) {
                $findings[] = ['level' => self::WATCH, 'code' => 'BRANCH_TRANSITIONAL', 'branch' => (string) $branch->code];
            }

            $lines = $exposure[$branchId] ?? [];
            $verified = 0;
            $transitional = 0;
            foreach ($lines as $source => $count) {
                if (in_array($source, RmeInvoicePriceSource::TARIFF_VERIFIED, true)) {
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
                'billed_lines' => array_sum($lines),
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
            'clinical_date' => $date,
            'strict_branch_codes' => $this->authority->strictBranchCodes(),
            'price_exceptions_enabled' => $this->authority->exceptionsEnabled(),
            'branches' => $branchRows,
            'findings' => $findings,
            'notes' => [
                'treatment_master_is_global' => true,
                'readiness_never_changes_mode' => true,
                'no_tariff_invented_or_copied' => true,
            ],
        ];
    }
}
