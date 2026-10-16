<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicOversight\Interfaces\OversightBillingCorrectionRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicOversight\Support\OversightFormat;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the read-only BILLING
 * CORRECTION oversight area (Finance Klinik / Data Analyst; owner-approved scope).
 *
 * BRANCH AUTHORITY is ClinicOversightScope keyed on
 * `view_billing_correction_oversight` — never RmeWorkingBranchScope. A request
 * branch filter only narrows (a foreign id yields nothing). Records come only
 * from the authoritative correction tables; nothing here is mocked, inferred or
 * written.
 *
 * WHAT A ROW SAYS AND WHAT IT DOES NOT. "Usulan" (proposed) and "Diterapkan"
 * (applied) are separate columns: an approved-but-not-applied correction never
 * shows an applied amount, and nothing here implies money moved — refund is not
 * implemented, so there is no refund column to fill.
 */
class OversightBillingCorrectionService
{
    public const EXPORT_LIMIT = 5000;

    private const AREA = ClinicOversightScope::PERMISSION_BILLING_CORRECTION;

    public function __construct(
        private readonly OversightBillingCorrectionRepositoryInterface $corrections,
        private readonly ClinicOversightScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(User $user, ClinicalDateRange $range, array $filters): LengthAwarePaginator
    {
        $branchIds = $this->branchIds($user, $filters);
        if ($branchIds === []) {
            return new Paginator([], 0, 25);
        }

        return $this->corrections->paginate($branchIds, $range->startUtcString(), $range->endExclusiveUtcString(), $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function patients(User $user, ClinicalDateRange $range, array $filters): LengthAwarePaginator
    {
        $branchIds = $this->branchIds($user, $filters);
        if ($branchIds === []) {
            return new Paginator([], 0, 25);
        }

        return $this->corrections->paginatePatients($branchIds, $range->startUtcString(), $range->endExclusiveUtcString(), $filters);
    }

    public function find(int $id): ?RmeInvoiceCorrection
    {
        return $this->corrections->find($id);
    }

    /** @return Collection<int, RmeInvoiceDocumentVersion> */
    public function documentVersions(RmeInvoiceCorrection $correction): Collection
    {
        return $this->corrections->documentVersions((int) $correction->rme_invoice_id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, RmeInvoiceCorrection>, truncated: bool}
     */
    public function exportRows(User $user, ClinicalDateRange $range, array $filters): array
    {
        $branchIds = $this->branchIds($user, $filters);
        if ($branchIds === []) {
            return ['rows' => collect(), 'truncated' => false];
        }

        $rows = $this->corrections->exportRows($branchIds, $range->startUtcString(), $range->endExclusiveUtcString(), $filters, self::EXPORT_LIMIT + 1);

        return [
            'rows' => $rows->take(self::EXPORT_LIMIT),
            'truncated' => $rows->count() > self::EXPORT_LIMIT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function csvHeader(): array
    {
        return [
            'No. Koreksi', 'Tanggal Pengajuan (WITA)', 'Cabang', 'Pasien', 'No. RM', 'No. Invoice',
            'Jenis Koreksi', 'Total Asli', 'Total Usulan', 'Total Diterapkan', 'Selisih Usulan',
            'Status Persetujuan', 'Status Penerapan', 'Pengaju', 'Pemutus', 'Waktu Diputuskan (WITA)',
            'Penerap', 'Waktu Diterapkan (WITA)',
        ];
    }

    /**
     * @return list<string|null>
     */
    public static function csvRow(RmeInvoiceCorrection $c): array
    {
        return [
            $c->correction_number,
            OversightFormat::clinicalTime($c->requested_at, 'd/m/Y H:i'),
            $c->branch?->name,
            $c->patient?->name,
            $c->patient?->medical_record_number,
            $c->invoice?->invoice_number,
            RmeInvoiceCorrectionType::label((string) $c->correction_type),
            (string) $c->original_grand_total,
            (string) $c->proposed_grand_total,
            $c->isApplied() ? (string) $c->proposed_grand_total : '',
            (string) $c->difference_amount,
            self::approvalStatus($c),
            self::applicationStatus($c),
            $c->requester?->name,
            $c->decider?->name,
            OversightFormat::clinicalTime($c->decided_at, 'd/m/Y H:i'),
            $c->applier?->name,
            OversightFormat::clinicalTime($c->applied_at, 'd/m/Y H:i'),
        ];
    }

    /** Approval axis, separated from application on purpose. */
    public static function approvalStatus(RmeInvoiceCorrection $c): string
    {
        return match ($c->status) {
            RmeInvoiceCorrectionStatus::SUBMITTED => 'Menunggu Persetujuan',
            RmeInvoiceCorrectionStatus::REJECTED => 'Ditolak',
            RmeInvoiceCorrectionStatus::CANCELLED => 'Dibatalkan Pengaju',
            RmeInvoiceCorrectionStatus::APPROVED, RmeInvoiceCorrectionStatus::APPLIED => 'Disetujui',
            RmeInvoiceCorrectionStatus::STALE => $c->decided_at !== null ? 'Disetujui' : 'Tidak Diputuskan',
            default => (string) $c->status,
        };
    }

    /** Application axis: only APPLIED means the invoice changed. */
    public static function applicationStatus(RmeInvoiceCorrection $c): string
    {
        return match ($c->status) {
            RmeInvoiceCorrectionStatus::APPLIED => 'Diterapkan',
            RmeInvoiceCorrectionStatus::STALE => 'Kedaluwarsa — Tidak Diterapkan',
            RmeInvoiceCorrectionStatus::APPROVED => 'Belum Diterapkan',
            default => 'Tidak Diterapkan',
        };
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function branchOptions(User $user): array
    {
        return $this->scope->branchesFor($user, self::AREA)
            ->map(fn (Branch $branch) => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private function branchIds(User $user, array $filters): array
    {
        return $this->scope->narrow(
            $this->scope->branchIdsFor($user, self::AREA),
            $filters['branch_id'] ?? null,
        );
    }
}
