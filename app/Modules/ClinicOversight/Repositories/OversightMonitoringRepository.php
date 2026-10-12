<?php

namespace App\Modules\ClinicOversight\Repositories;

use App\Modules\ClinicOversight\Interfaces\OversightMonitoringRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicalDayWindow;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only monitoring queries.
 * Rule 186 is the prose authority for the definitions below.
 *
 * DENTAL ROOM = a room of type treatment_room or consultation_room.
 * Sterilization, laboratory, x-ray and "other" rooms are never dental rooms.
 * The active-room board lists only ACTIVE dental rooms; the patient and
 * treatment lists keep a visit that sat in a dental room which has since been
 * deactivated, because the clinic day it describes really happened.
 *
 * ROOM ATTRIBUTION is the room CURRENTLY stored on the visit. No room-movement
 * history exists, so nothing here claims one.
 *
 * Every SELECT only. Soft-deleted rows are excluded explicitly in the
 * query-builder statements (the Eloquent scope covers the model queries).
 */
class OversightMonitoringRepository implements OversightMonitoringRepositoryInterface
{
    public const DENTAL_ROOM_TYPES = [
        ClinicRoom::TYPE_TREATMENT_ROOM,
        ClinicRoom::TYPE_CONSULTATION_ROOM,
    ];

    /** An issued invoice — a DRAFT is not yet billed, a VOID is not billed. */
    public const BILLED_INVOICE_STATUSES = [
        RmeInvoice::STATUS_UNPAID,
        RmeInvoice::STATUS_PARTIAL,
        RmeInvoice::STATUS_PAID,
    ];

    public const UNFINISHED_STATUSES = [
        ClinicVisit::STATUS_REGISTERED,
        ClinicVisit::STATUS_WAITING,
        ClinicVisit::STATUS_IN_PROGRESS,
        ClinicVisit::STATUS_CASHIER_PENDING,
    ];

