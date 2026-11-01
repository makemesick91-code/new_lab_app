<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Interfaces\OversightZeroValueClosureRepositoryInterface;
use App\Modules\ClinicOversight\Requests\OversightZeroValueClosureFilterRequest;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

/**
 * HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (rule 217 §9) —
 * READ-ONLY oversight of "Selesai Tanpa Biaya" closures in the billing-correction
 * oversight area. Same gate, permission and branch scope as the other areas there
 * (rule 186): GET only, no export, no mutation, no new permission. No patient name,
 * record number or identity number is shown — invoice and visit references only.
 */
class OversightZeroValueClosureController extends Controller
{
    use AuthorizesRequests;

    private const AREA = ClinicOversightScope::PERMISSION_BILLING_CORRECTION;

    public function __construct(
        private readonly OversightZeroValueClosureRepositoryInterface $closures,
        private readonly ClinicOversightScope $scope,
        private readonly ClinicalClock $clock,
    ) {}

    public function index(OversightZeroValueClosureFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $user = $request->user();
        $filters = $request->filters();

        // Default: the last 30 clinical (WITA) days, today included.
        $range = ClinicalDateRange::tryFrom(
            $request->filled('date_from') ? (string) $request->query('date_from') : $this->clock->today()->subDays(29)->format('Y-m-d'),
            $request->filled('date_to') ? (string) $request->query('date_to') : $this->clock->today()->format('Y-m-d'),
            $this->clock,
        ) ?? ClinicalDateRange::today($this->clock);

        $authorized = $this->scope->branchIdsFor($user, self::AREA);
        $branchIds = $this->scope->narrow($authorized, $filters['branch_id']);

        return view('oversight.billing-corrections.zero-value-closures-index', [
            'rows' => $this->closures->paginate($branchIds, $filters, $range->startUtcString(), $range->endExclusiveUtcString()),
            'summary' => $this->closures->summary($branchIds, $filters, $range->startUtcString(), $range->endExclusiveUtcString()),
            'options' => $this->closures->filterOptions($authorized),
            'branchOptions' => $this->scope->branchesFor($user, self::AREA)
                ->map(fn ($branch) => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
                ->values()
                ->all(),
            'range' => $range,
            'filters' => $filters,
        ]);
    }
}
