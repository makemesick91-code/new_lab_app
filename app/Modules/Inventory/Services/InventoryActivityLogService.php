<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Interfaces\InventoryActivityLogRepositoryInterface;
use App\Modules\Inventory\Models\InventoryActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryActivityLogService
{
    public function __construct(
        private readonly InventoryActivityLogRepositoryInterface $activityLogs,
        private readonly BranchContext $branchContext,
    ) {}

    public function log(
        string $action,
        Model $subject,
        array $metadata = [],
        ?string $description = null,
        ?User $user = null,
        ?string $correlationId = null,
    ): InventoryActivityLog {
        $branchId = $this->resolveBranchIdFromSubject($subject);

        return $this->persistLog(
            $branchId,
            $action,
            $subject,
            $metadata,
            $description,
            $user,
            $correlationId,
        );
    }

    public function logForBranch(
        int $branchId,
        string $action,
        Model $subject,
        array $metadata = [],
        ?string $description = null,
        ?User $user = null,
        ?string $correlationId = null,
    ): InventoryActivityLog {
        return $this->persistLog(
            $branchId,
            $action,
            $subject,
            $metadata,
            $description,
            $user,
            $correlationId,
        );
    }

    public function listForBranch(int $branchId, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);

        return $this->activityLogs->paginate($branchId, $filters, $perPage);
    }

    public function findInBranch(int $branchId, int $id): ?InventoryActivityLog
    {
        return $this->activityLogs->findInBranch($branchId, $id);
    }

    private function persistLog(
        int $branchId,
        string $action,
        Model $subject,
        array $metadata,
        ?string $description,
        ?User $user,
        ?string $correlationId,
    ): InventoryActivityLog {
        $this->assertValidAction($action);
        $this->assertValidCorrelationId($correlationId);

        unset($metadata['correlation_id']);

        [$ipAddress, $userAgent] = $this->resolveRequestContext();

        return $this->activityLogs->create([
            'branch_id' => $branchId,
            'user_id' => $user?->id ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
            'correlation_id' => $correlationId,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    private function resolveBranchIdFromSubject(Model $subject): int
    {
        $branchId = $subject->getAttribute('branch_id');

        if (is_numeric($branchId) && (int) $branchId > 0) {
            return (int) $branchId;
        }

        return $this->branchContext->requireId();
    }

    private function assertValidAction(string $action): void
    {
        if (! InventoryActivityAction::isValid($action)) {
            throw new InvalidArgumentException("Invalid inventory activity action [{$action}].");
        }
    }

    private function assertValidCorrelationId(?string $correlationId): void
    {
        if ($correlationId === null || $correlationId === '') {
            return;
        }

        if (! Str::isUuid($correlationId)) {
            throw new InvalidArgumentException('Invalid correlation_id; expected a valid UUID.');
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveRequestContext(): array
    {
        if (! app()->runningInConsole() && request()) {
            return [
                request()->ip(),
                request()->userAgent(),
            ];
        }

        return [null, null];
    }
}
