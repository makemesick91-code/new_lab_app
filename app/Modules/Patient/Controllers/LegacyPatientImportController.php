<?php

namespace App\Modules\Patient\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
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
            ->with('status', 'File legacy berhasil diunggah dan distaging. Tinjau sebelum commit.');
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

    public function commit(Request $request, LegacyPatientImportBatch $batch): RedirectResponse
    {
        if (! $batch->committable()) {
            return redirect()
                ->route('settings.patients.import.show', $batch)
                ->withErrors(['commit' => 'Batch tidak dapat di-commit (sudah diproses atau tidak ada baris valid).']);
        }

        $batch = $this->imports->commit($batch, $request->user()?->id);

        return redirect()
            ->route('settings.patients.import.show', $batch)
            ->with('status', sprintf('%d pasien legacy berhasil diimpor.', $batch->committed_rows));
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

    public function destroy(LegacyPatientImportBatch $batch): RedirectResponse
    {
        try {
            $this->imports->discard($batch);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('settings.patients.import.show', $batch)
                ->withErrors(['discard' => $e->getMessage()]);
        }

        return redirect()
            ->route('settings.patients.import.index')
            ->with('status', 'Batch staging dibuang. Data master pasien tidak tersentuh.');
    }
}
