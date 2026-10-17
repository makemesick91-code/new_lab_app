<?php

namespace App\Modules\Tariff\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Support\TariffAuditEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TariffService
{
    public function __construct(
        private readonly TariffRepositoryInterface $tariffs,
        private readonly BranchContext $branchContext,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->tariffs->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function find(int $id): ?Tariff
    {
        return $this->tariffs->findInBranch($this->branchContext->requireId(), $id);
    }

    /*
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the tariff master is now price
     * authority (D21), so every change is audited inside its own transaction.
     */

    public function create(array $data): Tariff
    {
        return DB::transaction(function () use ($data) {
            $tariff = $this->tariffs->create(array_merge($data, [
                'branch_id' => $this->branchContext->requireId(),
            ]));

            $this->auditLogs->log(TariffAuditEvent::ENTITY, (int) $tariff->id, TariffAuditEvent::CREATED, null, TariffAuditEvent::state($tariff));

            return $tariff;
        });
    }

    public function update(Tariff $tariff, array $data): Tariff
    {
        return DB::transaction(function () use ($tariff, $data) {
            $before = TariffAuditEvent::state($tariff);
            $updated = $this->tariffs->update($tariff, $data);

            $this->auditLogs->log(TariffAuditEvent::ENTITY, (int) $updated->id, TariffAuditEvent::UPDATED, $before, TariffAuditEvent::state($updated));

            return $updated;
        });
    }

    public function delete(Tariff $tariff): bool
    {
        return DB::transaction(function () use ($tariff) {
            $before = TariffAuditEvent::state($tariff);
            $deleted = $this->tariffs->delete($tariff);

            $this->auditLogs->log(TariffAuditEvent::ENTITY, (int) $tariff->id, TariffAuditEvent::DELETED, $before, null);

            return $deleted;
        });
    }
}
