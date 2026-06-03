<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Production\Interfaces\WorkLogRepositoryInterface;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\WorkLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Creates immutable production work logs and computes active duration.
 */
class WorkLogService
{
    public function __construct(
        private readonly WorkLogRepositoryInterface $workLogs,
    ) {}

    public function forLabOrder(int $labOrderId): Collection
    {
        return $this->workLogs->forLabOrder($labOrderId);
    }

    public function record(
        LabOrderAssignment $assignment,
        string $eventType,
        ?string $notes = null,
        int $durationMinutes = 0,
        ?User $actor = null,
    ): WorkLog {
        $actor = $actor ?? auth()->user();

        return $this->workLogs->create([
            'assignment_id' => $assignment->id,
            'event_type' => $eventType,
            'started_at' => now(),
            'ended_at' => null,
            'duration_minutes' => $durationMinutes,
            'notes' => $notes,
            'performed_by' => $actor?->id,
        ]);
    }

    /**
     * Active minutes elapsed since the latest WORK_STARTED / WORK_RESUMED event.
     */
    public function activeMinutesSinceLastStart(LabOrderAssignment $assignment): int
    {
        $last = $this->workLogs->forAssignment($assignment->id)
            ->first(fn (WorkLog $log) => in_array($log->event_type, [
                WorkLog::EVENT_WORK_STARTED,
                WorkLog::EVENT_WORK_RESUMED,
            ], true));

        if (! $last || ! $last->started_at) {
            return 0;
        }

        return (int) max(0, Carbon::parse($last->started_at)->diffInMinutes(now()));
    }
}
