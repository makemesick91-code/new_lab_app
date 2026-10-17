<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Services\BranchTariffAuthorityService;
use App\Modules\Tariff\Services\TariffImportService;
use App\Modules\Tariff\Support\TariffProvenance;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Console\Command;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — print an import-ready CSV of every
 * active treatment for ONE branch, with the tariff currently in force shown for
 * reference and the `price` column left BLANK for the owner's official list
 * (D21h). Read-only. A blank price is skipped on import — never imported as Rp0.
 *
 * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — `current_tariff_provenance` says
 * whether the tariff in force carries an owner-approval reference (VERIFIED) or
 * not (UNVERIFIED, e.g. seeded or typed in master data), so the owner reviewing
 * the sheet sees which current prices were never approved. Informational: the
 * import reads only `treatment_code` and `price`.
 */
class RmeTariffTemplateCommand extends Command
{
    protected $signature = 'rme:tariff-template {--branch= : Canonical branch code, e.g. SPN4 (required)}';

    protected $description = 'Print an import-ready tariff CSV template for one branch (price column blank). Read-only.';

    public function handle(TariffRepositoryInterface $tariffs, BranchTariffAuthorityService $authority, ClinicalClock $clock): int
    {
        $code = BranchCodeAlias::canonicalize((string) $this->option('branch'));
        $branch = $code !== null ? Branch::query()->whereIn('code', BranchCodeAlias::equivalentCodes($code))->first() : null;

        if (! $branch instanceof Branch || ! $branch->is_active || ! $branch->is_rme_enabled) {
            $this->error('Sertakan --branch= dengan kode cabang RME aktif (mis. SPN4).');

            return self::FAILURE;
        }

        $treatments = $tariffs->activeTreatmentCodes();
        $inForce = $authority->resolveMany((int) $branch->id, array_keys($treatments), $clock->todayString());
        $references = $tariffs->approvalReferencesFor(array_values(array_filter(array_map(
            fn (int $id) => $inForce->for($id)->isResolved() ? $inForce->for($id)->tariffId : null,
            array_keys($treatments),
        ))));

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['treatment_code', 'current_tariff_in_force', 'current_tariff_provenance', 'price']);
        foreach ($treatments as $id => $treatmentCode) {
            $resolution = $inForce->for($id);
            $provenance = '';
            if ($resolution->isResolved()) {
                $provenance = ($references[(int) $resolution->tariffId] ?? null) !== null
                    ? TariffProvenance::VERIFIED
                    : TariffProvenance::UNVERIFIED;
            }
            fputcsv($out, [
                TariffImportService::csvSafeCode((string) $treatmentCode),
                $resolution->isResolved() ? number_format(((int) $resolution->priceCents) / 100, 2, '.', '') : '',
                $provenance,
                '',
            ]);
        }
        rewind($out);
        $this->getOutput()->write((string) stream_get_contents($out));
        fclose($out);

        return self::SUCCESS;
    }
}
