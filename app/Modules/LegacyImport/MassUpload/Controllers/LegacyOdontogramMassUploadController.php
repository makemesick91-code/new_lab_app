<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Controllers;

use App\Modules\LegacyImport\MassUpload\Adapters\LegacyMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyOdontogramMassUploadAdapter;
use App\Modules\LegacyImport\Support\LegacyImportType;

/**
 * Mass Upload Legacy Odontogram.
 *
 * Same shape as its RME sibling, pointed at the odontogram adapter — which is
 * the one carrying this sprint's added manifest-RM binding gate.
 */
class LegacyOdontogramMassUploadController extends LegacyMassUploadController
{
    protected function importType(): string
    {
        return LegacyImportType::LEGACY_ODONTOGRAM;
    }

    protected function adapter(): LegacyMassUploadAdapter
    {
        return app(LegacyOdontogramMassUploadAdapter::class);
    }

    protected function routePrefix(): string
    {
        return 'settings.rme.legacy-mass-odontograms';
    }

    protected function viewPrefix(): string
    {
        return 'settings.rme.legacy-mass-odontograms';
    }

    protected function heading(): string
    {
        return 'Mass Upload Legacy Odontogram';
    }
}
