<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Jobs;

use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Support\Queue\EnterpriseQueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * FIX-04b — rasterize one staged legacy odontogram PDF into page images.
 *
 * Extends the ENT-5 EnterpriseQueueJob so it inherits the enforced retry /
 * backoff / timeout standard instead of declaring an ad-hoc one, and is unique
 * per import so a double dispatch cannot render the same document twice
 * concurrently and race over its page rows.
 *
 * It carries an ID, never a model: by the time the worker runs the import may
 * have been cancelled or already processed, so the service re-reads and
 * re-claims it under a row lock. Everything it does is idempotent — a re-run
 * re-renders from the same immutable source PDF.
 */
class ProcessLegacyOdontogramPdfImport extends EnterpriseQueueJob implements ShouldBeUnique
{
    public function __construct(
        public readonly int $importId,
    ) {
        parent::__construct();

        $this->onQueue($this->configuredQueue());
        $this->tries = max(1, (int) config('legacy_odontogram.processing.tries', 3));
        $this->timeout = max(60, (int) config('legacy_odontogram.processing.process_timeout', 180) + 60);
        $this->backoff = (array) config('legacy_odontogram.processing.backoff', [30, 120, 300]);
    }

    public function handle(LegacyOdontogramProcessingService $processing): void
    {
        $processing->process($this->importId);
    }

    public function uniqueId(): string
    {
        return 'legacy-odontogram-import:'.$this->importId;
    }

    public function uniqueFor(): int
    {
        return max(120, (int) config('legacy_odontogram.processing.process_timeout', 180) * 3);
    }

    /**
     * A permanently failed job must not leave the import stuck at PROCESSING:
     * an operator would otherwise see a spinner forever with no way to retry.
     */
    public function failed(?\Throwable $exception): void
    {
        app(LegacyOdontogramProcessingService::class)
            ->markFailedAfterExhaustedRetries($this->importId);
    }

    private function configuredQueue(): string
    {
        $queue = config('legacy_odontogram.processing.queue', 'legacy-odontogram-documents');

        return is_string($queue) && $queue !== '' ? $queue : 'legacy-odontogram-documents';
    }
}
