<?php

namespace App\Modules\Patient\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patient\Exceptions\LegacyPatientImportBlockedException;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Requests\CancelLegacyPatientImportRequest;
use App\Modules\Patient\Requests\ConfirmLegacyPatientImportRequest;
use App\Modules\Patient\Requests\UploadLegacyPatientCsvRequest;
use App\Modules\Patient\Services\LegacyPatientImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Every action is gated by `permission:manage patients` (route group). KTP/NIK
 * is never rendered in full. No visit / medical record / invoice / consent row
 * is ever created by this controller.
 *
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — uploading is staging,
 * not importing. The confirm action is the only path that can create a patient,
 * and it refuses the WHOLE batch when any row carries a blocking error. The
 * decision lives in the service; this controller only carries the operator's
 * intent in and the service's verdict out.
 */
class LegacyPatientImportController extends Controller
{
    public function __construct(
        private readonly LegacyPatientImportService $imports,
    ) {}

    public function index(): View
    {
        return view('settings.patients.import.create', [
            'batches' => LegacyPatientImportBatch::query()
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function template(): StreamedResponse
    {
        $rows = $this->imports->templateRows();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $rows['header']);
            fputcsv($handle, $rows['example']);
            fclose($handle);
        }, $this->imports->templateFilename(), [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function store(UploadLegacyPatientCsvRequest $request): RedirectResponse
    {
        try {
            $batch = $this->imports->parseAndStage(
                $request->file('csv_file'),
                $request->user()?->id,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('settings.patients.import.index')
                ->withErrors(['csv_file' => $e->getMessage()]);
        }

        return redirect()
            ->route('settings.patients.import.show', $batch)
            ->with('status', 'Berkas legacy diunggah dan diverifikasi ke staging. Belum ada pasien yang diimpor — tinjau hasil verifikasi, lalu konfirmasi.');
    }

    public function show(Request $request, LegacyPatientImportBatch $batch): View
    {
        $rows = LegacyPatientImportRow::query()
            ->where('batch_id', $batch->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($w) use ($term) {
                    $w->where('patient_name', 'like', $term)
                        ->orWhere('generated_medical_record_number', 'like', $term);
                });
            })
            ->orderBy('row_number')
            ->paginate(25)
            ->withQueryString();

        return view('settings.patients.import.preview', [
            'batch' => $batch,
            'rows' => $rows,
            'statusFilter' => $request->string('status')->toString(),
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function errors(LegacyPatientImportBatch $batch): StreamedResponse
    {
        $header = $this->imports->errorReportHeader();
        $lines = $this->imports->errorReportRows($batch);

        return response()->streamDownload(function () use ($header, $lines) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);
            foreach ($lines as $line) {
                fputcsv($handle, $line);
            }
            fclose($handle);
        }, 'legacy-patient-import-'.$batch->id.'-report.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Confirm and import the batch — all of it, or none of it.
     *
     * The refusal path is not a 500. A blocked batch is an expected outcome of a
     * correct workflow (the operator's file had errors, or the database moved
     * while they were reading), so it redirects back to the verification they
     * need to act on, carrying the reason.
     */
    public function commit(ConfirmLegacyPatientImportRequest $request, LegacyPatientImportBatch $batch): RedirectResponse
    {
        try {
            $batch = $this->imports->commit($batch, $request->user()?->id);
        } catch (LegacyPatientImportBlockedException $e) {
            return redirect()
                ->route('settings.patients.import.show', $batch)
                ->with('import_blocked_reason', $e->reason)
                ->with('import_blocked_findings', $e->findings)
                ->withErrors(['commit' => $e->getMessage()]);
        }

        return redirect()
            ->route('settings.patients.import.show', $batch)
            ->with('status', sprintf(
                '%d pasien legacy berhasil diimpor. Seluruh baris yang disetujui masuk dalam satu transaksi.',
                $batch->committed_rows,
            ));
    }

    public function rollback(Request $request, LegacyPatientImportBatch $batch): RedirectResponse
    {
        try {
            $this->imports->rollback($batch, $request->user()?->id);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('settings.patients.import.show', $batch)
                ->withErrors(['rollback' => $e->getMessage()]);
        }

        return redirect()
            ->route('settings.patients.import.show', $batch)
            ->with('status', 'Batch di-rollback. Pasien hasil impor di-soft-delete.');
    }

    /**
     * Cancel a staged batch.
     *
     * Reached through the existing DELETE route rather than a new one: this is
     * the same operator action the route always carried, with honest semantics.
     * The batch is no longer soft-deleted out of sight — it is marked cancelled
     * and stays visible, with who cancelled it and when.
     */
    public function destroy(CancelLegacyPatientImportRequest $request, LegacyPatientImportBatch $batch): RedirectResponse
    {
        try {
            $this->imports->cancel(
                $batch,
                $request->user()?->id,
                $request->input('cancel_reason'),
            );
        } catch (RuntimeException $e) {
            return redirect()
                ->route('settings.patients.import.show', $batch)
                ->withErrors(['discard' => $e->getMessage()]);
        }

        return redirect()
            ->route('settings.patients.import.show', $batch)
            ->with('status', 'Batch dibatalkan. Tidak ada pasien yang diimpor dari batch ini dan data master pasien tidak tersentuh.');
    }
}
