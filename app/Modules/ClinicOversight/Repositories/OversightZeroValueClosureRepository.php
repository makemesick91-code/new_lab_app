<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Repositories;

use App\Modules\ClinicOversight\Interfaces\OversightZeroValueClosureRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeZeroValueCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (rule 217 §9) —
 * read-only queries over NO_CHARGE invoices. Query builder, portable across
 * PostgreSQL and SQLite (no string_agg): the per-invoice treatment list is read in
 * ONE extra query for the ids on the current page.
 */
class OversightZeroValueClosureRepository implements OversightZeroValueClosureRepositoryInterface
{
    public function paginate(array $branchIds, array $filters, string $startUtc, string $endExclusiveUtc, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->base($branchIds, $filters, $startUtc, $endExclusiveUtc)
            ->join('mst_branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'i.cashier_id')
            ->leftJoin('users as closer', 'closer.id', '=', 'i.zero_value_closed_by')
            ->leftJoin('mst_doctors as d', 'd.id', '=', 'v.doctor_id')
            ->select([
                'i.id', 'i.invoice_number', 'i.branch_id', 'i.zero_value_category', 'i.zero_value_closed_at',
                'i.cashier_id as creator_id', 'i.zero_value_closed_by as closer_id',
                'b.name as branch_name', 'b.code as branch_code',
                'creator.name as creator_name', 'closer.name as closer_name',
                'v.visit_number', 'v.visit_type', 'v.visit_date', 'v.doctor_id', 'd.name as doctor_name',
            ])
            ->orderByDesc('i.zero_value_closed_at')
            ->orderByDesc('i.id')
            ->paginate($perPage)
            ->withQueryString();

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lines = $ids === [] ? collect() : DB::table('trx_rme_invoice_items as it')
            ->leftJoin('mst_treatments as t', 't.id', '=', 'it.treatment_id')
            ->whereIn('it.rme_invoice_id', $ids)
            ->orderBy('it.id')
            ->get(['it.rme_invoice_id', 'it.treatment_id', 't.name as treatment_name'])
            ->groupBy('rme_invoice_id');

        foreach ($page->items() as $row) {
            $row->treatments = collect($lines->get($row->id, []))
                ->groupBy(fn ($line) => $line->treatment_id === null ? 'free_text' : (string) $line->treatment_id)
                ->map(fn ($group) => [
                    'treatment_id' => $group->first()->treatment_id !== null ? (int) $group->first()->treatment_id : null,
                    'name' => $group->first()->treatment_name ?? '(teks bebas)',
                    'count' => $group->count(),
                ])
                ->values()
                ->all();
            $row->creator_is_closer = $row->creator_id !== null && (int) $row->creator_id === (int) $row->closer_id;
        }

        return $page;
    }

    public function summary(array $branchIds, array $filters, string $startUtc, string $endExclusiveUtc): array
    {
        $base = fn (): Builder => $this->base($branchIds, $filters, $startUtc, $endExclusiveUtc);

        $byCategory = $base()->groupBy('i.zero_value_category')
            ->selectRaw('i.zero_value_category AS category, COUNT(*) AS n')
            ->pluck('n', 'category');

        return [
            'total' => (int) $byCategory->sum(),
            'consultations' => (int) ($byCategory[RmeZeroValueCategory::CONSULTATION] ?? 0),
            'controls' => (int) ($byCategory[RmeZeroValueCategory::CONTROL] ?? 0),
            'by_branch' => $base()->join('mst_branches as b', 'b.id', '=', 'i.branch_id')
                ->groupBy('b.id', 'b.name')
                ->selectRaw('b.id AS id, b.name AS name, COUNT(*) AS n')
                ->orderByDesc('n')->orderBy('b.name')
                ->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'count' => (int) $r->n])->all(),
            'by_closer' => $base()->leftJoin('users as closer', 'closer.id', '=', 'i.zero_value_closed_by')
                ->groupBy('i.zero_value_closed_by', 'closer.name')
                ->selectRaw('i.zero_value_closed_by AS id, closer.name AS name, COUNT(*) AS n, SUM(CASE WHEN i.cashier_id = i.zero_value_closed_by THEN 1 ELSE 0 END) AS own')
                ->orderByDesc('n')
                ->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) ($r->name ?? '-'), 'count' => (int) $r->n, 'self_issued' => (int) $r->own])->all(),
            'by_doctor' => $base()->leftJoin('mst_doctors as d', 'd.id', '=', 'v.doctor_id')
                ->groupBy('v.doctor_id', 'd.name')
                ->selectRaw('v.doctor_id AS id, d.name AS name, COUNT(*) AS n')
                ->orderByDesc('n')
                ->get()->map(fn ($r) => ['id' => $r->id !== null ? (int) $r->id : null, 'name' => (string) ($r->name ?? '-'), 'count' => (int) $r->n])->all(),
            'by_treatment' => $base()->join('trx_rme_invoice_items as it', 'it.rme_invoice_id', '=', 'i.id')
                ->leftJoin('mst_treatments as t', 't.id', '=', 'it.treatment_id')
                ->groupBy('it.treatment_id', 't.name')
                ->selectRaw('it.treatment_id AS id, t.name AS name, COUNT(*) AS items, COUNT(DISTINCT i.id) AS invoices')
                ->orderByDesc('items')
                ->get()->map(fn ($r) => ['id' => $r->id !== null ? (int) $r->id : null, 'name' => (string) ($r->name ?? '(teks bebas)'), 'items' => (int) $r->items, 'invoices' => (int) $r->invoices])->all(),
            'anomalies' => [
                // Informational, permitted by S6-D3 (no second approval): the same
                // account issued the invoice and closed it.
                'self_issued' => (int) $base()->whereColumn('i.cashier_id', 'i.zero_value_closed_by')->count(),
                // Must stay 0: NO_CHARGE means no payment row ever (soft-deleted included).
                'with_payment_rows' => (int) $base()->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('trx_rme_payments as p')->whereColumn('p.rme_invoice_id', 'i.id'))->count(),
            ],
        ];
    }

    public function filterOptions(array $branchIds): array
    {
        $scope = fn (): Builder => DB::table('trx_rme_invoices as i')
            ->join('trx_clinic_visits as v', 'v.id', '=', 'i.clinic_visit_id')
            ->where('i.status', RmeInvoice::STATUS_NO_CHARGE)
            ->whereNull('i.deleted_at')
            ->when($branchIds === [], fn (Builder $q) => $q->whereRaw('1 = 0'), fn (Builder $q) => $q->whereIn('i.branch_id', $branchIds));

        $pairs = fn ($rows) => $rows->filter(fn ($r) => $r->id !== null)
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) ($r->name ?? '-')])
            ->unique('id')->sortBy('name')->values()->all();

        return [
            'doctors' => $pairs($scope()->join('mst_doctors as d', 'd.id', '=', 'v.doctor_id')->distinct()->get(['d.id', 'd.name'])),
            'cashiers' => $pairs(collect([
                ...$scope()->join('users as u', 'u.id', '=', 'i.zero_value_closed_by')->distinct()->get(['u.id', 'u.name']),
                ...$scope()->join('users as u', 'u.id', '=', 'i.cashier_id')->distinct()->get(['u.id', 'u.name']),
            ])),
            'treatments' => $pairs($scope()->join('trx_rme_invoice_items as it', 'it.rme_invoice_id', '=', 'i.id')
                ->join('mst_treatments as t', 't.id', '=', 'it.treatment_id')->distinct()->get(['t.id', 't.name'])),
        ];
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    private function base(array $branchIds, array $filters, string $startUtc, string $endExclusiveUtc): Builder
    {
        return DB::table('trx_rme_invoices as i')
            ->join('trx_clinic_visits as v', 'v.id', '=', 'i.clinic_visit_id')
            ->where('i.status', RmeInvoice::STATUS_NO_CHARGE)
            ->whereNull('i.deleted_at')
            ->when($branchIds === [], fn (Builder $q) => $q->whereRaw('1 = 0'), fn (Builder $q) => $q->whereIn('i.branch_id', $branchIds))
            ->where('i.zero_value_closed_at', '>=', $startUtc)
            ->where('i.zero_value_closed_at', '<', $endExclusiveUtc)
            ->when($filters['category'] ?? null, fn (Builder $q, $v) => $q->where('i.zero_value_category', $v))
            ->when($filters['closer_id'] ?? null, fn (Builder $q, $v) => $q->where('i.zero_value_closed_by', (int) $v))
            ->when($filters['creator_id'] ?? null, fn (Builder $q, $v) => $q->where('i.cashier_id', (int) $v))
            ->when($filters['doctor_id'] ?? null, fn (Builder $q, $v) => $q->where('v.doctor_id', (int) $v))
            ->when($filters['treatment_id'] ?? null, fn (Builder $q, $v) => $q->whereExists(fn ($e) => $e->select(DB::raw(1))
                ->from('trx_rme_invoice_items as fi')->whereColumn('fi.rme_invoice_id', 'i.id')->where('fi.treatment_id', (int) $v)))
            // Exact references, compared case-insensitively — no LIKE, so no wildcard
            // or escape-character handling is needed (rule 130).
            ->when($filters['invoice_number'] ?? null, fn (Builder $q, $v) => $q->whereRaw('UPPER(i.invoice_number) = ?', [mb_strtoupper((string) $v)]))
            ->when($filters['visit_number'] ?? null, fn (Builder $q, $v) => $q->whereRaw('UPPER(v.visit_number) = ?', [mb_strtoupper((string) $v)]));
    }
}
