<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Repositories;

use App\Modules\ClinicOversight\Interfaces\OversightBillingCorrectionRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OversightBillingCorrectionRepository implements OversightBillingCorrectionRepositoryInterface
{
    public function paginate(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->base($branchIds, $fromUtc, $toUtcExclusive, $filters)
            ->with(['branch', 'patient', 'invoice', 'requester', 'decider', 'applier'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginatePatients(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $applied = RmeInvoiceCorrectionStatus::APPLIED;

        $page = $this->base($branchIds, $fromUtc, $toUtcExclusive, $filters)
            ->toBase()
            ->selectRaw('patient_id')
            ->selectRaw('COUNT(*) AS correction_count')
            ->selectRaw("SUM(CASE WHEN status = '{$applied}' THEN 1 ELSE 0 END) AS applied_count")
            ->selectRaw("SUM(CASE WHEN status = '{$applied}' THEN difference_amount ELSE 0 END) AS applied_difference")
            ->selectRaw('MAX(requested_at) AS last_requested_at')
            ->groupBy('patient_id')
            ->orderByDesc('last_requested_at')
            ->orderBy('patient_id')
            ->paginate($perPage)
            ->withQueryString();

        $patients = Patient::query()
            ->with('branch')
            ->whereIn('id', collect($page->items())->pluck('patient_id')->all())
            ->get(['id', 'name', 'medical_record_number', 'branch_id'])
            ->keyBy('id');

        $page->setCollection(collect($page->items())->map(function (object $row) use ($patients) {
            $row->patient = $patients->get((int) $row->patient_id);

            return $row;
        }));

        return $page;
    }

    public function exportRows(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters, int $limit): Collection
    {
        return $this->base($branchIds, $fromUtc, $toUtcExclusive, $filters)
            ->with(['branch', 'patient', 'invoice', 'requester', 'decider', 'applier'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::query()
            ->with([
                'branch',
                'patient',
                'invoice',
                'clinicVisit',
                'requester',
                'decider',
                'applier',
                'canceller',
                'lines.proposedTreatment',
                'lines.originalTreatment',
                'lines.proposedDoctor',
                'lines.originalDoctor',
                'events.actor',
                'revisionVersion',
            ])
            ->find($id);
    }

    public function documentVersions(int $invoiceId): Collection
    {
        return RmeInvoiceDocumentVersion::query()
            ->where('rme_invoice_id', $invoiceId)
            ->orderBy('version_number')
            ->get(['id', 'rme_invoice_id', 'branch_id', 'version_number', 'version_kind', 'document_reference', 'source_correction_id', 'issued_at', 'snapshot_sha256']);
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Builder<RmeInvoiceCorrection>
     */
    private function base(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters): Builder
    {
        return RmeInvoiceCorrection::query()
            ->whereIn('trx_rme_invoice_corrections.branch_id', $branchIds === [] ? [0] : $branchIds)
            ->where('trx_rme_invoice_corrections.requested_at', '>=', $fromUtc)
            ->where('trx_rme_invoice_corrections.requested_at', '<', $toUtcExclusive)
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('trx_rme_invoice_corrections.status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, $search) {
                $term = '%'.mb_strtolower((string) $search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->whereRaw('LOWER(trx_rme_invoice_corrections.correction_number) LIKE ?', [$term])
                        ->orWhereHas('invoice', fn (Builder $i) => $i->whereRaw('LOWER(invoice_number) LIKE ?', [$term]))
                        ->orWhereHas('patient', function (Builder $p) use ($term) {
                            $p->whereRaw('LOWER(name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term]);
                        });
                });
            });
    }
}
