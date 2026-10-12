<?php

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Requests\OversightFilterRequest;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightCsvExporter;
use App\Modules\ClinicOversight\Services\OversightMonitoringService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicOversight\Support\OversightFormat;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — Monitoring Dental and
 * Monitoring Antrian. GET only, no write control of any kind: no room
 * selector that saves, no queue call, no reorder, no status change.
 */
class OversightMonitoringController extends Controller
{
    use AuthorizesRequests;

    public const EXPORT_LIMIT = 2000;

    public function __construct(
        private readonly OversightMonitoringService $monitoring,
        private readonly OversightAuditService $audit,
        private readonly OversightCsvExporter $csv,
    ) {}

    public function rooms(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-monitoring');

        $filters = $request->filters();

        return view('oversight.monitoring.rooms', [
            ...$this->monitoring->roomBoard($request->user(), $filters),
            'filters' => $filters,
            'branchOptions' => $this->monitoring->branchOptions($request->user()),
        ]);
    }

    public function roomPatients(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-monitoring');

        $filters = $request->filters();
        $user = $request->user();

        return view('oversight.monitoring.room-patients', [
            'visits' => $this->monitoring->roomPatients($user, $filters),
            'day' => $this->monitoring->day($filters['date'] ?? null),
            'filters' => $filters,
            'branchOptions' => $this->monitoring->branchOptions($user),
            'roomOptions' => $this->monitoring->roomOptions($user, $filters['branch_id'] ?? null),
            'canExport' => $user->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING),
        ]);
    }

    public function roomPatientsExport(OversightFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING);

        $filters = $request->filters() + ['limit' => self::EXPORT_LIMIT];
        $visits = $this->monitoring->roomPatients($request->user(), $filters, null);
        $day = $this->monitoring->day($filters['date'] ?? null);

        $this->auditExtraction(OversightAuditService::DATA_EXPORTED, 'room_patients', 'csv', $day->date, $filters, $visits->count(), $request);

        return $this->csv->download(
            'pasien-per-ruangan-'.$day->date.'.csv',
            ['Tanggal', 'Cabang', 'Ruangan', 'No. Antrian', 'No. Kunjungan', 'No. Rekam Medis', 'Nama Pasien', 'Telepon', 'Dokter', 'Status Kunjungan', 'Check-in', 'Status RM'],
            $this->patientRows($visits),
        );
    }

    public function roomPatientsPrint(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING);

        $filters = $request->filters() + ['limit' => self::EXPORT_LIMIT];
        $visits = $this->monitoring->roomPatients($request->user(), $filters, null);
        $day = $this->monitoring->day($filters['date'] ?? null);

        $this->auditExtraction(OversightAuditService::DATA_PRINTED, 'room_patients', 'print', $day->date, $filters, $visits->count(), $request);

        return view('oversight.monitoring.print-table', [
            'title' => 'Pasien per Ruangan Dental',
            'subtitle' => 'Atribusi ruangan = ruangan yang tersimpan pada kunjungan (riwayat perpindahan ruangan tidak tercatat).',
            'date' => $day->date,
            'header' => ['Cabang', 'Ruangan', 'Antrian', 'No. Kunjungan', 'No. RM', 'Pasien', 'Telepon', 'Dokter', 'Status', 'Check-in', 'Status RM'],
            'rows' => collect($this->patientRows($visits))->map(fn ($r) => array_slice($r, 1))->all(),
        ]);
    }

    public function roomTreatments(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-monitoring');

        $filters = $request->filters();
        $user = $request->user();

        return view('oversight.monitoring.room-treatments', [
            'items' => $this->monitoring->roomTreatments($user, $filters),
            'summary' => $this->monitoring->roomTreatmentSummary($user, $filters),
            'day' => $this->monitoring->day($filters['date'] ?? null),
            'filters' => $filters,
            'branchOptions' => $this->monitoring->branchOptions($user),
            'roomOptions' => $this->monitoring->roomOptions($user, $filters['branch_id'] ?? null),
            'unclassifiedLabel' => OversightMonitoringService::UNCLASSIFIED_TREATMENT_LABEL,
            'canExport' => $user->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING),
        ]);
    }

    public function roomTreatmentsExport(OversightFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING);

        $filters = $request->filters() + ['limit' => self::EXPORT_LIMIT];
        $items = $this->monitoring->roomTreatments($request->user(), $filters, null);
        $day = $this->monitoring->day($filters['date'] ?? null);

        $this->auditExtraction(OversightAuditService::DATA_EXPORTED, 'room_treatments', 'csv', $day->date, $filters, $items->count(), $request);

        return $this->csv->download(
            'jenis-perawatan-ditagihkan-'.$day->date.'.csv',
            ['Tanggal', 'Cabang', 'Ruangan', 'No. Kunjungan', 'No. Rekam Medis', 'Nama Pasien', 'Dokter', 'Jenis Perawatan Ditagihkan', 'Keterangan Item', 'Qty', 'Nilai Ditagihkan', 'No. Invoice', 'Status Invoice'],
            $this->treatmentRows($items),
        );
    }

    public function roomTreatmentsPrint(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING);

        $filters = $request->filters() + ['limit' => self::EXPORT_LIMIT];
        $items = $this->monitoring->roomTreatments($request->user(), $filters, null);
        $day = $this->monitoring->day($filters['date'] ?? null);

        $this->auditExtraction(OversightAuditService::DATA_PRINTED, 'room_treatments', 'print', $day->date, $filters, $items->count(), $request);

        return view('oversight.monitoring.print-table', [
            'title' => 'Jenis Perawatan Ditagihkan per Ruangan Dental',
            'subtitle' => 'Sumber: item invoice RME yang diterbitkan (bukan verifikasi tindakan klinis yang selesai). Invoice VOID dan DRAFT tidak dihitung.',
            'date' => $day->date,
            'header' => ['Cabang', 'Ruangan', 'No. Kunjungan', 'No. RM', 'Pasien', 'Dokter', 'Jenis Perawatan Ditagihkan', 'Keterangan', 'Qty', 'Nilai', 'Invoice', 'Status'],
            'rows' => collect($this->treatmentRows($items))->map(fn ($r) => array_slice($r, 1))->all(),
        ]);
    }

    public function queue(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-monitoring');

        $filters = $request->filters();
        $user = $request->user();

        return view('oversight.monitoring.queue', [
            'visits' => $this->monitoring->queue($user, $filters),
            'byBranch' => $this->monitoring->queueByBranch($user, $filters),
            'day' => $this->monitoring->day($filters['date'] ?? null),
            'filters' => $filters,
            'branchOptions' => $this->monitoring->branchOptions($user),
            'statusLabels' => OversightMonitoringService::STATUS_LABELS,
            'perBranch' => false,
            'canExport' => $user->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING),
        ]);
    }

    public function queueByBranch(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-monitoring');

        $filters = $request->filters();
        $user = $request->user();

        return view('oversight.monitoring.queue', [
            'visits' => $this->monitoring->queue($user, $filters, 100),
            'byBranch' => $this->monitoring->queueByBranch($user, $filters),
            'day' => $this->monitoring->day($filters['date'] ?? null),
            'filters' => $filters,
            'branchOptions' => $this->monitoring->branchOptions($user),
            'statusLabels' => OversightMonitoringService::STATUS_LABELS,
            'perBranch' => true,
            'canExport' => $user->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING),
        ]);
    }

    public function queueExport(OversightFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING);

        $filters = $request->filters() + ['limit' => self::EXPORT_LIMIT];
        $visits = $this->monitoring->queue($request->user(), $filters, null);
        $day = $this->monitoring->day($filters['date'] ?? null);

        $this->auditExtraction(OversightAuditService::DATA_EXPORTED, 'queue', 'csv', $day->date, $filters, $visits->count(), $request);

        return $this->csv->download(
            'antrian-'.$day->date.'.csv',
            ['Tanggal', 'Cabang', 'No. Antrian', 'No. Kunjungan', 'No. Rekam Medis', 'Nama Pasien', 'Ruangan', 'Dokter', 'Status', 'Check-in'],
            $this->queueRows($visits),
        );
    }

    public function queuePrint(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_MONITORING);

        $filters = $request->filters() + ['limit' => self::EXPORT_LIMIT];
        $visits = $this->monitoring->queue($request->user(), $filters, null);
        $day = $this->monitoring->day($filters['date'] ?? null);

        $this->auditExtraction(OversightAuditService::DATA_PRINTED, 'queue', 'print', $day->date, $filters, $visits->count(), $request);

        return view('oversight.monitoring.print-table', [
            'title' => 'Monitoring Antrian',
            'subtitle' => 'Antrian kunjungan pada tanggal klinik terpilih (WITA). Kunjungan tertunda dari tanggal sebelumnya tidak termasuk.',
            'date' => $day->date,
            'header' => ['Cabang', 'Antrian', 'No. Kunjungan', 'No. RM', 'Pasien', 'Ruangan', 'Dokter', 'Status', 'Check-in'],
            'rows' => collect($this->queueRows($visits))->map(fn ($r) => array_slice($r, 1))->all(),
        ]);
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function patientRows(Collection $visits): array
    {
        return $visits->map(fn ($v) => [
            $v->visit_date?->format('Y-m-d'),
            $v->branch?->name,
            $v->clinicRoom?->name,
            $v->queue_number,
            $v->visit_number,
            $v->patient?->medical_record_number,
            $v->patient?->name,
            $v->patient?->phone ?: $v->patient?->whatsapp_number,
            $v->doctor?->name,
            OversightMonitoringService::statusLabel($v->status),
            OversightFormat::clinicalTime($v->check_in_at),
            $v->medicalRecord?->status,
        ])->values()->all();
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function treatmentRows(Collection $items): array
    {
        return $items->map(fn ($i) => [
            (string) $i->visit_date,
            $i->branch_name,
            $i->room_name,
            $i->visit_number,
            $i->medical_record_number,
            $i->patient_name,
            $i->doctor_name,
            $i->treatment_name ?? OversightMonitoringService::UNCLASSIFIED_TREATMENT_LABEL,
            $i->description,
            $i->qty,
            $i->subtotal,
            $i->invoice_number,
            $i->invoice_status,
        ])->values()->all();
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function queueRows(Collection $visits): array
    {
        return $visits->map(fn ($v) => [
            $v->visit_date?->format('Y-m-d'),
            $v->branch?->name,
            $v->queue_number,
            $v->visit_number,
            $v->patient?->medical_record_number,
            $v->patient?->name,
            $v->clinicRoom?->name,
            $v->doctor?->name,
            OversightMonitoringService::statusLabel($v->status),
            OversightFormat::clinicalTime($v->check_in_at),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function auditExtraction(string $action, string $surface, string $format, string $date, array $filters, int $count, Request $request): void
    {
        $this->audit->log($action, null, [
            'surface' => $surface,
            'format' => $format,
            'date' => $date,
            'branch_filter' => $filters['branch_id'] ?? null,
            'row_count' => $count,
        ], $request->user());
    }
}
