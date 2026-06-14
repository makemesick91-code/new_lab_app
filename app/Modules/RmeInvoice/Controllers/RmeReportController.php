<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Sprint 23 Phase 23.5 — Separated RME report pages.
 *
 * RME is a multi-branch module, so both reports honour an optional branch
 * filter (RME-enabled branches only). Access is split by permission:
 *   - patients()  → view_rme_patient_reports
 *   - payments()  → view_rme_payment_reports
 * The route layer enforces these; a viewer with only one permission can never
 * reach the other page.
 */
class RmeReportController extends Controller
{
    public function patients(Request $request): View
    {
        $branchId = $this->resolveBranchId($request);

        $visits = ClinicVisit::query()
            ->with(['patient:id,name,medical_record_number', 'branch:id,name'])
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->input('status')),
                fn (Builder $q) => $q->where('status', '!=', ClinicVisit::STATUS_CANCELLED),
            )
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('visit_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('visit_date', '<=', $request->input('date_to')))
            ->when($request->filled('q'), fn (Builder $q) => $this->applyPatientSearch($q, $request->input('q')))
            ->latest('visit_date')
            ->limit(100)
            ->get();

        return view('rme.reports.patients', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $branchId,
            'filters' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'status' => $request->input('status'),
                'q' => $request->input('q'),
            ],
            'statusOptions' => $this->reportableVisitStatuses(),
            'visits' => $visits,
            'totalVisits' => $visits->count(),
        ]);
    }

    public function payments(Request $request): View
    {
        $branchId = $this->resolveBranchId($request);
        $paymentMethodId = $this->resolveMasterId($request, 'payment_method_id');
        $treatmentId = $this->resolveMasterId($request, 'treatment_id');
        $doctorId = $this->resolveMasterId($request, 'doctor_id');

        $payments = RmePayment::query()
            ->with([
                'patient:id,name,medical_record_number',
                'branch:id,name',
                'rmeInvoice:id,invoice_number,status',
                'rmeInvoice.items.treatment:id,name',
                'rmeInvoice.items.doctor:id,name',
                'paymentMethod:id,name',
                'clinicVisit.doctor:id,name',
            ])
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($paymentMethodId !== null, fn (Builder $q) => $q->where('payment_method_id', $paymentMethodId))
            ->when($treatmentId !== null, fn (Builder $q) => $q->whereHas(
                'rmeInvoice.items',
                fn (Builder $items) => $items->where('treatment_id', $treatmentId),
            ))
            ->when($doctorId !== null, fn (Builder $q) => $q->where(function (Builder $doctorQuery) use ($doctorId) {
                $doctorQuery
                    ->whereHas('clinicVisit', fn (Builder $visit) => $visit->where('doctor_id', $doctorId))
                    ->orWhereHas('rmeInvoice.items', fn (Builder $items) => $items->where('doctor_id', $doctorId));
            }))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereHas(
                'clinicVisit',
                fn (Builder $visit) => $visit->whereDate('visit_date', '>=', $request->input('date_from')),
            ))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereHas(
                'clinicVisit',
                fn (Builder $visit) => $visit->whereDate('visit_date', '<=', $request->input('date_to')),
            ))
            ->when($request->filled('q'), fn (Builder $q) => $this->applyPatientSearch($q, $request->input('q')))
            ->latest('paid_at')
            ->limit(100)
            ->get();

        return view('rme.reports.payments', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $branchId,
            'filters' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'payment_method_id' => $paymentMethodId,
                'treatment_id' => $treatmentId,
                'doctor_id' => $doctorId,
                'q' => $request->input('q'),
            ],
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'treatments' => Treatment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'payments' => $payments,
            'totalAmount' => (float) $payments->sum('amount'),
        ]);
    }

    private function applyPatientSearch(Builder $query, string $rawTerm): void
    {
        $term = '%'.strtolower(trim($rawTerm)).'%';

        $query->whereHas('patient', function (Builder $patientQuery) use ($term) {
            $patientQuery
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(medical_record_number, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(manual_rm_number, \'\')) LIKE ?', [$term])
                ->orWhereRaw('CAST(id AS TEXT) LIKE ?', [$term]);
        });
    }

    private function resolveBranchId(Request $request): ?int
    {
        if (! $request->filled('branch_id')) {
            return null;
        }

        $requested = (int) $request->input('branch_id');

        return Branch::query()
            ->where('id', $requested)
            ->where('is_active', true)
            ->rmeEnabled()
            ->value('id');
    }

    private function resolveMasterId(Request $request, string $key): ?int
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = (int) $request->input($key);

        return $value > 0 ? $value : null;
    }

    private function rmeBranches()
    {
        return Branch::query()
            ->where('is_active', true)
            ->rmeEnabled()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * @return array<string, string>
     */
    private function reportableVisitStatuses(): array
    {
        return [
            ClinicVisit::STATUS_REGISTERED => 'Terdaftar',
            ClinicVisit::STATUS_WAITING => 'Menunggu',
            ClinicVisit::STATUS_IN_PROGRESS => 'Dalam Pemeriksaan',
            ClinicVisit::STATUS_CASHIER_PENDING => 'Menunggu Kasir',
            ClinicVisit::STATUS_COMPLETED => 'Selesai',
        ];
    }
}