    public function activeDentalRooms(array $branchIds, array $filters = []): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return ClinicRoom::query()
            ->with('branch')
            ->whereIn('branch_id', $branchIds)
            ->whereIn('type', self::DENTAL_ROOM_TYPES)
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->when(! empty($filters['room_id']), fn ($q) => $q->whereKey((int) $filters['room_id']))
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();
    }

    public function roomVisitStatusCounts(array $branchIds, string $date): array
    {
        if ($branchIds === []) {
            return [];
        }

        $rows = DB::table('trx_clinic_visits')
            ->whereNull('deleted_at')
            ->whereIn('branch_id', $branchIds)
            ->where('visit_date', '>=', $date)->where('visit_date', '<', ClinicalDayWindow::nextDateOf($date))
            ->whereNotNull('clinic_room_id')
            ->selectRaw('clinic_room_id, status, COUNT(*) AS total')
            ->groupBy('clinic_room_id', 'status')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->clinic_room_id][(string) $row->status] = (int) $row->total;
        }

        return $out;
    }

    public function roomPatients(array $branchIds, string $date, array $filters, ?int $perPage): LengthAwarePaginator|Collection
    {
        if ($branchIds === []) {
            return $perPage === null ? collect() : $this->emptyPage($perPage);
        }

        $query = ClinicVisit::query()
            ->with(['patient', 'doctor', 'clinicRoom', 'branch', 'medicalRecord'])
            ->whereIn('branch_id', $branchIds)
            ->where('visit_date', '>=', $date)->where('visit_date', '<', ClinicalDayWindow::nextDateOf($date))
            ->whereHas('clinicRoom', fn ($q) => $q->withTrashed()->whereIn('type', self::DENTAL_ROOM_TYPES))
            ->when(! empty($filters['room_id']), fn ($q) => $q->where('clinic_room_id', (int) $filters['room_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->tap(fn (EloquentBuilder $q) => $this->applyVisitSearch($q, $filters['search'] ?? null))
            ->orderBy('branch_id')
            ->orderBy('clinic_room_id')
            ->orderBy('queue_number');

        return $perPage === null
            ? $query->limit((int) ($filters['limit'] ?? 1000))->get()
            : $query->paginate($perPage)->withQueryString();
    }

    public function roomTreatments(array $branchIds, string $date, array $filters, ?int $perPage): LengthAwarePaginator|Collection
    {
        if ($branchIds === []) {
            return $perPage === null ? collect() : $this->emptyPage($perPage);
        }

        $query = $this->billedItems($branchIds, $date, $filters)
            ->select([
                'it.id as item_id',
                'v.id as clinic_visit_id',
                'v.visit_date',
                'v.visit_number',
                'v.queue_number',
                'b.name as branch_name',
                'b.code as branch_code',
                'r.name as room_name',
                'r.status as room_status',
                'p.name as patient_name',
                'p.medical_record_number',
                DB::raw('COALESCE(di.name, d.name) as doctor_name'),
                't.name as treatment_name',
                'it.treatment_id',
                'it.description',
                'it.qty',
                'it.unit_price',
                'it.discount',
                'it.subtotal',
                'i.invoice_number',
                'i.status as invoice_status',
            ])
            ->orderBy('b.name')
            ->orderBy('r.name')
            ->orderBy('v.queue_number')
            ->orderBy('it.id');

        return $perPage === null
            ? $query->limit((int) ($filters['limit'] ?? 2000))->get()
            : $query->paginate($perPage)->withQueryString();
    }

    public function roomTreatmentSummary(array $branchIds, string $date, array $filters): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return $this->billedItems($branchIds, $date, $filters)
            ->groupBy('b.name', 'r.id', 'r.name', 'it.treatment_id', 't.name')
            ->selectRaw('b.name AS branch_name, r.id AS room_id, r.name AS room_name, it.treatment_id AS treatment_id, t.name AS treatment_name, COUNT(*) AS item_count, SUM(it.qty) AS total_qty, SUM(it.subtotal) AS total_amount')
            ->orderBy('b.name')
            ->orderBy('r.name')
            ->orderByDesc('item_count')
            ->get();
    }

    public function queue(array $branchIds, string $date, array $filters, ?int $perPage): LengthAwarePaginator|Collection
    {
        if ($branchIds === []) {
            return $perPage === null ? collect() : $this->emptyPage($perPage);
        }

        $query = ClinicVisit::query()
            ->with(['patient', 'doctor', 'clinicRoom', 'branch'])
            ->whereIn('branch_id', $branchIds)
            ->where('visit_date', '>=', $date)->where('visit_date', '<', ClinicalDayWindow::nextDateOf($date))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->tap(fn (EloquentBuilder $q) => $this->applyVisitSearch($q, $filters['search'] ?? null))
            ->orderBy('branch_id')
            ->orderBy('queue_number');

        return $perPage === null
            ? $query->limit((int) ($filters['limit'] ?? 2000))->get()
            : $query->paginate($perPage)->withQueryString();
    }

    public function queueStatusCountsByBranch(array $branchIds, string $date): array
    {
        if ($branchIds === []) {
            return [];
        }

        $rows = DB::table('trx_clinic_visits')
            ->whereNull('deleted_at')
            ->whereIn('branch_id', $branchIds)
            ->where('visit_date', '>=', $date)->where('visit_date', '<', ClinicalDayWindow::nextDateOf($date))
            ->selectRaw('branch_id, status, COUNT(*) AS total')
            ->groupBy('branch_id', 'status')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->branch_id][(string) $row->status] = (int) $row->total;
        }

        return $out;
    }

    public function staleUnfinishedByBranch(array $branchIds, string $date): array
    {
        if ($branchIds === []) {
            return [];
        }

        return DB::table('trx_clinic_visits')
            ->whereNull('deleted_at')
            ->whereIn('branch_id', $branchIds)
            ->where('visit_date', '<', $date)
            ->whereIn('status', self::UNFINISHED_STATUSES)
            ->selectRaw('branch_id, COUNT(*) AS total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Billed invoice items of visits on the date that sat in a dental room.
     * Attributed to the visit's branch AND required to be in scope on the
     * invoice side too, so neither side can carry an out-of-scope row in.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    private function billedItems(array $branchIds, string $date, array $filters): QueryBuilder
    {
        $query = DB::table('trx_rme_invoice_items as it')
            ->join('trx_rme_invoices as i', 'i.id', '=', 'it.rme_invoice_id')
            ->join('trx_clinic_visits as v', 'v.id', '=', 'i.clinic_visit_id')
            ->join('mst_clinic_rooms as r', 'r.id', '=', 'v.clinic_room_id')
            ->join('mst_patients as p', 'p.id', '=', 'v.patient_id')
            ->join('mst_branches as b', 'b.id', '=', 'v.branch_id')
            ->leftJoin('mst_doctors as d', 'd.id', '=', 'v.doctor_id')
            ->leftJoin('mst_doctors as di', 'di.id', '=', 'it.doctor_id')
            ->leftJoin('mst_treatments as t', 't.id', '=', 'it.treatment_id')
            ->whereNull('i.deleted_at')
            ->whereNull('v.deleted_at')
            ->whereIn('i.status', self::BILLED_INVOICE_STATUSES)
            ->whereIn('v.branch_id', $branchIds)
            ->whereIn('i.branch_id', $branchIds)
            ->whereIn('r.type', self::DENTAL_ROOM_TYPES)
            ->where('v.visit_date', '>=', $date)->where('v.visit_date', '<', ClinicalDayWindow::nextDateOf($date));

        if (! empty($filters['room_id'])) {
            $query->where('v.clinic_room_id', (int) $filters['room_id']);
        }

        if (! empty($filters['treatment_id'])) {
            $filters['treatment_id'] === 'unclassified'
                ? $query->whereNull('it.treatment_id')
                : $query->where('it.treatment_id', (int) $filters['treatment_id']);
        }

        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function (QueryBuilder $q) use ($term): void {
                $q->whereRaw('LOWER(p.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(p.medical_record_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(v.visit_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(t.name, it.description)) LIKE ?', [$term]);
            });
        }

        return $query;
    }

    private function applyVisitSearch(EloquentBuilder $query, mixed $search): void
    {
        $search = is_string($search) ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = '%'.mb_strtolower($search).'%';

        $query->where(function (EloquentBuilder $q) use ($term): void {
            $q->whereRaw('LOWER(trx_clinic_visits.visit_number) LIKE ?', [$term])
                ->orWhereHas('patient', function (EloquentBuilder $p) use ($term): void {
                    $p->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term]);
                });
        });
    }

    private function emptyPage(int $perPage): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
    }
}
