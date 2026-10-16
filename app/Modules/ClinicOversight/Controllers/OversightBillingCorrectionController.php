<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Requests\OversightBillingCorrectionFilterRequest;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightBillingCorrectionService;
use App\Modules\ClinicOversight\Services\OversightCsvExporter;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Support\Clinical\ClinicalClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — BILLING CORRECTION oversight
 * (Finance Klinik / Data Analyst). GET-only, read-only.
 *
 * Three layers, as for every oversight area (rule 186): route middleware
 * (`view_billing_correction_oversight`, plus `export_oversight_data` on every
 * print/PDF/CSV route), the named `clinic-oversight.*` gate here, and the
 * ClinicOversightScope inside the service/policy. A correction outside the
 * oversight branch set is 404 — its existence is not confirmed.
 *
 * This surface has NO approve/reject/apply/cancel/refund action and no form
 * that posts anywhere. It is not gated by the `rme.invoice_correction` flag:
 * financial history stays readable after an emergency stop.
 */
class OversightBillingCorrectionController extends Controller
{
    use AuthorizesRequests;

    private const AREA = ClinicOversightScope::PERMISSION_BILLING_CORRECTION;

    private const FILTER_KEYS = ['start_date', 'end_date', 'date', 'branch_id', 'status', 'search'];

    public function __construct(
        private readonly OversightBillingCorrectionService $corrections,
        private readonly OversightAuditService $audit,
        private readonly OversightCsvExporter $csv,
        private readonly ClinicalClock $clock,
    ) {}

    public function index(OversightBillingCorrectionFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        return view('oversight.billing-corrections.index', $this->listData($request, 'history'));
    }

    public function patients(OversightBillingCorrectionFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        return view('oversight.billing-corrections.index', $this->listData($request, 'patients'));
    }

    public function show(Request $request, int $correction): View
    {
        $record = $this->resolve($request, $correction);

        $this->audit->log(OversightAuditService::BILLING_CORRECTION_VIEWED, (int) $record->id, [
            'surface' => 'billing_correction_detail',
            'record_type' => 'rme_invoice_correction',
            'record_id' => (int) $record->id,
            'branch_id' => (int) $record->branch_id,
            'patient_id' => (int) $record->patient_id,
        ], $request->user());

        return view('oversight.billing-corrections.show', [
            'correction' => $record,
            'versions' => $this->corrections->documentVersions($record),
            'canExport' => $request->user()->can('clinic-oversight.export', self::AREA),
        ]);
    }

    public function export(OversightBillingCorrectionFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', self::AREA);

        $range = $request->dateRange();
        $filters = $request->filters();
        $export = $this->corrections->exportRows($request->user(), $range, $filters);

        $this->audit->log(OversightAuditService::DATA_EXPORTED, null, [
            'surface' => 'billing_corrections',
            'format' => 'csv',
            'branch_filter' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'date_from' => $range->startDate,
            'date_to' => $range->endDate,
            'row_count' => $export['rows']->count(),
            'truncated' => $export['truncated'],
        ], $request->user());

        $rows = $export['rows']->map(fn (RmeInvoiceCorrection $c) => OversightBillingCorrectionService::csvRow($c));

        return $this->csv->download(
            'koreksi-tagihan-'.$range->startDate.'_'.$range->endDate.'.csv',
            OversightBillingCorrectionService::csvHeader(),
            $rows,
        );
    }

    public function print(OversightBillingCorrectionFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.export', self::AREA);

        $range = $request->dateRange();
        $filters = $request->filters();
        $export = $this->corrections->exportRows($request->user(), $range, $filters);

        $this->audit->log(OversightAuditService::DATA_PRINTED, null, [
            'surface' => 'billing_corrections',
            'format' => 'print',
            'branch_filter' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'date_from' => $range->startDate,
            'date_to' => $range->endDate,
            'row_count' => $export['rows']->count(),
            'truncated' => $export['truncated'],
        ], $request->user());

        return view('oversight.billing-corrections.print', [
            'rows' => $export['rows'],
            'truncated' => $export['truncated'],
            'range' => $range,
            'carriedFilters' => Arr::only($filters, self::FILTER_KEYS),
            'generatedAt' => $this->clock->now()->format('d/m/Y H:i'),
        ]);
    }

    public function showPrint(Request $request, int $correction): View
    {
        $record = $this->resolve($request, $correction);
        $this->authorize('clinic-oversight.export', self::AREA);

        $this->auditOutput(OversightAuditService::BILLING_CORRECTION_PRINTED, $record, 'print', $request);

        return view('oversight.billing-corrections.show-print', $this->printData($record) + ['forPdf' => false]);
    }

    public function showPdf(Request $request, int $correction): Response
    {
        $record = $this->resolve($request, $correction);
        $this->authorize('clinic-oversight.export', self::AREA);

        $this->auditOutput(OversightAuditService::BILLING_CORRECTION_PDF, $record, 'pdf', $request);

        // Generic download name: the correction number, never the patient's name.
        $filename = 'koreksi-tagihan-'.($record->correction_number ?? $record->id).'.pdf';

        return Pdf::loadView('oversight.billing-corrections.show-print', $this->printData($record) + ['forPdf' => true])
            ->download($filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function listData(OversightBillingCorrectionFilterRequest $request, string $mode): array
    {
        $range = $request->dateRange();
        $filters = $request->filters();
        $user = $request->user();

        return [
            'mode' => $mode,
            'rows' => $mode === 'patients'
                ? $this->corrections->patients($user, $range, $filters)
                : $this->corrections->list($user, $range, $filters),
            'range' => $range,
            'filters' => $filters,
            'carriedFilters' => Arr::only($filters, self::FILTER_KEYS),
            'branchOptions' => $this->corrections->branchOptions($user),
            'canExport' => $user->can('clinic-oversight.export', self::AREA),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function printData(RmeInvoiceCorrection $record): array
    {
        return [
            'correction' => $record,
            'versions' => $this->corrections->documentVersions($record),
            'generatedAt' => $this->clock->now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * 404 for a correction outside the oversight branch set (or unknown) — the
     * same answer, so existence is not confirmed.
     */
    private function resolve(Request $request, int $id): RmeInvoiceCorrection
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $record = $this->corrections->find($id);
        abort_if($record === null, 404);
        abort_unless($request->user()->can('clinic-oversight.view-billing-correction', $record), 404);

        return $record;
    }

    private function auditOutput(string $action, RmeInvoiceCorrection $record, string $format, Request $request): void
    {
        $this->audit->log($action, (int) $record->id, [
            'surface' => 'billing_correction_detail',
            'format' => $format,
            'record_type' => 'rme_invoice_correction',
            'record_id' => (int) $record->id,
            'branch_id' => (int) $record->branch_id,
            'patient_id' => (int) $record->patient_id,
        ], $request->user());
    }
}
