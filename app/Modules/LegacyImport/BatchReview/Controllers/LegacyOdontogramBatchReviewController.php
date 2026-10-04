<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Controllers;

use App\Modules\LegacyImport\BatchReview\Adapters\LegacyBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyOdontogramBatchReviewAdapter;

/**
 * Batch Review Legacy Odontogram.
 *
 * Carries no behaviour of its own — it only fixes the clinical domain this
 * route group reviews, so nothing an operator can send is able to redirect a
 * session into the RME archive.
 */
class LegacyOdontogramBatchReviewController extends LegacyBatchReviewController
{
    protected function adapter(): LegacyBatchReviewAdapter
    {
        return app(LegacyOdontogramBatchReviewAdapter::class);
    }

    protected function routePrefix(): string
    {
        return 'settings.rme.legacy-review-odontograms';
    }

    protected function heading(): string
    {
        return 'Batch Review Legacy Odontogram';
    }
}
