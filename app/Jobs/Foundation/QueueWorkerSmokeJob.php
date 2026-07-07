<?php

namespace App\Jobs\Foundation;

use App\Support\Queue\EnterpriseQueueJob;
use Illuminate\Support\Facades\Log;

/**
 * POST-ENT — harmless queue worker smoke job.
 *
 * Proves the queue worker is actually consuming jobs end-to-end WITHOUT touching
 * any business data. It only writes one bounded, non-PII, non-secret log line.
 * It never sends WhatsApp, creates a LabOrder, mutates inventory/payments, or
 * reads patient data, so it is production-safe. Extends the ENT-5
 * EnterpriseQueueJob so it inherits the approved retry/backoff/timeout standard.
 */
class QueueWorkerSmokeJob extends EnterpriseQueueJob
{
    public function __construct(private readonly string $token = '')
    {
        parent::__construct();
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        Log::info('queue-worker-smoke: processed', [
            'token' => $this->token !== '' ? $this->token : 'smoke',
            'sprint' => 'POST-ENT-RUNTIME-HARDENING',
        ]);
    }
}
