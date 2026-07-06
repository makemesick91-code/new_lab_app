<?php

namespace App\Support\Queue;

/**
 * ENT-5 — Approved enterprise retry/backoff/timeout defaults for queued work.
 *
 * Queued jobs/listeners either use this trait (and call
 * applyEnterpriseQueueRetryDefaults() from their constructor, or extend
 * App\Support\Queue\EnterpriseQueueJob which does it for them) or declare
 * explicit tries/backoff/timeout values. The central standard lives in
 * config/queue_governance.php (ent5_retry_failed_job.retry_standards) and is
 * enforced by foundation:queue-retry-failed-job-check.
 */
trait EnterpriseQueueRetryDefaults
{
    /** @var int|null */
    public $tries;

    /** @var array<int, int>|int|null */
    public $backoff;

    /** @var int|null */
    public $timeout;

    public function applyEnterpriseQueueRetryDefaults(): void
    {
        $standards = (array) config('queue_governance.ent5_retry_failed_job.retry_standards', []);

        $this->tries ??= (int) ($standards['default_tries'] ?? 3);
        $this->backoff ??= (array) ($standards['default_backoff_seconds'] ?? [10, 60, 180]);
        $this->timeout ??= (int) ($standards['default_timeout_seconds'] ?? 120);
    }
}
