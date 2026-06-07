<?php

namespace App\Modules\Inventory\Services\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

trait LogsInventoryActivity
{
    private function logActivity(
        string $action,
        Model $subject,
        array $metadata = [],
        ?string $description = null,
        ?User $user = null,
        ?string $correlationId = null,
    ): void {
        try {
            $this->activityLogger->log($action, $subject, $metadata, $description, $user, $correlationId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
