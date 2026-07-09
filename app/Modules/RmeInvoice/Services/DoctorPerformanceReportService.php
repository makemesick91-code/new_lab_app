<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FIX-PRE-68-45 Scope C — Doctor Performance / Income report.
 *
 * Read-only aggregation over the RME invoice/payment source of truth, scoped by
 * the doctor who HANDLED the visit (canonical `trx_clinic_visits.doctor_id`). The
 * "total paid" figure is exact — it sums `trx_rme_payments.amount` for the
 * doctor's visits (payments are per-invoice/per-visit, so no proportional
 * allocation is needed). Lab invoices/payments are never touched.
 *
 * Visibility tiers (server-side, IDOR-safe):
 *   - `all`  : `view_doctor_performance_report` — every doctor, all RME branches,
 *              may filter by branch + doctor.
 *   - `own`  : `view_own_doctor_performance_report` + the user is a linked doctor
 *              (`mst_doctors.user_id`) — forced to their OWN doctor_id; any
 *              requested doctor_id is ignored.
 *   - denied : anything else → the controller returns 403.
 *
 * (The branch-scoped "Kepala Cabang" tier is introduced in Phase 3 alongside the
 *  Kepala Cabang role + user→branch link; it is intentionally not built here to
 *  avoid orphan infrastructure.)
 *
 * Never renders KTP/NIK/scanned docs/raw medical notes.
 */
