<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Controllers;

use App\Modules\LegacyImport\MassUpload\Adapters\LegacyMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyRmeMassUploadAdapter;
use App\Modules\LegacyImport\Support\LegacyImportType;

/**
 * Mass Upload Legacy RME.
 *
 * Carries no behaviour of its own — it only fixes the clinical domain this
 * route group writes to, so nothing an operator can send is able to redirect a
 * batch into the odontogram archive.
 */
class LegacyRmeMassUploadController extends LegacyMassUploadController
{
    protected function importType(): string
    {
        return LegacyImportType::LEGACY_RME;
    }

    protected function adapter(): LegacyMassUploadAdapter
    {
        return app(LegacyRmeMassUploadAdapter::class);
    }

    protected function routePrefix(): string
    {
        return 'settings.rme.legacy-mass-imports';
    }

    protected function viewPrefix(): string
    {
        return 'settings.rme.legacy-mass-imports';
    }

    protected function heading(): string
    {
        return 'Mass Upload Legacy RME';
    }
}
