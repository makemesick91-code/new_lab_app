<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $query = $this->patientReportQuery($request);
        $totalFilteredPatients = (clone $query)->distinct()->count('patient_id');

        $visits = (clone $query)
            ->latest('visit_date')
            ->limit(100)
            ->get();

        return view('rme.reports.patients', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $this->resolveBranchId($request),
            'filters' => $this->patientFilters($request),
            'statusOptions' => $this->reportableVisitStatuses(),
            'visits' => $visits,
            'totalFilteredPatients' => $totalFilteredPatients,
            'totalVisits' => $visits->count(),
        ]);
    }

    public function patientsExport(Request $request): StreamedResponse
    {
        $query = $this->patientReportQuery($request);
        $visits = (clone $query)->latest('visit_date')->get();
        $statusOptions = $this->reportableVisitStatuses();
        $filename = 'laporan-pasien-rme-'.now()->format('Ymd-Hi').'.csv';

        return $this->streamCsv($filename, [
            'No',
            'ID/RM Pasien',
            'Nama Pasien',
            'Tanggal Kunjungan',
            'Status',
            'Dokter',
            'Cabang',
            'Keluhan Utama',
        ], $visits->map(function (ClinicVisit $visit, int $index) use ($statusOptions) {
            return [
                $index + 1,
                $visit->patient?->medical_record_number ?? ('#'.$visit->patient_id),
                $visit->patient?->name ?? '',
                $visit->visit_date?->format('d/m/Y') ?? '',
                $statusOptions[$visit->status] ?? $visit->status,
                $visit->doctor?->name ?? '',
                $visit->branch?->name ?? '',
                $visit->chief_complaint ?? '',
            ];
        }));
    }

    public function patientsPrint(Request $request): View
    {
        $query = $this->patientReportQuery($request);
        $totalFilteredPatients = (clone $query)->distinct()->count('patient_id');
        $visits = (clone $query)->latest('visit_date')->get();

        return view('rme.reports.print.patients', [
            'filters' => $this->patientFilters($request),
            'filterSummary' => $this->buildPatientFilterSummary($request),
            'statusOptions' => $this->reportableVisitStatuses(),
            'visits' => $visits,
            'totalFilteredPatients' => $totalFilteredPatients,
            'printedAt' => now(),
        ]);
    }

    public function payments(Request $request): View
    {
        $paymentsQuery = $this->paymentReportQuery($request);
        $totalFilteredPatients = (clone $paymentsQuery)->distinct()->count('patient_id');
        $totalPaymentAmount = (float) (clone $paymentsQuery)->sum('amount');
        $totalFilteredTransactions = (clone $paymentsQuery)->count();

        $payments = (clone $paymentsQuery)
            ->with([
                'patient:id,name,medical_record_number',
                'branch:id,name',
                'rmeInvoice:id,invoice_number,status,grand_total',
                'rmeInvoice.items.treatment:id,name',
                'rmeInvoice.items.doctor:id,name',
                'paymentMethod:id,name',
                'clinicVisit:id,visit_date,doctor_id',
                'clinicVisit.doctor:id,name',
            ])
            ->latest('paid_at')
            ->limit(100)
            ->get();

        return view('rme.reports.payments', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $this->resolveBranchId($request),
            'filters' => $this->paymentFilters($request),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'treatments' => Treatment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'payments' => $payments,
            'totalAmount' => (float) $payments->sum('amount'),
            'totalPaymentAmount' => $totalPaymentAmount,
            'totalFilteredPatients' => $totalFilteredPatients,
            'totalFilteredTransactions' => $totalFilteredTransactions,
        ]);
    }

    public function paymentsExport(Request $request): StreamedResponse
    {
        $payments = (clone $this->paymentReportQuery($request))
            ->with([
                'patient:id,name,medical_record_number',
                'branch:id,name',
                'rmeInvoice:id,invoice_number,status,grand_total',
                'rmeInvoice.items.treatment:id,name',
                'rmeInvoice.items.doctor:id,name',
                'paymentMethod:id,name',
                'clinicVisit:id,visit_date,doctor_id',
                'clinicVisit.doctor:id,name',
            ])
            ->latest('paid_at')
            ->get();

        $filename = 'laporan-pembayaran-rme-'.now()->format('Ymd-Hi').'.csv';

        return $this->streamCsv($filename, [
            'No',
            'ID/RM Pasien',
            'Nama Pasien',
            'Tanggal Kunjungan',
            'Metode Pembayaran',
            'Treatment',
            'Dokter',
            'Status Invoice',
            'Total Tagihan',
            'Nominal Pembayaran',
            'Tanggal Pembayaran',
        ], $payments->map(function (RmePayment $payment, int $index) {
            return [
                $index + 1,
                $payment->patient?->medical_record_number ?? ('#'.$payment->patient_id),
                $payment->patient?->name ?? '',
                $payment->clinicVisit?->visit_date?->format('d/m/Y') ?? '',
                $payment->paymentMethod?->name ?? '',
                $this->paymentTreatmentNames($payment),
                $this->paymentDoctorNames($payment),
                $payment->rmeInvoice?->status ?? '',
                $payment->rmeInvoice?->grand_total ?? '',
                $payment->amount,
                $payment->paid_at?->format('d/m/Y H:i') ?? '',
            ];
        }));
    }

    public function paymentsPrint(Request $request): View
    {
        $paymentsQuery = $this->paymentReportQuery($request);
        $totalFilteredPatients = (clone $paymentsQuery)->distinct()->count('patient_id');
        $totalPaymentAmount = (float) (clone $paymentsQuery)->sum('amount');
        $totalFilteredTransactions = (clone $paymentsQuery)->count();

        $payments = (clone $paymentsQuery)
            ->with([
                'patient:id,name,medical_record_number',
                'branch:id,name',
                'rmeInvoice:id,invoice_number,status,grand_total',
                'rmeInvoice.items.treatment:id,name',
                'rmeInvoice.items.doctor:id,name',
                'paymentMethod:id,name',
                'clinicVisit:id,visit_date,doctor_id',
                'clinicVisit.doctor:id,name',
            ])
            ->latest('paid_at')
            ->get();

        return view('rme.reports.print.payments', [
            'filters' => $this->paymentFilters($request),
            'filterSummary' => $this->buildPaymentFilterSummary($request),
            'payments' => $payments,
            'totalFilteredPatients' => $totalFilteredPatients,
            'totalFilteredTransactions' => $totalFilteredTransactions,
            'totalPaymentAmount' => $totalPaymentAmount,
            'printedAt' => now(),
        ]);
    }

    private function patientReportQuery(Request $request): Builder
    {
        // FIX-04 — always branch-scoped; the filter narrows inside the scope.
        return ClinicVisit::query()
            ->with(['patient:id,name,medical_record_number', 'branch:id,name', 'doctor:id,name'])
            ->whereIn('branch_id', $this->reportScopeBranchIds($request))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->input('status')),
                fn (Builder $q) => $q->where('status', '!=', ClinicVisit::STATUS_CANCELLED),
            )
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('visit_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('visit_date', '<=', $request->input('date_to')))
            ->when($request->filled('q'), fn (Builder $q) => $this->applyPatientSearch($q, $request->input('q')));
    }

    private function paymentReportQuery(Request $request): Builder
    {
        $paymentMethodId = $this->resolveMasterId($request, 'payment_method_id');
        $treatmentId = $this->resolveMasterId($request, 'treatment_id');
        $doctorId = $this->resolveMasterId($request, 'doctor_id');

        // FIX-09 — always branch-scoped; list, totals and export share this scope.
        return RmePayment::query()
            ->whereIn('branch_id', $this->reportScopeBranchIds($request))
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
            ->when($request->filled('q'), fn (Builder $q) => $this->applyPatientSearch($q, $request->input('q')));
    }

    /**
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function streamCsv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            echo "\xEF\xBB\xBF";
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn ($v) => $v ?? '', (array) $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function paymentTreatmentNames(RmePayment $payment): string
    {
        return $payment->rmeInvoice?->items
            ?->pluck('treatment.name')
            ->filter()
            ->unique()
            ->values()
            ->join(', ') ?? '';
    }

    private function paymentDoctorNames(RmePayment $payment): string
    {
        return collect([$payment->clinicVisit?->doctor?->name])
            ->merge($payment->rmeInvoice?->items?->pluck('doctor.name') ?? [])
            ->filter()
            ->unique()
            ->values()
            ->join(', ');
    }

    /**
     * @return array<string, mixed>
     */
    private function patientFilters(Request $request): array
    {
        return [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'status' => $request->input('status'),
            'q' => $request->input('q'),
            'branch_id' => $this->resolveBranchId($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentFilters(Request $request): array
    {
        return [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'payment_method_id' => $this->resolveMasterId($request, 'payment_method_id'),
            'treatment_id' => $this->resolveMasterId($request, 'treatment_id'),
            'doctor_id' => $this->resolveMasterId($request, 'doctor_id'),
            'q' => $request->input('q'),
            'branch_id' => $this->resolveBranchId($request),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildPatientFilterSummary(Request $request): array
    {
        $summary = [];
        $branchId = $this->resolveBranchId($request);

        if ($branchId !== null) {
            $branchName = Branch::query()->where('id', $branchId)->value('name');
            $summary[] = 'Cabang: '.($branchName ?? $branchId);
        }

        if ($request->filled('date_from')) {
            $summary[] = 'Tanggal dari: '.$request->input('date_from');
        }

        if ($request->filled('date_to')) {
            $summary[] = 'Tanggal sampai: '.$request->input('date_to');
        }

        if ($request->filled('status')) {
            $statusOptions = $this->reportableVisitStatuses();
            $summary[] = 'Status: '.($statusOptions[$request->input('status')] ?? $request->input('status'));
        }

        if ($request->filled('q')) {
            $summary[] = 'Pencarian: '.$request->input('q');
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function buildPaymentFilterSummary(Request $request): array
    {
        $summary = $this->buildPatientFilterSummary($request);
        $paymentMethodId = $this->resolveMasterId($request, 'payment_method_id');
        $treatmentId = $this->resolveMasterId($request, 'treatment_id');
        $doctorId = $this->resolveMasterId($request, 'doctor_id');

        if ($paymentMethodId !== null) {
            $name = PaymentMethod::query()->where('id', $paymentMethodId)->value('name');
            $summary[] = 'Metode pembayaran: '.($name ?? $paymentMethodId);
        }

        if ($treatmentId !== null) {
            $name = Treatment::query()->where('id', $treatmentId)->value('name');
            $summary[] = 'Treatment: '.($name ?? $treatmentId);
        }

        if ($doctorId !== null) {
            $name = Doctor::query()->where('id', $doctorId)->value('name');
            $summary[] = 'Dokter: '.($name ?? $doctorId);
        }

        return $summary;
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

        // FIX-04/FIX-09 — a requested branch is honoured only when it is inside
        // the viewer's authorised scope; otherwise it is ignored (never widened).
        if (! app(RmeWorkingBranchScope::class)->allows($request->user(), $requested)) {
            return null;
        }

        return Branch::query()
            ->where('id', $requested)
            ->where('is_active', true)
            ->rmeEnabled()
            ->value('id');
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-04/FIX-09) — the base branch scope
     * for EVERY RME report query. Previously the branch predicate was applied
     * only when the user supplied `branch_id`, so an unfiltered report spanned
     * every branch. It is now always applied: a context-bound role (Admin
     * Klinik, Perawat, Kasir) sees only its working branch, and the same scope
     * backs the on-screen list, the totals and the CSV/print exports.
     *
     * @return array<int, int>
     */
    private function reportScopeBranchIds(Request $request): array
    {
        return app(RmeWorkingBranchScope::class)->resolve(
            $request->user(),
            $this->resolveBranchId($request),
        );
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
        $allowed = app(RmeWorkingBranchScope::class)->branchIdsFor(request()->user());

        return Branch::query()
            ->whereIn('id', $allowed)
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
