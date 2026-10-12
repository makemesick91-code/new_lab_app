<?php

namespace App\Modules\ClinicOversight\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicOversight\Interfaces\OversightKpiRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicalDayWindow;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Support\Clinical\ClinicalClock;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — the Finance & Data Analyst
 * dashboard. ONE implementation shared by both roles (the roles never get a
 * service, page or endpoint of their own).
 *
 * Read-only: it composes the KPI repository and writes nothing. No cache:
 * the five aggregations are grouped and index-backed, and an uncached answer
 * cannot leak one viewer's branch scope to another.
 *
 * The all-branch TOTAL is not always the arithmetic sum of the branch rows,
 * and the payload says so: a patient seen at two branches is one patient, and
 * one cashier batch that settled invoices at two branches is one transaction.
 */
class OversightDashboardService
{
    public const METRIC_VERSION = 1;

    public function __construct(
        private readonly OversightKpiRepositoryInterface $kpis,
        private readonly ClinicOversightScope $scope,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?string $requestedDate, mixed $requestedBranch): array
    {
        $day = ClinicalDayWindow::resolve($requestedDate, $this->clock);

        $authorizedBranches = $this->scope->branchesFor($user, ClinicOversightScope::PERMISSION_DASHBOARD);
        $authorizedIds = $authorizedBranches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedIds = $this->scope->narrow($authorizedIds, $requestedBranch);

        $patients = $this->kpis->checkedInPatientsByBranch($selectedIds, $day);
        $transactions = $this->kpis->transactionsByBranch($selectedIds, $day);
        $revenue = $this->kpis->revenueCentsByBranch($selectedIds, $day);
        $receivables = $this->kpis->activeReceivablesByBranch($selectedIds);
        $cancellations = $this->kpis->cancelledVisitsByBranch($selectedIds, $day);

        $rows = [];
        foreach ($authorizedBranches as $branch) {
            /** @var Branch $branch */
            $id = (int) $branch->id;
            if (! in_array($id, $selectedIds, true)) {
                continue;
            }

            $rows[] = [
                'branch_id' => $id,
                'branch_code' => (string) $branch->code,
                'branch_name' => (string) $branch->name,
                'patients' => $patients[$id] ?? 0,
                'transactions' => $transactions[$id] ?? 0,
                'revenue_cents' => $revenue[$id] ?? 0,
                'receivable_cents' => $receivables[$id]['amount_cents'] ?? 0,
                'receivable_invoices' => $receivables[$id]['invoices'] ?? 0,
                'cancellations' => $cancellations[$id] ?? 0,
            ];
        }

        $totals = [
            'patients' => $this->kpis->checkedInPatientsTotal($selectedIds, $day),
            'patients_branch_sum' => array_sum(array_column($rows, 'patients')),
            'transactions' => $this->kpis->transactionsTotal($selectedIds, $day),
            'transactions_branch_sum' => array_sum(array_column($rows, 'transactions')),
            'revenue_cents' => array_sum(array_column($rows, 'revenue_cents')),
            'receivable_cents' => array_sum(array_column($rows, 'receivable_cents')),
            'receivable_invoices' => array_sum(array_column($rows, 'receivable_invoices')),
            'cancellations' => array_sum(array_column($rows, 'cancellations')),
        ];

        return [
            'metric_version' => self::METRIC_VERSION,
            'date' => $day->date,
            'is_today' => $day->date === $this->clock->todayString(),
            'timezone' => $this->clock->timezone(),
            'generated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'selected_branch_id' => count($selectedIds) === 1 && $requestedBranch !== null && $requestedBranch !== ''
                ? $selectedIds[0]
                : null,
            'branch_filter_rejected' => $requestedBranch !== null && $requestedBranch !== '' && $selectedIds === [],
            'branches' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Branch options for the filter — exactly the server-authorized set.
     *
     * @return list<array{id:int, code:string, name:string}>
     */
    public function branchOptions(User $user): array
    {
        return $this->scope->branchesFor($user, ClinicOversightScope::PERMISSION_DASHBOARD)
            ->map(fn (Branch $b) => ['id' => (int) $b->id, 'code' => (string) $b->code, 'name' => (string) $b->name])
            ->values()
            ->all();
    }
}