class DoctorPerformanceReportService
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /**
     * Resolve the caller's access tier. IDOR boundary: a doctor's own tier always
     * forces their own doctor_id regardless of the requested value.
     *
     * @return array{mode: 'all'|'own'|'denied', forced_doctor_id: int|null, can_pick_doctor: bool, can_pick_branch: bool, own_doctor: Doctor|null}
     */
    public function resolveAccess(User $user): array
    {
        if ($user->can('view_doctor_performance_report')) {
            return [
                'mode' => 'all',
                'forced_doctor_id' => null,
                'can_pick_doctor' => true,
                'can_pick_branch' => true,
                'own_doctor' => null,
            ];
        }

        $ownDoctor = Doctor::query()->where('user_id', $user->id)->first();

        if ($ownDoctor !== null && $user->can('view_own_doctor_performance_report')) {
            return [
                'mode' => 'own',
                'forced_doctor_id' => (int) $ownDoctor->id,
                'can_pick_doctor' => false,
                'can_pick_branch' => false,
                'own_doctor' => $ownDoctor,
            ];
        }

        return [
            'mode' => 'denied',
            'forced_doctor_id' => null,
            'can_pick_doctor' => false,
            'can_pick_branch' => false,
            'own_doctor' => null,
        ];
    }

    /**
     * Build the full report payload for the resolved access + filters.
     *
     * @param  array{mode: string, forced_doctor_id: int|null, can_pick_doctor: bool, can_pick_branch: bool}  $access
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $access, array $filters): array
    {
        $rmeBranchIds = $this->branches->rmeEnabledIds();

        // Branch scope: executive users may narrow to one RME branch; others see all.
        $branchIds = $rmeBranchIds;
        $selectedBranchId = null;
        if (($access['can_pick_branch'] ?? false) && ! empty($filters['branch_id'])) {
            $requested = (int) $filters['branch_id'];
            if (in_array($requested, $rmeBranchIds, true)) {
                $branchIds = [$requested];
                $selectedBranchId = $requested;
            }
        }

        // Doctor scope: forced own doctor, else the (optional) executive filter.
        $doctorId = $access['forced_doctor_id']
            ?? (($access['can_pick_doctor'] ?? false) && ! empty($filters['doctor_id']) ? (int) $filters['doctor_id'] : null);

        $from = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $to = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;
        $treatmentId = ! empty($filters['treatment_id']) ? (int) $filters['treatment_id'] : null;
        $invoiceStatus = ! empty($filters['status']) ? (string) $filters['status'] : null;

        $scope = [
            'branch_ids' => $branchIds,
            'doctor_id' => $doctorId,
            'from' => $from,
            'to' => $to,
        ];

        // A specific doctor (own tier, or executive picked one) → detail view.
        // Otherwise → per-doctor summary table.
        if ($doctorId !== null) {
            return [
                'view_mode' => 'detail',
                'selected_branch_id' => $selectedBranchId,
                'selected_doctor_id' => $doctorId,
                'doctor' => Doctor::query()->find($doctorId),
                'kpis' => $this->doctorKpis($scope),
                'treatment_breakdown' => $this->treatmentBreakdown($scope, $treatmentId, $invoiceStatus),
                'summary_rows' => [],
            ];
        }

        return [
            'view_mode' => 'summary',
            'selected_branch_id' => $selectedBranchId,
            'selected_doctor_id' => null,
            'doctor' => null,
            'kpis' => $this->doctorKpis($scope),
            'treatment_breakdown' => [],
            'summary_rows' => $this->doctorSummaryRows($scope),
        ];
    }

    /**
     * Scoped, non-cancelled clinic visits (branch + date + optional doctor).
     */
    private function scopedVisits(array $scope): Builder
    {
        return ClinicVisit::query()
            ->whereIn('branch_id', $scope['branch_ids'])
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->when($scope['doctor_id'] !== null, fn (Builder $q) => $q->where('doctor_id', $scope['doctor_id']))
            ->when($scope['from'] !== null, fn (Builder $q) => $q->where('visit_date', '>=', $scope['from']))
            ->when($scope['to'] !== null, fn (Builder $q) => $q->where('visit_date', '<=', $scope['to']));
    }

    /**
     * Visit-id subquery reused by invoice/payment aggregates (kept in SQL).
     */
    private function visitIdSubquery(array $scope): BuilderContract
    {
        return $this->scopedVisits($scope)->select('id')->getQuery();
    }

    /**
     * Headline KPIs for the scoped doctor(s): visits, distinct patients handled,
     * total billed (invoice grand_total, non-void), total paid (payment sum), and
     * outstanding. Totals key off the RME invoice/payment source of truth.
     *
     * @return array<string, int|float>
     */
    private function doctorKpis(array $scope): array
    {
        $visits = $this->scopedVisits($scope)->count();
        $patients = (clone $this->scopedVisits($scope))->distinct()->count('patient_id');

        $billed = (float) RmeInvoice::query()
            ->whereIn('clinic_visit_id', $this->visitIdSubquery($scope))
            ->where('status', '!=', RmeInvoice::STATUS_VOID)
            ->sum('grand_total');

        $paid = (float) RmePayment::query()
            ->whereIn('clinic_visit_id', $this->visitIdSubquery($scope))
            ->sum('amount');

        return [
            'visits' => $visits,
            'patients' => $patients,
            'billed' => $billed,
            'paid' => $paid,
            'outstanding' => max(0, $billed - $paid),
        ];
    }

    /**
     * Per-doctor summary rows (executive summary view). Merged in PHP by doctor_id
     * from three grouped queries (portable across PG/SQLite).
     *
     * @return array<int, array<string, mixed>>
     */
    private function doctorSummaryRows(array $scope): array
    {
        $visitAgg = $this->scopedVisits($scope)
            ->whereNotNull('doctor_id')
            ->selectRaw('doctor_id, COUNT(*) as visits, COUNT(DISTINCT patient_id) as patients')
            ->groupBy('doctor_id')
            ->get()
            ->keyBy('doctor_id');

        if ($visitAgg->isEmpty()) {
            return [];
        }

        $billedByDoctor = RmeInvoice::query()
            ->join('trx_clinic_visits', 'trx_rme_invoices.clinic_visit_id', '=', 'trx_clinic_visits.id')
            ->whereIn('trx_clinic_visits.id', $this->visitIdSubquery($scope))
            ->where('trx_rme_invoices.status', '!=', RmeInvoice::STATUS_VOID)
            ->whereNotNull('trx_clinic_visits.doctor_id')
            ->selectRaw('trx_clinic_visits.doctor_id as doctor_id, SUM(trx_rme_invoices.grand_total) as billed')
            ->groupBy('trx_clinic_visits.doctor_id')
            ->pluck('billed', 'doctor_id');

        $paidByDoctor = RmePayment::query()
            ->join('trx_clinic_visits', 'trx_rme_payments.clinic_visit_id', '=', 'trx_clinic_visits.id')
            ->whereIn('trx_clinic_visits.id', $this->visitIdSubquery($scope))
            ->whereNotNull('trx_clinic_visits.doctor_id')
            ->selectRaw('trx_clinic_visits.doctor_id as doctor_id, SUM(trx_rme_payments.amount) as paid')
            ->groupBy('trx_clinic_visits.doctor_id')
            ->pluck('paid', 'doctor_id');

        $doctors = Doctor::query()
            ->whereIn('id', $visitAgg->keys()->all())
            ->pluck('name', 'id');

        return $visitAgg->map(function ($row, $doctorId) use ($billedByDoctor, $paidByDoctor, $doctors): array {
            $billed = (float) ($billedByDoctor[$doctorId] ?? 0);
            $paid = (float) ($paidByDoctor[$doctorId] ?? 0);

            return [
                'doctor_id' => (int) $doctorId,
                'doctor_name' => $doctors[$doctorId] ?? 'Dokter #'.$doctorId,
                'visits' => (int) $row->visits,
                'patients' => (int) $row->patients,
                'billed' => $billed,
                'paid' => $paid,
                'outstanding' => max(0, $billed - $paid),
            ];
        })->sortByDesc('paid')->values()->all();
    }

    /**
     * Treatment breakdown for the scoped doctor: per treatment, how many invoice
     * items, how many are on a fully-paid invoice, and the billed subtotal.
     * Optional treatment + invoice-status filters narrow this table only.
     *
     * @return array<int, array<string, mixed>>
     */
    private function treatmentBreakdown(array $scope, ?int $treatmentId, ?string $invoiceStatus): array
    {
        $invoiceIds = RmeInvoice::query()
            ->whereIn('clinic_visit_id', $this->visitIdSubquery($scope))
            ->where('status', '!=', RmeInvoice::STATUS_VOID)
            ->when($invoiceStatus !== null, fn (Builder $q) => $q->where('status', $invoiceStatus))
            ->select('id')
            ->getQuery();

        $rows = RmeInvoiceItem::query()
            ->join('trx_rme_invoices', 'trx_rme_invoice_items.rme_invoice_id', '=', 'trx_rme_invoices.id')
            ->whereIn('trx_rme_invoice_items.rme_invoice_id', $invoiceIds)
            ->when($treatmentId !== null, fn (Builder $q) => $q->where('trx_rme_invoice_items.treatment_id', $treatmentId))
            ->selectRaw('trx_rme_invoice_items.treatment_id as treatment_id')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('SUM(CASE WHEN trx_rme_invoices.status = ? THEN 1 ELSE 0 END) as paid_item_count', [RmeInvoice::STATUS_PAID])
            ->selectRaw('SUM(trx_rme_invoice_items.subtotal) as billed')
            ->groupBy('trx_rme_invoice_items.treatment_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $treatmentNames = Treatment::query()
            ->whereIn('id', $rows->pluck('treatment_id')->filter()->all())
            ->pluck('name', 'id');

        return $rows->map(fn ($row): array => [
            'treatment_id' => $row->treatment_id !== null ? (int) $row->treatment_id : null,
            'treatment_name' => $row->treatment_id !== null
                ? ($treatmentNames[$row->treatment_id] ?? 'Tindakan #'.$row->treatment_id)
                : 'Tindakan manual / bebas',
            'item_count' => (int) $row->item_count,
            'paid_item_count' => (int) $row->paid_item_count,
            'billed' => (float) $row->billed,
        ])->sortByDesc('billed')->values()->all();
    }

    /**
     * Doctor options for the executive filter dropdown (RME-scoped, id + name).
     *
     * @return Collection<int, Doctor>
     */
    public function doctorOptions()
    {
        return Doctor::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * RME-enabled branch options for the executive filter dropdown.
     *
     * @return Collection<int, Branch>
     */
    public function branchOptions()
    {
        return Branch::query()
            ->where('is_active', true)
            ->rmeEnabled()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * Active treatment options for the treatment filter dropdown.
     *
     * @return Collection<int, Treatment>
     */
    public function treatmentOptionsForFilter()
    {
        return Treatment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Invoice status options for the payment-status filter.
     *
     * @return array<string, string>
     */
    public function invoiceStatusOptions(): array
    {
        return [
            RmeInvoice::STATUS_PAID => 'Lunas',
            RmeInvoice::STATUS_PARTIAL => 'Sebagian',
            RmeInvoice::STATUS_UNPAID => 'Belum dibayar',
        ];
    }
}
