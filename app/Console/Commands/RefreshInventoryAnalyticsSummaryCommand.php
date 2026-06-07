<?php

namespace App\Console\Commands;

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class RefreshInventoryAnalyticsSummaryCommand extends Command
{
    protected $signature = 'inventory:analytics-summary:refresh
                            {--branch= : Refresh only one branch_id}
                            {--date= : Refresh specific date YYYY-MM-DD}
                            {--daily : Refresh inventory daily summary only}
                            {--branch-summary : Refresh inventory branch summary only}
                            {--product-summary : Refresh inventory product summary only}
                            {--procurement : Refresh procurement daily summary only}
                            {--all : Refresh all summaries}';

    protected $description = 'Refresh read-only inventory analytics summary tables from ledger and procurement data';

    public function __construct(
        private readonly InventoryAnalyticsSummaryRefreshService $refreshService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $branchId = $this->resolveBranchId();
            $date = $this->resolveDate();
            $types = $this->resolveSummaryTypes();

            $this->info('Starting inventory analytics summary refresh...');
            $this->line('Branch target: '.($branchId !== null ? (string) $branchId : 'all active branches'));
            $this->line('Date target: '.$date);
            $this->line('Summary types: '.implode(', ', $types));

            foreach ($types as $type) {
                match ($type) {
                    'daily' => $this->refreshService->refreshDailySummaries($branchId, $date),
                    'branch-summary' => $this->refreshService->refreshBranchSummaries($branchId, $date),
                    'product-summary' => $this->refreshService->refreshProductSummaries($branchId, $date),
                    'procurement' => $this->refreshService->refreshProcurementDailySummaries($branchId, $date),
                    default => $this->refreshService->refreshAll($branchId, $date),
                };
            }

            $this->info('Inventory analytics summary refresh completed.');

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Inventory analytics summary refresh failed: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function resolveBranchId(): ?int
    {
        $branchOption = $this->option('branch');

        if ($branchOption === null || $branchOption === '') {
            return null;
        }

        if (! is_numeric($branchOption)) {
            throw new \InvalidArgumentException('Invalid branch_id. Must be a numeric branch identifier.');
        }

        $branchId = (int) $branchOption;

        $exists = Branch::query()
            ->where('id', $branchId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException("Invalid branch_id {$branchId}. Active branch not found.");
        }

        return $branchId;
    }

    private function resolveDate(): string
    {
        $dateOption = $this->option('date');

        if ($dateOption === null || $dateOption === '') {
            return now()->toDateString();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateOption)) {
            throw new \InvalidArgumentException('Invalid date format. Use YYYY-MM-DD.');
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $dateOption)->toDateString();
        } catch (Throwable) {
            throw new \InvalidArgumentException('Invalid date. Use a valid calendar date in YYYY-MM-DD format.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveSummaryTypes(): array
    {
        $flags = [
            'daily' => (bool) $this->option('daily'),
            'branch-summary' => (bool) $this->option('branch-summary'),
            'product-summary' => (bool) $this->option('product-summary'),
            'procurement' => (bool) $this->option('procurement'),
            'all' => (bool) $this->option('all'),
        ];

        $selected = array_keys(array_filter($flags));

        if ($selected === [] || in_array('all', $selected, true)) {
            return ['all'];
        }

        return $selected;
    }
}
