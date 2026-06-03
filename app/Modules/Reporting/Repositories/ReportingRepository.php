<?php

namespace App\Modules\Reporting\Repositories;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting queries against existing Sprint 0–7 tables.
 * No mutations; filters/aggregations only (sprint_8_technical_design.md §9).
 */
class ReportingRepository implements ReportingRepositoryInterface
{
    private const VOID = 'VOID';

    // ---------------------------------------------------------------- Orders

    public function ordersQuery(array $f): Builder
    {
        return DB::table('trx_lab_orders as o')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'o.clinic_id')
            ->leftJoin('mst_doctors as d', 'd.id', '=', 'o.doctor_id')
            ->leftJoin('mst_patients as p', 'p.id', '=', 'o.patient_id')
            ->whereNull('o.deleted_at')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('o.clinic_id', $v))
            ->when($f['doctor_id'] ?? null, fn ($q, $v) => $q->where('o.doctor_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('o.status', $v))
            ->when($f['service_id'] ?? null, fn ($q, $v) => $q->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('trx_lab_order_items as it')->whereColumn('it.lab_order_id', 'o.id')->where('it.lab_service_id', $v)->whereNull('it.deleted_at')))
            ->when(true, fn ($q) => $this->dateRange($q, 'o.order_date', $f))
            ->select('o.id', 'o.order_number', 'c.name as clinic_name', 'd.name as doctor_name', 'p.name as patient_name', 'o.order_date', 'o.due_date', 'o.priority', 'o.status')
            ->orderByDesc('o.id');
    }

    public function ordersStatusSummary(array $f): Collection
    {
        return $this->ordersQuery($f)->reorder()->select('o.status', DB::raw('COUNT(*) as total'))
            ->groupBy('o.status')->get();
    }

    // ------------------------------------------------------------ Production

    public function productionQuery(array $f): Builder
    {
        return DB::table('trx_lab_order_assignments as a')
            ->leftJoin('trx_lab_orders as o', 'o.id', '=', 'a.lab_order_id')
            ->leftJoin('mst_technicians as t', 't.id', '=', 'a.technician_id')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'o.clinic_id')
            ->whereNull('a.deleted_at')
            ->when($f['technician_id'] ?? null, fn ($q, $v) => $q->where('a.technician_id', $v))
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('o.clinic_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('a.status', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'a.assigned_at', $f))
            ->select('a.id', 'o.order_number', 't.name as technician_name', 'c.name as clinic_name', 'a.status', 'a.assigned_at', 'a.completed_at')
            ->orderByDesc('a.id');
    }

    public function technicianWorkload(array $f): Collection
    {
        return DB::table('trx_lab_order_assignments as a')
            ->leftJoin('mst_technicians as t', 't.id', '=', 'a.technician_id')
            ->whereNull('a.deleted_at')
            ->when($f['technician_id'] ?? null, fn ($q, $v) => $q->where('a.technician_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'a.assigned_at', $f))
            ->select('t.name as technician_name', DB::raw('COUNT(*) as total_assignments'),
                DB::raw("SUM(CASE WHEN a.status = 'DONE' THEN 1 ELSE 0 END) as completed"))
            ->groupBy('t.name')->orderByDesc('total_assignments')->get();
    }

    // -------------------------------------------------------------------- QC

    public function qcQuery(array $f): Builder
    {
        return DB::table('trx_lab_quality_controls as q')
            ->leftJoin('trx_lab_orders as o', 'o.id', '=', 'q.lab_order_id')
            ->leftJoin('users as u', 'u.id', '=', 'q.inspected_by')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'o.clinic_id')
            ->whereNull('q.deleted_at')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('o.clinic_id', $v))
            ->when($f['qc_status'] ?? null, fn ($q, $v) => $q->where('q.result', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'q.created_at', $f))
            ->select('q.id', 'o.order_number', 'c.name as clinic_name', 'u.name as inspector_name', 'q.result', 'q.completed_at', 'q.created_at')
            ->orderByDesc('q.id');
    }

    public function qcResultSummary(array $f): Collection
    {
        return DB::table('trx_lab_quality_controls as q')
            ->whereNull('q.deleted_at')
            ->when(true, fn ($x) => $this->dateRange($x, 'q.created_at', $f))
            ->select(DB::raw("COALESCE(q.result, 'IN_REVIEW') as result"), DB::raw('COUNT(*) as total'))
            ->groupBy('q.result')->get();
    }

    public function remakeCount(array $f): int
    {
        return DB::table('trx_lab_remake_requests as r')
            ->whereNull('r.deleted_at')
            ->when(true, fn ($q) => $this->dateRange($q, 'r.requested_at', $f))
            ->count();
    }

    // -------------------------------------------------------------- Delivery

    public function deliveryQuery(array $f): Builder
    {
        return DB::table('trx_lab_deliveries as dl')
            ->leftJoin('trx_lab_orders as o', 'o.id', '=', 'dl.lab_order_id')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'o.clinic_id')
            ->leftJoin('users as u', 'u.id', '=', 'dl.courier_id')
            ->whereNull('dl.deleted_at')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('o.clinic_id', $v))
            ->when($f['courier_id'] ?? null, fn ($q, $v) => $q->where('dl.courier_id', $v))
            ->when($f['delivery_status'] ?? null, fn ($q, $v) => $q->where('dl.status', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'dl.created_at', $f))
            ->select('dl.delivery_number', 'o.order_number', 'c.name as clinic_name', 'u.name as courier_name', 'dl.status', 'dl.receiver_name', 'dl.receiver_signature_path', 'dl.completed_at', 'dl.created_at')
            ->orderByDesc('dl.id');
    }

    public function deliveryStatusSummary(array $f): Collection
    {
        return DB::table('trx_lab_deliveries as dl')
            ->whereNull('dl.deleted_at')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('trx_lab_orders as o')->whereColumn('o.id', 'dl.lab_order_id')->where('o.clinic_id', $v)))
            ->when(true, fn ($q) => $this->dateRange($q, 'dl.created_at', $f))
            ->select('dl.status', DB::raw('COUNT(*) as total'))->groupBy('dl.status')->get();
    }

    public function courierPerformance(array $f): Collection
    {
        return DB::table('trx_lab_deliveries as dl')
            ->leftJoin('users as u', 'u.id', '=', 'dl.courier_id')
            ->whereNull('dl.deleted_at')
            ->when($f['courier_id'] ?? null, fn ($q, $v) => $q->where('dl.courier_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'dl.created_at', $f))
            ->select('u.name as courier_name', DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN dl.status IN ('DELIVERED','COMPLETED') THEN 1 ELSE 0 END) as delivered"),
                DB::raw('SUM(CASE WHEN dl.receiver_signature_path IS NOT NULL THEN 1 ELSE 0 END) as pod_completed'))
            ->groupBy('u.name')->orderByDesc('total')->get();
    }

    // --------------------------------------------------------------- Invoice

    public function invoicesQuery(array $f): Builder
    {
        return DB::table('trx_invoices as i')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'i.clinic_id')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when($f['invoice_status'] ?? null, fn ($q, $v) => $q->where('i.status', $v))
            ->when(! ($f['include_void'] ?? false) && ! ($f['invoice_status'] ?? null), fn ($q) => $q->where('i.status', '!=', self::VOID))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->select('i.id', 'i.invoice_number', 'c.name as clinic_name', 'i.invoice_date', 'i.due_date', 'i.status', 'i.total_amount', 'i.paid_amount', 'i.outstanding_amount')
            ->orderByDesc('i.id');
    }

    public function invoiceStatusSummary(array $f): Collection
    {
        return DB::table('trx_invoices as i')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->select('i.status', DB::raw('COUNT(*) as total'), DB::raw('SUM(i.total_amount) as amount'))
            ->groupBy('i.status')->get();
    }

    // --------------------------------------------------------------- Payment

    public function paymentsQuery(array $f): Builder
    {
        return DB::table('trx_payments as pm')
            ->leftJoin('trx_invoices as i', 'i.id', '=', 'pm.invoice_id')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'i.clinic_id')
            ->leftJoin('users as u', 'u.id', '=', 'pm.received_by')
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when($f['payment_method'] ?? null, fn ($q, $v) => $q->where('pm.payment_method', $v))
            ->when($f['received_by'] ?? null, fn ($q, $v) => $q->where('pm.received_by', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'pm.payment_date', $f))
            ->select('pm.payment_number', 'i.invoice_number', 'c.name as clinic_name', 'pm.payment_date', 'pm.payment_method', 'pm.amount', 'u.name as received_by_name')
            ->orderByDesc('pm.id');
    }

    public function paymentsByMethod(array $f): Collection
    {
        return DB::table('trx_payments as pm')
            ->when($f['payment_method'] ?? null, fn ($q, $v) => $q->where('pm.payment_method', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'pm.payment_date', $f))
            ->select('pm.payment_method', DB::raw('COUNT(*) as total'), DB::raw('SUM(pm.amount) as amount'))
            ->groupBy('pm.payment_method')->get();
    }

    public function paymentsByUser(array $f): Collection
    {
        return DB::table('trx_payments as pm')
            ->leftJoin('users as u', 'u.id', '=', 'pm.received_by')
            ->when(true, fn ($q) => $this->dateRange($q, 'pm.payment_date', $f))
            ->select('u.name as received_by_name', DB::raw('COUNT(*) as total'), DB::raw('SUM(pm.amount) as amount'))
            ->groupBy('u.name')->orderByDesc('amount')->get();
    }

    public function paymentsTotal(array $f): float
    {
        return (float) DB::table('trx_payments as pm')
            ->when($f['payment_method'] ?? null, fn ($q, $v) => $q->where('pm.payment_method', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'pm.payment_date', $f))
            ->sum('pm.amount');
    }

    // ----------------------------------------------------------- Outstanding

    public function outstandingQuery(array $f): Builder
    {
        return DB::table('trx_invoices as i')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'i.clinic_id')
            ->where('i.status', '!=', self::VOID)
            ->where('i.outstanding_amount', '>', 0)
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->select('i.id', 'i.invoice_number', 'c.name as clinic_name', 'i.invoice_date', 'i.due_date', 'i.status', 'i.total_amount', 'i.paid_amount', 'i.outstanding_amount')
            ->orderBy('i.due_date');
    }

    // ----------------------------------------------------------- Revenue

    public function revenueRows(array $f): Collection
    {
        return DB::table('trx_invoices as i')
            ->where('i.status', '!=', self::VOID)
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->select('i.invoice_date', 'i.total_amount')->get();
    }

    public function revenueByClinic(array $f): Collection
    {
        return DB::table('trx_invoices as i')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'i.clinic_id')
            ->where('i.status', '!=', self::VOID)
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->select('c.name as clinic_name', DB::raw('SUM(i.total_amount) as amount'))
            ->groupBy('c.name')->orderByDesc('amount')->get();
    }

    public function invoiceRevenueTotal(array $f): float
    {
        return (float) DB::table('trx_invoices as i')
            ->where('i.status', '!=', self::VOID)
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->sum('i.total_amount');
    }

    public function outstandingTotal(array $f): float
    {
        return (float) DB::table('trx_invoices as i')
            ->where('i.status', '!=', self::VOID)
            ->when($f['clinic_id'] ?? null, fn ($q, $v) => $q->where('i.clinic_id', $v))
            ->when(true, fn ($q) => $this->dateRange($q, 'i.invoice_date', $f))
            ->sum('i.outstanding_amount');
    }

    // --------------------------------------------------------- Dashboard

    public function orderStatusCount(?string $status): int
    {
        return DB::table('trx_lab_orders')->whereNull('deleted_at')
            ->when($status, fn ($q, $v) => $q->where('status', $v))->count();
    }

    public function overdueInvoiceCount(): int
    {
        return DB::table('trx_invoices')
            ->where('status', '!=', self::VOID)
            ->where('outstanding_amount', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();
    }

    public function ordersByClinic(array $f): Collection
    {
        return DB::table('trx_lab_orders as o')
            ->leftJoin('mst_clinics as c', 'c.id', '=', 'o.clinic_id')
            ->whereNull('o.deleted_at')
            ->when(true, fn ($q) => $this->dateRange($q, 'o.order_date', $f))
            ->select('c.name as clinic_name', DB::raw('COUNT(*) as total'))
            ->groupBy('c.name')->orderByDesc('total')->get();
    }

    // ---------------------------------------------------------------- Helper

    /**
     * Inclusive date range filter (timezone-safe via whereDate).
     */
    private function dateRange(Builder $query, string $column, array $f): void
    {
        if (! empty($f['date_from'])) {
            $query->whereDate($column, '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $query->whereDate($column, '<=', $f['date_to']);
        }
    }
}
