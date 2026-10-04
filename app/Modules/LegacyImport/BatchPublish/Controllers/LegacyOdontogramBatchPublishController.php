<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Controllers;

use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyOdontogramBatchPublishAdapter;

/**
 * Batch Publish Legacy Odontogram.
 *
 * Carries no behaviour of its own — it only fixes the archive this route group
 * publishes into, so nothing an operator can send is able to redirect a run
 * into the RME archive.
 */
class LegacyOdontogramBatchPublishController extends LegacyBatchPublishController
{
    protected function adapter(): LegacyBatchPublishAdapter
    {
        return app(LegacyOdontogramBatchPublishAdapter::class);
    }

    protected function routePrefix(): string
    {
        return 'settings.rme.legacy-publish-odontograms';
    }

    protected function heading(): string
    {
        return 'Batch Publish Legacy Odontogram';
    }
}
