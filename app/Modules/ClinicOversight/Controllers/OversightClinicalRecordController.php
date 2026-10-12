<?php

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Requests\OversightFilterRequest;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightClinicalRecordService;
use App\Modules\ClinicOversight\Services\OversightCsvExporter;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only native RME for the
 * oversight roles: patient index, patient RM history, visit detail, print,
 * PDF, and the handwriting images those pages reference.
 *
 * GET only. Every record id is resolved THROUGH the oversight branch scope
 * first, so an out-of-scope id is a 404 that confirms nothing. The views it
 * renders carry no form, no edit button and no status control.
 *
 * It reuses the operational print templates but NOT their controllers: the
 * operational prescription print stamps `printed_at` on a GET, which an
 * oversight read must never do, so the oversight print renders the same view
 * without that write.
 */
class OversightClinicalRecordController extends Controller
{
    use AuthorizesRequests;

    /** Bound on a CSV export of the index, so an export is never unbounded. */
    public const EXPORT_LIMIT = 2000;

    public function __construct(
        private readonly OversightClinicalRecordService $records,
        private readonly OversightAuditService $audit,
        private readonly OversightCsvExporter $csv,
        private readonly ClinicOversightScope $scope,
    ) {}

    public function index(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        $filters = $request->filters();
        $user = $request->user();

        return view('oversight.rme.index', [
            ...$this->records->index($user, $filters, 20),
            'filters' => $filters,
            'branchOptions' => $this->scope->branchesFor($user, ClinicOversightScope::PERMISSION_CLINICAL),
            'sourceOptions' => UnifiedMedicalRecordSource::LABELS,
            'canExport' => $user->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL),
        ]);
    }

    public function export(OversightFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $filters = $request->filters();
        $patients = $this->records->indexForExport($request->user(), $filters, self::EXPORT_LIMIT);

        $this->audit->log(OversightAuditService::DATA_EXPORTED, null, [
            'surface' => 'rme_index',
            'format' => 'csv',
            'branch_filter' => $filters['branch_id'] ?? null,
            'date_from' => $filters['visit_date_from'] ?? null,
            'date_to' => $filters['visit_date_to'] ?? null,
            'row_count' => $patients->count(),
        ], $request->user());

        $rows = $patients->map(fn ($p) => [
            $p->medical_record_number,
            $p->name,
            $p->branch?->name,
            (int) ($p->native_count ?? 0),
            (int) ($p->legacy_rme_count ?? 0),
            (int) ($p->legacy_odontogram_count ?? 0),
            $p->last_activity_at ?? null,
        ]);

        return $this->csv->download(
            'rme-pasien-lintas-cabang-'.now()->format('Ymd').'.csv',
            ['No. Rekam Medis', 'Nama Pasien', 'Cabang Pasien', 'Lembar RME Native', 'Arsip RME Lama', 'Arsip Odontogram Lama', 'Aktivitas Terakhir'],
            $rows,
        );
    }

    /**
     * "Detail RME" (visit number) and "Riwayat RME Pasien" (Nomor RM) lookups.
     * Both resolve ONLY within the oversight scope and only on an exact match;
     * a miss renders the same "not found" whether the record is absent or out
     * of scope, so a lookup is never an existence oracle.
     */
    public function lookup(OversightFilterRequest $request): View|RedirectResponse
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        $filters = $request->filters();
        $mode = $request->query('mode') === 'patient' ? 'patient' : 'visit';
        $visitNumber = (string) ($filters['visit_number'] ?? '');
        $rmNumber = (string) ($filters['medical_record_number'] ?? '');

        if ($mode === 'visit' && $visitNumber !== '') {
            $visit = $this->records->findVisitByNumber($request->user(), $visitNumber);

            if ($visit !== null) {
                return redirect()->route('oversight.rme.visits.show', $visit->id);
            }
        }

        if ($mode === 'patient' && $rmNumber !== '') {
            $patient = $this->records->findPatientByMedicalRecordNumber($request->user(), $rmNumber);

            if ($patient !== null) {
                return redirect()->route('oversight.rme.patients.show', $patient->id);
            }
        }

        return view('oversight.rme.lookup', [
            'mode' => $mode,
            'visitNumber' => $visitNumber,
            'rmNumber' => $rmNumber,
            'notFound' => ($mode === 'visit' && $visitNumber !== '') || ($mode === 'patient' && $rmNumber !== ''),
        ]);
    }

    public function patient(Request $request, int $patient): View
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        $history = $this->records->patientHistory($request->user(), $patient);
        abort_if($history === null, 404);

        $this->audit->log(OversightAuditService::PATIENT_HISTORY_VIEWED, $patient, [
            'surface' => 'patient_history',
            'patient_id' => $patient,
        ], $request->user());

        return view('oversight.rme.patient', [
            ...$history,
            'canExport' => $request->user()->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL),
        ]);
    }

    public function visit(Request $request, int $visit): View
    {
        $clinicVisit = $this->resolveVisit($request, $visit);

        $this->audit->log(OversightAuditService::NATIVE_RECORD_VIEWED, (int) $clinicVisit->id, [
            'surface' => 'visit_detail',
            'clinic_visit_id' => (int) $clinicVisit->id,
            'patient_id' => (int) $clinicVisit->patient_id,
            'branch_id' => (int) $clinicVisit->branch_id,
        ], $request->user());

        return view('oversight.rme.visit', [
            ...$this->records->visitDetail($clinicVisit),
            'canExport' => $request->user()->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL),
        ]);
    }

    public function print(Request $request, int $visit): View
    {
        $clinicVisit = $this->resolveVisit($request, $visit);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $this->auditOutput(OversightAuditService::NATIVE_RECORD_PRINTED, $clinicVisit, 'print', $request);

        return view('rme.visits.print', [
            ...$this->records->printData($clinicVisit),
            'printPdfUrl' => route('oversight.rme.visits.pdf', $clinicVisit->id),
            'printBackUrl' => route('oversight.rme.visits.show', $clinicVisit->id),
        ]);
    }

    public function pdf(Request $request, int $visit): Response
    {
        $clinicVisit = $this->resolveVisit($request, $visit);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $this->auditOutput(OversightAuditService::NATIVE_RECORD_PDF, $clinicVisit, 'pdf', $request);

        // Generic download name: the visit number, never the patient's name.
        $filename = 'rme-visit-'.($clinicVisit->visit_number ?? $clinicVisit->id).'.pdf';

        return Pdf::loadView('rme.visits.print-pdf', $this->records->printData($clinicVisit))->download($filename);
    }

    public function odontogramPrint(Request $request, int $visit): View
    {
        $clinicVisit = $this->resolveVisit($request, $visit);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $data = $this->records->odontogramPrintData($clinicVisit);
        abort_if($data === null, 404);

        $this->auditOutput(OversightAuditService::ODONTOGRAM_PRINTED, $clinicVisit, 'print', $request);

        return view('rme.visits.odontogram.print', [
            ...$data,
            'printBackUrl' => route('oversight.rme.visits.show', $clinicVisit->id),
        ]);
    }

    /**
     * Read-only prescription print. Deliberately does NOT call the
     * prescription service's markPrinted(): the operational print stamps
     * `printed_at` on first print, and an oversight read must write nothing.
     */
    public function prescriptionPrint(Request $request, int $visit): View
    {
        $clinicVisit = $this->resolveVisit($request, $visit);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $prescription = RmePrescription::query()
            ->where('clinic_visit_id', $clinicVisit->id)
            ->with(['clinicVisit.patient', 'clinicVisit.doctor', 'clinicVisit.branch', 'doctor'])
            ->first();
        abort_if($prescription === null, 404);

        $this->auditOutput(OversightAuditService::DATA_PRINTED, $clinicVisit, 'print', $request, 'prescription');

        return view('rme.visits.prescription.print', [
            'prescription' => $prescription,
            'clinicVisit' => $prescription->clinicVisit,
            'printBackUrl' => route('oversight.rme.visits.show', $clinicVisit->id),
        ]);
    }

    public function handwritingImage(Request $request, MedicalRecordHandwriting $handwriting): StreamedResponse
    {
        $record = $handwriting->medicalRecord;
        $this->authorizeRecordImage($request, $record);

        return $this->stream($handwriting->handwriting_path, $request, $record, 'handwriting', null);
    }

    public function handwritingPageImage(Request $request, MedicalRecordHandwritingPage $handwritingPage): StreamedResponse
    {
        $record = $handwritingPage->medicalRecord;
        $this->authorizeRecordImage($request, $record);

        return $this->stream($handwritingPage->handwriting_path, $request, $record, 'page', (int) $handwritingPage->page_number);
    }

    private function resolveVisit(Request $request, int $visitId): ClinicVisit
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        $visit = $this->records->findVisit($request->user(), $visitId);
        abort_if($visit === null, 404);

        $this->authorize('clinic-oversight.view-visit', $visit);

        return $visit;
    }

    private function authorizeRecordImage(Request $request, ?MedicalRecord $record): void
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        // Out of scope is a 404, exactly like a missing record — never a 403
        // that would confirm the image exists in a branch the reader cannot see.
        abort_if($record === null || ! $request->user()->can('clinic-oversight.view-medical-record', $record), 404);
        abort_unless(
            in_array((int) $record->branch_id, $this->records->branchIdsFor($request->user()), true),
            404,
        );
    }

    private function stream(?string $path, Request $request, MedicalRecord $record, string $variant, ?int $pageNumber): StreamedResponse
    {
        abort_if($path === null || $path === '', 404);

        $headers = [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            $comma = strpos($path, ',');
            $binary = $comma === false ? '' : (string) base64_decode(substr($path, $comma + 1), true);
            abort_if($binary === '', 404);

            $this->auditStream($request, $record, $variant, $pageNumber);

            return response()->stream(function () use ($binary): void {
                echo $binary;
            }, 200, $headers);
        }

        $disk = ClinicalEvidenceStorage::disk();
        abort_unless($disk->exists($path), 404);

        $this->auditStream($request, $record, $variant, $pageNumber);

        return $disk->response($path, 'halaman-rm.png', $headers);
    }

    /**
     * Audited only once the image is known to exist (a 404 streams nothing).
     * Ids only — never the path, the patient's name or the record content.
     */
    private function auditStream(Request $request, MedicalRecord $record, string $variant, ?int $pageNumber): void
    {
        $this->audit->log(OversightAuditService::NATIVE_FILE_STREAMED, (int) $record->id, [
            'surface' => 'native_handwriting',
            'record_type' => 'medical_record',
            'record_id' => (int) $record->id,
            'clinic_visit_id' => $record->clinic_visit_id === null ? null : (int) $record->clinic_visit_id,
            'patient_id' => $record->patient_id === null ? null : (int) $record->patient_id,
            'branch_id' => $record->branch_id === null ? null : (int) $record->branch_id,
            'variant' => $variant,
            'page_number' => $pageNumber,
        ], $request->user());
    }

    private function auditOutput(string $action, ClinicVisit $visit, string $format, Request $request, string $recordType = 'native_rme'): void
    {
        $this->audit->log($action, (int) $visit->id, [
            'surface' => 'visit_output',
            'format' => $format,
            'record_type' => $recordType,
            'clinic_visit_id' => (int) $visit->id,
            'patient_id' => (int) $visit->patient_id,
            'branch_id' => (int) $visit->branch_id,
        ], $request->user());
    }
}
