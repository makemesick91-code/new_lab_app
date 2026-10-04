<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Controllers;

use App\Modules\LegacyImport\BatchReview\Adapters\LegacyBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyRmeBatchReviewAdapter;

/**
 * Batch Review Legacy RME.
 *
 * Carries no behaviour of its own — it only fixes the clinical domain this
 * route group reviews, so nothing an operator can send is able to redirect a
 * session into the odontogram archive.
 */
class LegacyRmeBatchReviewController extends LegacyBatchReviewController
{
    protected function adapter(): LegacyBatchReviewAdapter
    {
        return app(LegacyRmeBatchReviewAdapter::class);
    }

    protected function routePrefix(): string
    {
        return 'settings.rme.legacy-review-imports';
    }

    protected function heading(): string
    {
        return 'Batch Review Legacy RME';
    }
}
