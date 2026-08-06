<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Jobs;

use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Support\Queue\EnterpriseQueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * LEGACY-RME-PDF-1B — renders one staged legacy PDF into private page images.
 *
 * Extends the ENT-5 EnterpriseQueueJob so it inherits the approved retry /
 * backoff / timeout standard, then overrides the timeout to the PDF processing
 * envelope (rasterization is legitimately slower than a default job).
 *
 * Carries only the import ID — never a model, an uploaded file, or any bytes,
 * so a queued payload can never hold clinical content.
 *
 * ShouldBeUnique keeps two workers off the same import; the processing service
 * independently claims the row under a lock, so correctness never depends on
 * the cache lock alone.
 */
class ProcessLegacyRmePdfImport extends EnterpriseQueueJob implements ShouldBeUnique
{
    public function __construct(
        public readonly int $importId,
    ) {
        parent::__construct();

        $this->onQueue($this->configuredQueue());

        $this->tries = max(1, (int) config('legacy_rme.processing.tries', 3));
        $this->timeout = max(60, (int) config('legacy_rme.processing.process_timeout', 180) + 60);
        $this->backoff = (array) config('legacy_rme.processing.backoff', [30, 120, 300]);
    }

    public function handle(LegacyRmeImportProcessingService $processing): void
    {
        $processing->process($this->importId);
    }

    public function uniqueId(): string
    {
        return 'legacy-rme-import:'.$this->importId;
    }

    /**
     * Release the uniqueness lock even if the worker dies mid-render.
     */
    public function uniqueFor(): int
    {
        return max(120, (int) config('legacy_rme.processing.process_timeout', 180) * 3);
    }

    /**
     * The worker died before the service could record anything (hard crash,
     * OOM, exhausted retries). Leave the import in a truthful FAILED state the
     * operator can retry, rather than a silent PROCESSING that never resolves.
     */
    public function failed(?\Throwable $exception): void
    {
        app(LegacyRmeImportProcessingService::class)
            ->markFailedAfterExhaustedRetries($this->importId);
    }

    private function configuredQueue(): string
    {
        $queue = config('legacy_rme.processing.queue', 'legacy-rme-documents');

        return is_string($queue) && $queue !== '' ? $queue : 'legacy-rme-documents';
    }
}
