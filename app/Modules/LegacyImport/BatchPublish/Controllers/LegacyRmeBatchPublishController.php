<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Controllers;

use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyRmeBatchPublishAdapter;

/**
 * Batch Publish Legacy RME.
 *
 * Carries no behaviour of its own — it only fixes the archive this route group
 * publishes into, so nothing an operator can send is able to redirect a run
 * into the odontogram archive.
 */
class LegacyRmeBatchPublishController extends LegacyBatchPublishController
{
    protected function adapter(): LegacyBatchPublishAdapter
    {
        return app(LegacyRmeBatchPublishAdapter::class);
    }

    protected function routePrefix(): string
    {
        return 'settings.rme.legacy-publish-imports';
    }

    protected function heading(): string
    {
        return 'Batch Publish Legacy RME';
    }
}
