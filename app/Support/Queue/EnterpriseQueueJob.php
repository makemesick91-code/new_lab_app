<?php

namespace App\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ENT-5 — Base class for all future DaengtisiaMS queued jobs.
 *
 * Applies the approved enterprise retry/backoff/timeout standard from
 * config/queue_governance.php at construction time so every child job ships
 * with an explicit retry/failure policy by default. Children with their own
 * constructor must call parent::__construct() (or set the properties
 * themselves before dispatch).
 *
 * Jobs touching payments, invoices, inventory, lab candidate generation, or
 * notifications must additionally be idempotent (QUEUE-1 IdempotencyService)
 * and must never roll back a committed payment on secondary failure.
 */
abstract class EnterpriseQueueJob implements ShouldQueue
{
    use Dispatchable;
    use EnterpriseQueueRetryDefaults;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->applyEnterpriseQueueRetryDefaults();
    }
}
